-- SaaS B1.2c-2b.2b.2a: tenant-bound delivery intents, no sending or real provider connectors.
CREATE TABLE IF NOT EXISTS saas_delivery_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_account_id BIGINT UNSIGNED NOT NULL,
 receipt_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 destination_scope VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 last_error VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_saas_delivery_receipt_channel (tenant_account_id,receipt_id,channel),
 KEY idx_saas_delivery_due (tenant_account_id,status,available_at,id),
 KEY idx_saas_delivery_account (tenant_account_id,id),
 CONSTRAINT fk_saas_delivery_account FOREIGN KEY(tenant_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_saas_delivery_receipt FOREIGN KEY(receipt_id) REFERENCES saas_event_receipts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
