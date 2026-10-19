CREATE TABLE IF NOT EXISTS platform_asaas_subscriptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 workspace_id BIGINT UNSIGNED NOT NULL,
 plan_id BIGINT UNSIGNED NOT NULL,
 environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 asaas_customer_id VARCHAR(90) CHARACTER SET ascii COLLATE ascii_bin NULL,
 asaas_subscription_id VARCHAR(90) CHARACTER SET ascii COLLATE ascii_bin NULL,
 payer_name VARCHAR(190) NOT NULL,
 payer_email VARCHAR(190) NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 cycle VARCHAR(24) NOT NULL,
 due_date DATE NOT NULL,
 request_status VARCHAR(24) NOT NULL DEFAULT 'creating',
 billing_status VARCHAR(24) NOT NULL DEFAULT 'awaiting_payment',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_asaas_workspace_env (workspace_id,environment),
 UNIQUE KEY uq_asaas_subscription (asaas_subscription_id),
 CONSTRAINT fk_asaas_sub_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
 CONSTRAINT fk_asaas_sub_plan FOREIGN KEY (plan_id) REFERENCES platform_access_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS platform_asaas_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 asaas_payment_id VARCHAR(90) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(40) NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 due_date DATE NULL,
 invoice_url VARCHAR(1024) NULL,
 paid_at DATETIME NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_asaas_payment (environment,asaas_payment_id),
 KEY idx_asaas_payment_sub (subscription_id,updated_at),
 CONSTRAINT fk_asaas_payment_sub FOREIGN KEY (subscription_id) REFERENCES platform_asaas_subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS platform_asaas_webhook_events (
 environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(90) NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(environment,event_id),
 KEY idx_asaas_event_sub (subscription_id,created_at),
 CONSTRAINT fk_asaas_event_sub FOREIGN KEY (subscription_id) REFERENCES platform_asaas_subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
