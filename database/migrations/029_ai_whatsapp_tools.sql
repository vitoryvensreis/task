-- TaskEsc AI step 3. Existing inbox rows remain ignored, never replayed.
ALTER TABLE ai_whatsapp_inbox
 ADD COLUMN ai_state VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ignored',
 ADD COLUMN ai_mentions_json TEXT NULL,
 ADD COLUMN ai_reply TEXT NULL,
 ADD COLUMN ai_error VARCHAR(160) NULL,
 ADD COLUMN ai_attempts INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN ai_available_at DATETIME(6) NULL,
 ADD COLUMN ai_updated_at DATETIME(6) NULL,
 ADD COLUMN ai_processed_at DATETIME(6) NULL,
 ADD COLUMN ai_sent_at DATETIME(6) NULL,
 ADD KEY idx_ai_inbox_work (ai_state,ai_available_at,id);
