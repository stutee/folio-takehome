<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('document with null published_at is immediately available', function () {
    $stmt = db()->prepare('SELECT published_at FROM documents WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected seeded document');
    assert_true($row['published_at'] === null, 'expected published_at NULL on seeded doc, got: ' . var_export($row['published_at'], true));

    $now = gmdate('Y-m-d H:i:s');
    $blocked = !empty($row['published_at']) && $row['published_at'] > $now;
    assert_true(!$blocked, 'gate should not block a doc with NULL published_at');
});

test('document scheduled in the future is blocked from view', function () {
    $pdo = db();
    $pdo->prepare("
        INSERT INTO documents (title, body, created_by, published_at)
        VALUES (?, ?, 1, datetime('now', '+1 day'))
    ")->execute(['Future Doc', 'secret body']);
    $docId = (int) $pdo->lastInsertId();

    $token = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)')
        ->execute([$docId, $token, 'r@example.com']);

    $stmt = $pdo->prepare('
        SELECT d.published_at
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    assert_true($row !== false, 'expected the share to resolve');
    $now = gmdate('Y-m-d H:i:s');
    $blocked = !empty($row['published_at']) && $row['published_at'] > $now;
    assert_true($blocked, 'gate should block a future-scheduled doc');
});

test('document scheduled in the past is available', function () {
    $pdo = db();
    $pdo->prepare("
        INSERT INTO documents (title, body, created_by, published_at)
        VALUES (?, ?, 1, datetime('now', '-1 hour'))
    ")->execute(['Past Doc', 'body']);
    $docId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT published_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();

    $now = gmdate('Y-m-d H:i:s');
    $blocked = !empty($row['published_at']) && $row['published_at'] > $now;
    assert_true(!$blocked, 'gate should not block a past-scheduled doc');
});

test('title search matches by substring and excludes non-matches', function () {
    $pdo = db();
    $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)')
        ->execute(['Onboarding Guide', 'body']);
    $pdo->prepare('INSERT INTO documents (title, body, created_by) VALUES (?, ?, 1)')
        ->execute(['Welcome Letter', 'body']);

    $q = 'welcome';
    $likeQ = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $stmt = $pdo->prepare("
        SELECT title FROM documents
        WHERE title LIKE ? ESCAPE '\\'
        ORDER BY id ASC
    ");
    $stmt->execute([$likeQ]);
    $rows = $stmt->fetchAll();

    $titles = array_column($rows, 'title');
    assert_true(count($titles) === 2, 'expected 2 matches for "welcome", got: ' . var_export($titles, true));
    assert_true(in_array('Welcome Packet', $titles, true), 'expected seeded "Welcome Packet" to match');
    assert_true(in_array('Welcome Letter', $titles, true), 'expected "Welcome Letter" to match');
    assert_true(!in_array('Onboarding Guide', $titles, true), 'expected "Onboarding Guide" to be excluded');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
