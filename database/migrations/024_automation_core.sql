CREATE TABLE IF NOT EXISTS automations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(190) NOT NULL,
 trigger_event VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 definition_json JSON NOT NULL,
 active TINYINT NOT NULL DEFAULT 0,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by INT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(id),
 KEY idx_automations_active_trigger (active,trigger_event,id),
 CONSTRAINT fk_automations_created_by FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS automation_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 automation_id BIGINT UNSIGNED NOT NULL,
 operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 trigger_event VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
 context_json JSON NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME(6) NOT NULL,
 started_at DATETIME(6) NULL,
 finished_at DATETIME(6) NULL,
 last_error VARCHAR(190) NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_automation_run_operation (automation_id,operation_id),
 KEY idx_automation_runs_ready (status,available_at,id),
 KEY idx_automation_runs_entity (entity_type,entity_id,id),
 CONSTRAINT fk_automation_runs_automation FOREIGN KEY(automation_id) REFERENCES automations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS automation_run_steps (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 run_id BIGINT UNSIGNED NOT NULL,
 node_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 node_type VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 input_json JSON NOT NULL,
 output_json JSON NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 1,
 started_at DATETIME(6) NOT NULL,
 finished_at DATETIME(6) NULL,
 last_error VARCHAR(190) NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_automation_run_step (run_id,node_id),
 KEY idx_automation_steps_run (run_id,id),
 CONSTRAINT fk_automation_steps_run FOREIGN KEY(run_id) REFERENCES automation_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS automation_worker_state (
 id TINYINT UNSIGNED NOT NULL,
 last_outbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 heartbeat_at DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 run_id BIGINT UNSIGNED NULL,
 demand_id INT NULL,
 node_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 recipient_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 recipient_user_id INT NULL,
 recipient_client_id INT NULL,
 recipient_phone VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NOT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
 provider_message_id VARCHAR(255) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(190) NULL,
 created_at DATETIME(6) NOT NULL,
 sent_at DATETIME(6) NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_whatsapp_run_node (run_id,node_id),
 KEY idx_whatsapp_status_created (status,created_at,id),
 KEY idx_whatsapp_demand (demand_id,id),
 CONSTRAINT fk_whatsapp_run FOREIGN KEY(run_id) REFERENCES automation_runs(id) ON DELETE SET NULL,
 CONSTRAINT fk_whatsapp_demand FOREIGN KEY(demand_id) REFERENCES demands(id) ON DELETE SET NULL,
 CONSTRAINT fk_whatsapp_user FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_whatsapp_client FOREIGN KEY(recipient_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
