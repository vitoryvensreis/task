<?php
declare(strict_types=1);

/**
 * Transitional fail-closed isolation boundary.
 *
 * No legacy controller, MCP API, download or worker can safely run against
 * customer tenants until all its SQL and provider side-effects are scoped.
 * A legacy administrator could otherwise read customer data even if the
 * customer's own login were denied. Therefore block ALL legacy operations
 * whenever any external account exists or a multi-account flag is enabled.
 *
 * This protection is intentionally independent from UI flags. Replace it
 * only after tenant-aware controllers AND workers pass cross-tenant checks.
 */
final class SaasLegacyBoundary {
 public static function assertSingleOperation(): void {
  $foundation=DB::one('SELECT value FROM settings WHERE `key`=?',
    [SaasFoundationMigration::MARKER]);
  if(($foundation['value']??'')!=='1')return;

  $external=DB::one("SELECT id FROM accounts WHERE account_type<>'internal' LIMIT 1");
  $enabled=DB::one(
   "SELECT account_id FROM account_settings
     WHERE `key` IN ('saas.multi_account_enabled','saas.external_signup_enabled')
       AND value<>'0' LIMIT 1"
  );
  if($external||$enabled)
   throw new RuntimeException('saas_legacy_surface_requires_tenant_scoping');
 }

 public static function enforce(): void {
  Auth::load();
  try {self::assertSingleOperation();}
  catch(RuntimeException $e) {
   if($e->getMessage()!=='saas_legacy_surface_requires_tenant_scoping')throw $e;
   fail('Este recurso ainda não está isolado entre empresas.',503);
  }
 }
}
