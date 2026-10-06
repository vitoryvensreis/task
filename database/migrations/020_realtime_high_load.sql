-- TaskEsc schema 20 — realtime/outbox/high-load foundation
CREATE TABLE IF NOT EXISTS event_outbox (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id BIGINT NOT NULL DEFAULT 0,
  action VARCHAR(100) NOT NULL,
  client_id INT NULL,
  actor_id INT NULL,
  channels_json TEXT NOT NULL,
  payload_json LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  locked_by VARCHAR(190) NULL,
  published_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_outbox_dispatch (status,available_at,id),
  KEY idx_outbox_lock (status,locked_at),
  KEY idx_outbox_entity (entity,entity_id,id),
  KEY idx_outbox_client (client_id,id),
  KEY idx_outbox_published (status,published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE demands ADD INDEX idx_demands_kanban_rt (client_id,deleted_at,stage_id,sort_order,updated_at,id);
ALTER TABLE files ADD INDEX idx_files_demand_rt (demand_id,id);
ALTER TABLE comments ADD INDEX idx_comments_demand_rt (demand_id,id);
ALTER TABLE notifications ADD INDEX idx_notifications_realtime (user_id,id);

INSERT INTO settings (`key`,value) VALUES ('schema_version','20')
ON DUPLICATE KEY UPDATE value='20';
