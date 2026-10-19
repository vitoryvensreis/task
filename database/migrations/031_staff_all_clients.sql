-- Independent additive migration; Migrations::run checks column existence before applying.
-- No bump to schema_version (other operational migrations may already be newer).
ALTER TABLE users ADD COLUMN staff_all_clients TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
