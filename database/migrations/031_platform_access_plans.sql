-- Platform access plans v1: additive metadata, not the retired SaaS schema.
-- Run only through backup-gated bin/migrate-platform-access.php.
CREATE TABLE IF NOT EXISTS platform_access_plans (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(120) NOT NULL,
 code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
 billing_period VARCHAR(12) NOT NULL DEFAULT 'monthly',
 features_json JSON NOT NULL,
 team_limit INT UNSIGNED NULL,
 public_visible TINYINT NOT NULL DEFAULT 0,
 active TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_access_plan_code(code),
 KEY idx_access_plan_public(public_visible,active,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workspace_access_plans (
 workspace_id BIGINT UNSIGNED NOT NULL,
 plan_id BIGINT UNSIGNED NOT NULL,
 assigned_by INT NULL,
 assigned_at DATETIME NOT NULL,
 PRIMARY KEY(workspace_id),
 KEY idx_workspace_access_plan(plan_id),
 CONSTRAINT fk_workspace_access_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
 CONSTRAINT fk_workspace_access_plan FOREIGN KEY(plan_id) REFERENCES platform_access_plans(id) ON DELETE RESTRICT,
 CONSTRAINT fk_workspace_access_actor FOREIGN KEY(assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_access_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id INT NOT NULL,
 action VARCHAR(80) NOT NULL,
 workspace_id BIGINT UNSIGNED NULL,
 details_json JSON NULL,
 created_at DATETIME NOT NULL,
 KEY idx_platform_access_log_created(created_at,id),
 KEY idx_platform_access_log_workspace(workspace_id,id),
 CONSTRAINT fk_platform_access_log_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
