-- TaskEsc v2.3 / schema 5
-- Financeiro baseado na regra comercial atual e upload grande em blocos.
-- A aplicação executa a mesma atualização de forma idempotente por app/Migrations.php.

-- Compatível com instalações que já possuam source_key.
SET @has_source_key := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'source_key'
);
SET @sql := IF(@has_source_key = 0,
  'ALTER TABLE invoices ADD COLUMN source_key VARCHAR(120) NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_source_key_index := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'source_key' AND NON_UNIQUE = 0
);
SET @sql := IF(@has_source_key_index = 0,
  'CREATE UNIQUE INDEX ux_invoices_source_key ON invoices (source_key)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO settings (`key`,value) VALUES ('upload_mb','0')
ON DUPLICATE KEY UPDATE value='0';

-- Contratos antigos ficam preservados para histórico, mas não criam uma segunda mensalidade.
UPDATE contracts ct
JOIN clients c ON c.id=ct.client_id
SET ct.auto_bill=0
WHERE c.commercial_type='monthly' AND ct.status='active';

-- Apenas uma cobrança antiga por task recebe a chave automática; parcelas/histórico continuam intactos.
UPDATE invoices i
JOIN (
  SELECT demand_id, MIN(id) AS invoice_id
  FROM invoices
  WHERE demand_id IS NOT NULL AND status<>'cancelled'
  GROUP BY demand_id
) x ON x.invoice_id=i.id
SET i.source_key=CONCAT('task:',i.demand_id)
WHERE i.source_key IS NULL;

INSERT INTO settings (`key`,value) VALUES ('schema_version','5')
ON DUPLICATE KEY UPDATE value='5';
