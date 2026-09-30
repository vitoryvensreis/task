-- TaskEsc v2.5 / schema 7
-- Drive dedicado por cliente e separação entre conexões de Drive e Calendar.

ALTER TABLE clients
  ADD COLUMN drive_account_id INT NULL AFTER monthly_payment_day,
  ADD COLUMN drive_folder_id VARCHAR(255) NULL AFTER drive_account_id,
  ADD INDEX idx_clients_drive_account (drive_account_id);

ALTER TABLE google_accounts
  ADD COLUMN calendar_enabled TINYINT NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN drive_enabled TINYINT NOT NULL DEFAULT 0 AFTER calendar_enabled;

UPDATE google_accounts ga
SET calendar_enabled=1
WHERE EXISTS (SELECT 1 FROM calendars c WHERE c.account_id=ga.id);

UPDATE google_accounts
SET drive_enabled=1
WHERE scopes LIKE '%https://www.googleapis.com/auth/drive.file%';

INSERT INTO settings (`key`,value) VALUES ('schema_version','7')
ON DUPLICATE KEY UPDATE value='7';
