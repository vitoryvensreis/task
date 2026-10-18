-- Stage 4: tenant staff invitations (schema 30 additive, no SaaS restoration).
-- Applied idempotently by WorkspaceTeams::migrate during the regular deployment.
-- Stores only invitation token hashes. Each staff user belongs to one workspace.
CREATE TABLE IF NOT EXISTS team_invitations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 workspace_id BIGINT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 email VARCHAR(190) NOT NULL,
 role_id BIGINT UNSIGNED NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 invited_by INT NOT NULL,
 expires_at DATETIME NOT NULL,
 accepted_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_team_invite_user(workspace_id,user_id),
 UNIQUE KEY uq_team_invite_token(token_hash),
 KEY idx_team_invites_ws_status(workspace_id,status,expires_at),
 CONSTRAINT fk_team_invite_ws FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
 CONSTRAINT fk_team_invite_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_team_invite_author FOREIGN KEY(invited_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
