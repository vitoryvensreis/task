-- TaskEsc AI Passo 2: identidade e autorizacao (schema 28).
-- Valores conservadores: sem permissao para contas nao-admin e mensagens historicas.
ALTER TABLE users ADD COLUMN ai_assistant_enabled TINYINT NOT NULL DEFAULT 0 AFTER whatsapp_phone;
ALTER TABLE ai_whatsapp_inbox
  ADD COLUMN auth_user_id INT NULL AFTER sender_id,
  ADD COLUMN auth_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' AFTER status,
  ADD COLUMN auth_reason VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending' AFTER auth_status,
  ADD COLUMN auth_checked_at DATETIME(6) NULL AFTER auth_reason,
  ADD KEY idx_ai_inbox_auth (auth_status,received_at,id),
  ADD KEY idx_ai_inbox_auth_user (auth_user_id,id);
