<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Converts a title to readble IDs
function slugify(string $title): string {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    if ($ascii === false) {
        $ascii = $title;
    }
    $slug = strtolower($ascii);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        return 'doc';
    }
    if (strlen($slug) > 40) {
        $slug = substr($slug, 0, 40);
        $lastDash = strrpos($slug, '-');
        if ($lastDash !== false && $lastDash >= 20) {
            $slug = substr($slug, 0, $lastDash);
        }
        $slug = rtrim($slug, '-');
        if ($slug === '') {
            return 'doc';
        }
    }
    return $slug;
}

// Short readable suffix used to reduce ID collisions.
function readable_id_suffix(int $length = 4): string {
    $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
    $alphabetLen = strlen($alphabet);
    $bytes = random_bytes($length);
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[ord($bytes[$i]) % $alphabetLen];
    }
    return $out;
}

// Generates a unique readable ID by retrying if a collision happens.
function generate_readable_id(string $title, int $maxAttempts = 8): string {
    $slug = slugify($title);
    $stmt = db()->prepare('SELECT 1 FROM documents WHERE readable_id = ? LIMIT 1');
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = $slug . '-' . readable_id_suffix(4);
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
    }
    throw new RuntimeException("Could not generate unique readable_id for \"{$title}\" after {$maxAttempts} attempts.");
}