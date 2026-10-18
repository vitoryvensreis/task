-- Dedicated support correspondence between one workspace's administrators and
-- the verified platform Master. No admin-to-admin threads and no public files.
CREATE TABLE IF NOT EXISTS workspace_support_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 workspace_id BIGINT UNSIGNED NOT NULL,
 sender_user_id INT NOT NULL,
 sender_role VARCHAR(10) NOT NULL,
 client_nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NOT NULL,
 file_name VARCHAR(128) NULL,
 file_mime VARCHAR(64) NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_support_message_nonce(workspace_id,sender_user_id,client_nonce),
 KEY idx_support_workspace_feed(workspace_id,id),
 CONSTRAINT fk_support_message_workspace FOREIGN KEY(workspace_id) REFERENCES workspaces(id) ON DELETE RESTRICT,
 CONSTRAINT fk_support_message_sender FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
