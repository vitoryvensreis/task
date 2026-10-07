-- Schema 22: chat direto entre usuários sobre a infraestrutura realtime existente.
-- Schema 20 continua reservado ao SaaS removido.
CREATE TABLE IF NOT EXISTS chat_threads (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 user_low_id INT NOT NULL,
 user_high_id INT NOT NULL,
 last_message_id BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_chat_thread_pair (user_low_id,user_high_id),
 KEY idx_chat_thread_low_updated (user_low_id,updated_at,id),
 KEY idx_chat_thread_high_updated (user_high_id,updated_at,id),
 CONSTRAINT fk_chat_thread_low FOREIGN KEY (user_low_id) REFERENCES users(id),
 CONSTRAINT fk_chat_thread_high FOREIGN KEY (user_high_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 thread_id BIGINT UNSIGNED NOT NULL,
 sender_id INT NOT NULL,
 recipient_id INT NOT NULL,
 client_nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 read_at DATETIME(6) NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_chat_sender_nonce (sender_id,client_nonce),
 KEY idx_chat_thread_messages (thread_id,id),
 KEY idx_chat_recipient_unread (recipient_id,read_at,id),
 KEY idx_chat_sender_recipient (sender_id,recipient_id,id),
 CONSTRAINT fk_chat_message_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id),
 CONSTRAINT fk_chat_message_sender FOREIGN KEY (sender_id) REFERENCES users(id),
 CONSTRAINT fk_chat_message_recipient FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
