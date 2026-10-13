-- TaskEsc schema 26: WhatsApp manual por task.
-- Execute somente após backup validado. Idempotência da migração é controlada em Migrations::automation().
ALTER TABLE demands ADD COLUMN whatsapp_custom_phone VARCHAR(20) NULL AFTER whatsapp_recipient_mode;
