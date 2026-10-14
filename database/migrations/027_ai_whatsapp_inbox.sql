-- TaskEsc AI Passo 1: inbox de mensagens recebidas (schema 27).
-- Não altera nem recria tabelas de automações, chat ou SaaS removido.
CREATE TABLE IF NOT EXISTS ai_whatsapp_inbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 session_name VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 chat_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 chat_type VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NULL,
 status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'received',
 received_at DATETIME(6) NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_ai_inbox_provider (session_name,provider_message_id),
 KEY idx_ai_inbox_pending (status,received_at,id),
 KEY idx_ai_inbox_chat (session_name,chat_id,id),
 KEY idx_ai_inbox_sender (sender_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
