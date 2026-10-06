-- Schema 21: Outbox transacional. Schema 20 permanece reservado ao SaaS removido.
-- DDL idempotente. Migrations::outbox() valida a estrutura antes de avançar o marcador.
-- Não executar DDL dentro de uma transação de negócio (MySQL faz commit implícito).
CREATE TABLE IF NOT EXISTS event_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json JSON NOT NULL,
 created_by INT NULL,
 created_at DATETIME(6) NOT NULL,
 available_at DATETIME(6) NOT NULL,
 published_at DATETIME(6) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_event_outbox_event_key (event_key),
 KEY idx_event_outbox_ready (published_at,available_at,id),
 KEY idx_event_outbox_entity (entity_type,entity_id,id),
 KEY idx_event_outbox_published (published_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
