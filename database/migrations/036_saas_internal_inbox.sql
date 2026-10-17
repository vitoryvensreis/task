-- B1.2c-2b.2b.2b.2b.1: tenant-bound internal inbox, never any external delivery.
CREATE TABLE IF NOT EXISTS saas_internal_inbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tenant_account_id BIGINT UNSIGNED NOT NULL,
 delivery_job_id BIGINT UNSIGNED NOT NULL,
 recipient_user_id INT NOT NULL,
 event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_saas_inbox_delivery_user (tenant_account_id,delivery_job_id,recipient_user_id),
 KEY idx_saas_inbox_user (tenant_account_id,recipient_user_id,id),
 KEY idx_saas_inbox_entity (tenant_account_id,entity_type,entity_id),
 CONSTRAINT fk_saas_inbox_account FOREIGN KEY (tenant_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_saas_inbox_job FOREIGN KEY (delivery_job_id) REFERENCES saas_delivery_jobs(id) ON DELETE RESTRICT,
 CONSTRAINT fk_saas_inbox_user FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
