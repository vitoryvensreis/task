-- TaskEsc SaaS B1.1: esquema aditivo novo, independente do schema 20 REMOVIDO.
-- Não modifica clientes, tasks nem outras tabelas operacionais.
-- Executar EXCLUSIVAMENTE via bin/migrate-saas-foundation.php após backup validado.
CREATE TABLE IF NOT EXISTS accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(190) NOT NULL,
 account_type VARCHAR(20) NOT NULL DEFAULT 'customer',
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_accounts_slug (slug),
 KEY idx_accounts_status (status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS account_members (
 account_id BIGINT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 membership_role VARCHAR(20) NOT NULL DEFAULT 'member',
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 joined_at DATETIME NOT NULL,
 PRIMARY KEY (account_id,user_id),
 KEY idx_account_members_user (user_id,status,account_id),
 CONSTRAINT fk_account_members_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_account_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS account_settings (
 account_id BIGINT UNSIGNED NOT NULL,
 `key` VARCHAR(100) NOT NULL,
 value LONGTEXT NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (account_id,`key`),
 CONSTRAINT fk_account_settings_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
