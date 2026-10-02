-- TaskEsc schema 15 — categorias de custo gerenciáveis
CREATE TABLE IF NOT EXISTS expense_categories (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_expense_categories_name (name),
  KEY idx_expense_categories_active (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO expense_categories (name,active,created_at,updated_at) VALUES
('Água',1,NOW(),NOW()),
('Energia / Luz',1,NOW(),NOW()),
('Internet / Telefonia',1,NOW(),NOW()),
('Aluguel',1,NOW(),NOW()),
('Software',1,NOW(),NOW()),
('Armazenamento / Cloud',1,NOW(),NOW()),
('Assinaturas',1,NOW(),NOW()),
('Marketing',1,NOW(),NOW()),
('Transporte',1,NOW(),NOW()),
('Equipamentos',1,NOW(),NOW()),
('Impostos',1,NOW(),NOW()),
('Serviços',1,NOW(),NOW()),
('Alimentação',1,NOW(),NOW()),
('Custo Pessoal',1,NOW(),NOW()),
('Outros',1,NOW(),NOW());

INSERT IGNORE INTO expense_categories (name,active,created_at,updated_at)
SELECT DISTINCT TRIM(category),1,NOW(),NOW() FROM expenses WHERE TRIM(category)<>'';

INSERT IGNORE INTO expense_categories (name,active,created_at,updated_at)
SELECT DISTINCT TRIM(category),1,NOW(),NOW() FROM expense_rules WHERE TRIM(category)<>'';

INSERT INTO settings (`key`, value)
VALUES ('schema_version', '15')
ON DUPLICATE KEY UPDATE value='15';
