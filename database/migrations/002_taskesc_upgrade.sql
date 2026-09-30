-- TaskEsc v2 - referência de atualização para instalações existentes.
-- O sistema executa esta evolução automaticamente por app/Migrations.php.
ALTER TABLE demands ADD COLUMN deleted_at DATETIME NULL AFTER updated_at;
ALTER TABLE demands ADD COLUMN drive_folder_id VARCHAR(255) NULL AFTER deleted_at;
ALTER TABLE files ADD COLUMN storage_provider VARCHAR(20) NOT NULL DEFAULT 'local' AFTER disk_name;
ALTER TABLE files ADD COLUMN drive_file_id VARCHAR(255) NULL AFTER storage_provider;
ALTER TABLE files ADD COLUMN drive_account_id INT NULL AFTER drive_file_id;
ALTER TABLE files ADD COLUMN drive_web_view_url TEXT NULL AFTER drive_account_id;
ALTER TABLE google_accounts ADD COLUMN scopes TEXT NULL AFTER refresh_token;
INSERT INTO settings (`key`,value) VALUES ('drive_account_id','') ON DUPLICATE KEY UPDATE value=value;
INSERT INTO settings (`key`,value) VALUES ('drive_root_folder_id','') ON DUPLICATE KEY UPDATE value=value;
INSERT INTO settings (`key`,value) VALUES ('schema_version','2') ON DUPLICATE KEY UPDATE value='2';

-- Converte apenas as cores padrão antigas do Kanban para a identidade monocromática TaskEsc.
UPDATE stages SET color=CASE color WHEN '#7c8a9e' THEN '#666666' WHEN '#8b5cf6' THEN '#777777' WHEN '#f59e0b' THEN '#8a8a8a' WHEN '#06b6d4' THEN '#9a9a9a' WHEN '#4263eb' THEN '#b8b8b8' WHEN '#ec4899' THEN '#d1d1d1' WHEN '#f97316' THEN '#a7a7a7' WHEN '#16a34a' THEN '#f0f0f0' WHEN '#94a3b8' THEN '#5a5a5a' WHEN '#64748b' THEN '#444444' ELSE color END WHERE color IN ('#7c8a9e','#8b5cf6','#f59e0b','#06b6d4','#4263eb','#ec4899','#f97316','#16a34a','#94a3b8','#64748b');
