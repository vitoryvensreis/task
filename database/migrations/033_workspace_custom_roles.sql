-- Custom employee roles are strictly scoped to a single existing workspace.
-- Does not change users.role (admin/staff/client) or enable new workspaces.
CREATE TABLE IF NOT EXISTS workspace_role_definitions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 workspace_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(80) NOT NULL,
 description VARCHAR(255) NOT NULL DEFAULT '',
 abilities_json JSON NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 created_by INT NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_workspace_role_name(workspace_id,name),
 KEY idx_workspace_role_active(workspace_id,active,id),
 CONSTRAINT fk_workspace_role_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
 CONSTRAINT fk_workspace_role_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- workspace_members.custom_role_id is added idempotently by the backup-gated migration script.
