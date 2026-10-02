-- TaskEsc schema 16 — log de entregas Web Push
CREATE TABLE IF NOT EXISTS push_delivery_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  subscription_id BIGINT NULL,
  notification_id BIGINT NULL,
  demand_id INT NULL,
  scope VARCHAR(40) NOT NULL DEFAULT 'task_responsible',
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  url TEXT NULL,
  status VARCHAR(30) NOT NULL,
  http_code INT NULL,
  error TEXT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_push_delivery_created (created_at),
  KEY idx_push_delivery_user (user_id,created_at),
  KEY idx_push_delivery_status (status,created_at),
  KEY idx_push_delivery_demand (demand_id,created_at),
  CONSTRAINT fk_push_delivery_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_push_delivery_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id),
  CONSTRAINT fk_push_delivery_notification FOREIGN KEY (notification_id) REFERENCES notifications(id),
  CONSTRAINT fk_push_delivery_demand FOREIGN KEY (demand_id) REFERENCES demands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO settings (`key`, value)
VALUES ('schema_version', '16')
ON DUPLICATE KEY UPDATE value='16';
