-- Aditivo: valor de cada vaga extra por período e quantidade contratada por workspace.
-- Não altera planos ou workspaces já existentes (defaults 0).
ALTER TABLE platform_access_plans
 ADD COLUMN extra_user_price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER price_cents;
ALTER TABLE workspace_access_plans
 ADD COLUMN extra_seats INT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_id;
