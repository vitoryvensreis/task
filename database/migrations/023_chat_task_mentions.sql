-- Schema 23: referências de tasks dentro de mensagens do chat administrativo.
-- Schema 20 continua reservado ao SaaS removido.
CREATE TABLE IF NOT EXISTS chat_message_tasks (
 message_id BIGINT UNSIGNED NOT NULL,
 demand_id INT NOT NULL,
 label VARCHAR(255) NOT NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY (message_id,demand_id),
 KEY idx_chat_message_tasks_demand (demand_id,message_id),
 CONSTRAINT fk_chat_message_tasks_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
 CONSTRAINT fk_chat_message_tasks_demand FOREIGN KEY (demand_id) REFERENCES demands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
