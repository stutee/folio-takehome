-- Adds optional scheduled publish time to documents.
-- Stored as UTC 'YYYY-MM-DD HH:MM:SS' 
ALTER TABLE documents ADD COLUMN published_at TEXT;