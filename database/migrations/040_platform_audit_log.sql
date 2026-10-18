CREATE TABLE IF NOT EXISTS platform_audit_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NULL,
 entity VARCHAR(40) NOT NULL,
 entity_id INT NOT NULL,
 action VARCHAR(80) NOT NULL,
 before_json LONGTEXT NULL,
 after_json LONGTEXT NULL,
 origin VARCHAR(20) NOT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_platform_audit_created (created_at,id),
 KEY idx_platform_audit_actor (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
