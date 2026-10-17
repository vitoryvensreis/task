-- B1.2c-2b.2b.1: receipts for verified SaaS event preparation only.
-- No external delivery and no legacy worker integration.
CREATE TABLE IF NOT EXISTS saas_event_receipts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_account_id BIGINT UNSIGNED NOT NULL,
 outbox_id BIGINT UNSIGNED NOT NULL,
 event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'held',
 prepared_at DATETIME(6) NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_saas_receipt_event (tenant_account_id,outbox_id),
 KEY idx_saas_receipts_account (tenant_account_id,status,id),
 CONSTRAINT fk_saas_receipts_account FOREIGN KEY(tenant_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_saas_receipts_outbox FOREIGN KEY(outbox_id) REFERENCES saas_event_outbox(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
