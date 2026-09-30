-- TaskEsc v2.2
-- Execute somente se a atualizacao automatica nao estiver disponivel.
ALTER TABLE clients ADD COLUMN monthly_payment_day TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER monthly_amount_cents;
UPDATE clients SET monthly_payment_day=10 WHERE monthly_payment_day<1 OR monthly_payment_day>31 OR monthly_payment_day IS NULL;
INSERT INTO settings (`key`,value) VALUES ('schema_version','4')
ON DUPLICATE KEY UPDATE value='4';
