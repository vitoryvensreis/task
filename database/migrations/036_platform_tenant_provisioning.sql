-- Schema 30 compatible: staged future workspaces, not active tenants.
-- Intentionally not named workspace_* (legacy foundation checks staged workspace tables).
-- Rows here contain ONLY owner identity and proposed initial configuration.
-- No business rows, memberships, invoices, uploads or tokens are created.
CREATE TABLE IF NOT EXISTS platform_tenant_provisioning (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 source_workspace_id BIGINT UNSIGNED NOT NULL,
 prospective_owner_id INT NOT NULL,
 proposed_name VARCHAR(190) NOT NULL,
 proposed_slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 initial_config_json LONGTEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending',
 workspace_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 activated_at DATETIME NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_ptp_owner (prospective_owner_id),
 UNIQUE KEY uq_ptp_slug (proposed_slug),
 KEY idx_ptp_status (status,id),
 CONSTRAINT fk_ptp_source FOREIGN KEY (source_workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ptp_owner FOREIGN KEY (prospective_owner_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ptp_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;