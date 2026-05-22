-- Human-readable identifier for documents. 
ALTER TABLE documents ADD COLUMN readable_id TEXT;
CREATE UNIQUE INDEX idx_documents_readable_id
    ON documents(readable_id)
    WHERE readable_id IS NOT NULL;