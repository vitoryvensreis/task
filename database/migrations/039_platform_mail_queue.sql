CREATE TABLE IF NOT EXISTS platform_mail_queue (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 recipient VARCHAR(190) NOT NULL,
 subject VARCHAR(190) NOT NULL,
 body LONGTEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 sent_at DATETIME NULL,
 attempts INT NOT NULL DEFAULT 0,
 last_attempt_at DATETIME NULL,
 error TEXT NULL,
 next_attempt DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_platform_mail_queue_status_next (status,next_attempt,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
