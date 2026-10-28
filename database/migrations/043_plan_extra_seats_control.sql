-- Controle de contratação de vagas extras por plano.
-- DEFAULT 1: preserva a possibilidade de contratação nos planos já criados.
-- A migração é idempotente pelo script bin/migrate-platform-extra-seats.php.
ALTER TABLE platform_access_plans
 ADD COLUMN allow_extra_seats TINYINT(1) NOT NULL DEFAULT 1 AFTER extra_user_price_cents;
