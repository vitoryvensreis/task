-- TaskEsc schema 17 — descontos em pagamentos
ALTER TABLE payments
  ADD COLUMN discount_cents BIGINT NOT NULL DEFAULT 0 AFTER amount_cents,
  ADD COLUMN discount_type VARCHAR(10) NULL AFTER discount_cents,
  ADD COLUMN discount_percent DECIMAL(7,4) NULL AFTER discount_type;

ALTER TABLE payments
  ADD INDEX idx_payments_invoice_settlement (invoice_id,paid_on);

INSERT INTO settings (`key`, value)
VALUES ('schema_version','17')
ON DUPLICATE KEY UPDATE value='17';
