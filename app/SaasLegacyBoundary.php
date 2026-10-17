<?php
declare(strict_types=1);

/**
 * Defense-in-depth for routes that still use single-operation, global SQL.
 * Until these controllers have been tenant-scoped, never let a customer
 * membership or a customer-selected session call the legacy surface.
 *
 * This gate is intentionally independent from rollout flags: manually
 * inserting a customer account must not expose the legacy API.
 */
final class SaasLegacyBoundary {
 public static function enforce(): void {
  Auth::load();
  $userId=(int)(Auth::$actor['id']??Auth::$user['id']??0);
  if($userId<1)fail('Autenticação necessária.',401);
  $foundation=DB::one('SELECT value FROM settings WHERE `key`=?',
    [SaasFoundationMigration::MARKER]);
  if(($foundation['value']??'')!=='1')return;

  // Old installations, before their first customer account, keep working.
  // Any customer record (even suspended) enables the strict separation gate.
  if(!DB::one("SELECT id FROM accounts WHERE account_type<>'internal' LIMIT 1"))return;
  $membership=AccountContext::legacyForUser($userId);
  $internalId=(int)$membership['account_id'];
  $selected=(int)($_SESSION['taskesc_saas_account']??0);
  if($selected!==0&&$selected!==$internalId)
   fail('Troque para a operação interna antes de abrir este recurso.',403);
 }
}
