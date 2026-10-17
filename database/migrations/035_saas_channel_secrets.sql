-- B1.2c-2b.2b.2b.2a: tenant-bound encrypted provider credentials, disabled by design.
-- DO NOT enable any delivery worker/provider during this migration.
CREATE TABLE IF NOT EXISTS saas_channel_secrets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_account_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 secret_envelope LONGTEXT NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'disabled',
 updated_by INT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_saas_channel_owner (tenant_account_id,channel),
 KEY idx_saas_channel_account (tenant_account_id,status,id),
 CONSTRAINT fk_saas_channel_account FOREIGN KEY (tenant_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_saas_channel_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
