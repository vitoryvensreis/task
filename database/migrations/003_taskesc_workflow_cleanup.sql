-- TaskEsc v2.1
-- Simplificação do fluxo de tasks, cadastro comercial de clientes e prazo mínimo.
-- A aplicação executa esta atualização automaticamente por app/Migrations.php.

ALTER TABLE clients ADD COLUMN commercial_type VARCHAR(20) NOT NULL DEFAULT 'one_off' AFTER brand;
ALTER TABLE clients ADD COLUMN monthly_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER commercial_type;

UPDATE clients
SET commercial_type='one_off'
WHERE commercial_type IS NULL OR commercial_type NOT IN ('one_off','monthly');

-- Se já existia contrato recorrente ativo, preserva a semântica como cliente mensal.
UPDATE clients c
SET commercial_type='monthly',
    monthly_amount_cents=COALESCE((
      SELECT ct.amount_cents
      FROM contracts ct
      WHERE ct.client_id=c.id AND ct.status='active'
      ORDER BY ct.id DESC
      LIMIT 1
    ),0)
WHERE EXISTS (
  SELECT 1 FROM contracts ct2
  WHERE ct2.client_id=c.id AND ct2.status='active'
);

-- Os antigos campos específicos por categoria deixam de ser exibidos no novo formulário.
UPDATE categories SET fields_json='[]';

-- Preserva o prazo anterior antes de remover requested_due.
UPDATE demands
SET due_at=COALESCE(due_at,CONCAT(requested_due,' 18:00:00'),DATE_ADD(created_at,INTERVAL 1 DAY),UTC_TIMESTAMP());

-- Consolida valor antigo + extra - desconto antes de remover as colunas legadas.
UPDATE demands
SET amount_cents=CASE
      WHEN billing_mode='included' THEN 0
      ELSE GREATEST(0,COALESCE(amount_cents,0)+COALESCE(extra_cents,0)-COALESCE(discount_cents,0))
    END;

UPDATE demands
SET billing_mode=CASE WHEN billing_mode='included' THEN 'included' ELSE 'one_off' END,
    amount_cents=COALESCE(amount_cents,0);

ALTER TABLE demands DROP COLUMN requested_due;
ALTER TABLE demands DROP COLUMN requested_priority;
ALTER TABLE demands DROP COLUMN original_due_at;
ALTER TABLE demands DROP COLUMN extra_cents;
ALTER TABLE demands DROP COLUMN discount_cents;

-- contract_id pode ter nome de FK diferente conforme a instalação.
SET @taskesc_fk_contract := (
  SELECT CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='demands'
    AND COLUMN_NAME='contract_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1
);
SET @taskesc_sql := IF(
  @taskesc_fk_contract IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE demands DROP FOREIGN KEY `',REPLACE(@taskesc_fk_contract,'`','``'),'`')
);
PREPARE taskesc_stmt FROM @taskesc_sql;
EXECUTE taskesc_stmt;
DEALLOCATE PREPARE taskesc_stmt;
ALTER TABLE demands DROP COLUMN contract_id;
ALTER TABLE demands MODIFY due_at DATETIME NOT NULL;

INSERT INTO settings (`key`,value) VALUES ('min_delivery_business_hours','24')
ON DUPLICATE KEY UPDATE value=value;
INSERT INTO settings (`key`,value) VALUES ('schema_version','3')
ON DUPLICATE KEY UPDATE value='3';
