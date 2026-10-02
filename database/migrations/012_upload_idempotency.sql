-- TaskEsc schema 12 — idempotência de uploads
ALTER TABLE files
  ADD COLUMN upload_key CHAR(64) NULL AFTER size_bytes,
  ADD UNIQUE KEY uq_files_upload_key (upload_key);

INSERT INTO settings (`key`, value)
VALUES ('schema_version', '12')
ON DUPLICATE KEY UPDATE value='12';
