-- Execute only after a verified full database backup, with the automation advisory lock.
-- Widen the destination to hold native WhatsApp group IDs (e.g. 120363123456789@g.us).
-- Phone numbers, rows, indexes and relationships are preserved.
ALTER TABLE whatsapp_messages MODIFY COLUMN recipient_phone VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
