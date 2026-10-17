<?php
declare(strict_types=1);

/**
 * Foundation only: resolve a verified membership, never accept an account id
 * from the request without checking the user-account relationship.
 * B1.2 must propagate this context to EVERY query before onboarding tenants.
 */
final class AccountContext {
 public const LEGACY_SLUG='taskesc-existing-operation';

 /** Throws 403/404 on invalid, suspended or foreign memberships. */
 public static function forUser(int $userId,int $accountId): array {
  if($userId<1||$accountId<1)fail('Conta inválida.',403);
  $member=DB::one(
   "SELECT a.id AS account_id,a.slug,a.name,a.account_type,a.status AS account_status,
           m.user_id,m.membership_role,m.status AS member_status
    FROM account_members m JOIN accounts a ON a.id=m.account_id
    JOIN users u ON u.id=m.user_id
    WHERE m.account_id=? AND m.user_id=? AND u.active=1 LIMIT 1",
   [$accountId,$userId]
  );
  if(!$member||$member['account_status']!=='active'||$member['member_status']!=='active')fail('Você não tem acesso a esta conta.',403);
  return $member;
 }

 public static function forCurrentUser(int $accountId): array {
  Auth::load();
  return self::forUser((int)(Auth::$user['id']??0),$accountId);
 }

 /** Phase 1 supports only the single existing operation. Never auto-pick
  * between multiple memberships: explicit and verified selection is required.
  */
 public static function legacyForUser(int $userId): array {
  $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=? LIMIT 1',[self::LEGACY_SLUG,'internal']);
  if(!$account)fail('A fundação multiempresa ainda não foi instalada.',503);
  return self::forUser($userId,(int)$account['id']);
 }
}
