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
 /** Keep legacy user provisioning and account membership atomic after B1.1.
  *
  * Only the existing internal company may be used here. This method MUST run
  * in the same transaction as the corresponding users INSERT/UPDATE. Existing
  * manually revoked memberships are never silently reactivated.
  */
 public static function syncInternalUser(int $userId,string $role,bool $active): void {
  if($userId<1||!in_array($role,['admin','staff','client'],true))
   throw new InvalidArgumentException('Invalid internal account member.');
  if(!DB::conn()->inTransaction())
   throw new LogicException('The user and its internal membership must be saved atomically.');
  $foundation=(string)(DB::one('SELECT value FROM settings WHERE `key`=?',
   [SaasFoundationMigration::MARKER])['value']??'');
  if($foundation!=='1')return; // installations not yet migrated
  SaasLegacyBoundary::assertSingleOperation();
  $account=DB::one('SELECT id FROM accounts WHERE slug=? AND account_type=? AND status=? FOR UPDATE',
   [self::LEGACY_SLUG,'internal','active']);
  if(!$account)throw new RuntimeException('Internal SaaS account is missing or inactive.');
  $accountId=(int)$account['id'];
  $memberRole=$role==='admin'?'owner':'member';
  $status=$active?'active':'inactive';
  // Revoked members remain revoked on unrelated profile edits or role updates.
  // Deactivating a user always revokes their internal membership immediately.
  DB::run(
   "INSERT INTO account_members (account_id,user_id,membership_role,status,joined_at)
      VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      membership_role=VALUES(membership_role),
      status=IF(VALUES(status)='inactive','inactive',status)",
   [$accountId,$userId,$memberRole,$status,now()]
  );
 }

}
