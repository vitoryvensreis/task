-- TaskEsc schema 14 — controle de custos pagos e pendentes
ALTER TABLE expenses
  ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER spent_on,
  ADD COLUMN paid_on DATE NULL AFTER payment_status,
  ADD INDEX idx_expenses_payment (payment_status, paid_on, spent_on);

ALTER TABLE costs
  ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'paid' AFTER spent_on,
  ADD COLUMN paid_on DATE NULL AFTER payment_status,
  ADD INDEX idx_costs_payment (payment_status, paid_on, spent_on);

UPDATE expenses SET payment_status='paid', paid_on=spent_on WHERE paid_on IS NULL;
UPDATE costs SET payment_status='paid', paid_on=spent_on WHERE paid_on IS NULL;

ALTER TABLE expenses MODIFY payment_status VARCHAR(20) NOT NULL DEFAULT 'pending';
ALTER TABLE costs MODIFY payment_status VARCHAR(20) NOT NULL DEFAULT 'paid';

INSERT INTO settings (`key`, value)
VALUES ('schema_version', '14')
ON DUPLICATE KEY UPDATE value='14';
