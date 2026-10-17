-- B1.2c-2b.2a: staging-only outbox. Never consumed by legacy workers.
-- Independent of the retired SaaS schema 20; requires B1.1+B1.2a+B1.2b.
CREATE TABLE IF NOT EXISTS saas_event_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_account_id BIGINT UNSIGNED NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 payload_json JSON NOT NULL,
 created_by INT NULL,
 created_at DATETIME(6) NOT NULL,
 available_at DATETIME(6) NOT NULL,
 published_at DATETIME(6) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(190) NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_saas_outbox_account_key (tenant_account_id,event_key),
 KEY idx_saas_outbox_pending (tenant_account_id,published_at,available_at,id),
 KEY idx_saas_outbox_entity (tenant_account_id,entity_type,entity_id,id),
 CONSTRAINT fk_saas_outbox_account FOREIGN KEY (tenant_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_saas_outbox_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
