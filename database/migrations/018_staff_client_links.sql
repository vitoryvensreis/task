-- TaskEsc schema 18
-- Vínculos da Equipe passam a definir o escopo de acesso aos clientes.
-- Preserva o acesso dos responsáveis já existentes vinculando-os automaticamente aos clientes de suas tasks.

INSERT IGNORE INTO user_clients (user_id,client_id,can_approve,can_finance)
SELECT DISTINCT d.assigned_to,d.client_id,1,0
FROM demands d
JOIN users u ON u.id=d.assigned_to
WHERE d.assigned_to IS NOT NULL
  AND d.deleted_at IS NULL
  AND u.role='staff';

INSERT INTO settings (`key`,value)
VALUES ('schema_version','18')
ON DUPLICATE KEY UPDATE value='18';
