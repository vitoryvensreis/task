-- TaskEsc schema 20 — SaaS foundation (somente aditivo)
CREATE TABLE IF NOT EXISTS workspaces (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_workspaces_slug (slug),
  KEY idx_workspaces_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workspace_members (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'client',
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_workspace_members_workspace_user (workspace_id,user_id),
  KEY idx_workspace_members_user (user_id,active),
  KEY idx_workspace_members_workspace (workspace_id,active),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workspace_settings (
  workspace_id INT NOT NULL,
  `key` VARCHAR(120) NOT NULL,
  value LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (workspace_id,`key`),
  FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB;

INSERT INTO workspaces (id,name,slug,status,created_at,updated_at)
VALUES (1,'Escotilha Produtora','escotilha','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE name=VALUES(name),status='active',updated_at=VALUES(updated_at);

INSERT INTO workspace_members (workspace_id,user_id,role,active,created_at)
SELECT 1,u.id,
       CASE WHEN u.role='admin' THEN 'admin' WHEN u.role='staff' THEN 'staff' ELSE 'client' END,
       u.active,COALESCE(u.created_at,UTC_TIMESTAMP())
FROM users u
ON DUPLICATE KEY UPDATE role=VALUES(role),active=VALUES(active);

UPDATE workspace_members wm
JOIN (SELECT COALESCE((SELECT MIN(id) FROM users WHERE role='admin'),MIN(id)) AS owner_id FROM users) seed
  ON seed.owner_id=wm.user_id
SET wm.role='owner'
WHERE wm.workspace_id=1;

INSERT INTO settings (`key`,value) VALUES
 ('saas_enabled','0'),
 ('workspace_context_enabled','0'),
 ('workspace_permissions_enabled','0'),
 ('workspace_settings_enabled','0'),
 ('signup_enabled','0'),
 ('billing_enabled','0')
ON DUPLICATE KEY UPDATE value=value;

INSERT INTO settings (`key`,value) VALUES ('schema_version','20')
ON DUPLICATE KEY UPDATE value='20';
