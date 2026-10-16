-- Schema 30: agenda compartilhada de WhatsApp para tarefas (sem SaaS).
CREATE TABLE IF NOT EXISTS whatsapp_contacts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(190) NOT NULL,
 phone VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by INT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_whatsapp_contacts_phone (phone),
 KEY idx_whatsapp_contacts_name (name),
 CONSTRAINT fk_whatsapp_contacts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
