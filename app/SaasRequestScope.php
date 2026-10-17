<?php
declare(strict_types=1);

/**
 * SaaS B1.2c-1 — opt-in HTTP request context.
 *
 * The legacy TaskEsc API remains single-operation; this service is used
 * ONLY by explicit saas.* endpoints until every legacy controller is scoped.
 * API bearer tokens have no tenant grant yet and cannot use these endpoints.
 */
final class SaasRequestScope {
 private const SESSION_KEY='taskesc_saas_account';

 public static function ready(): void {
  $flags=DB::all('SELECT `key`,value FROM settings WHERE `key` IN (?,?,?)',[
   SaasFoundationMigration::MARKER,SaasDomainMigration::MARKER,SaasChildMigration::MARKER
  ]);
  $values=[];foreach($flags as $flag)$values[$flag['key']]=$flag['value'];
  if(($values[SaasFoundationMigration::MARKER]??'')!=='1'
   ||($values[SaasDomainMigration::MARKER]??'')!=='2'
   ||($values[SaasChildMigration::MARKER]??'')!=='3')
   fail('Acesso SaaS indisponível: migrações de isolamento pendentes.',503);
 }

 private static function currentUser(): int {
  Auth::load();
  if(Auth::$origin!=='web')fail('Tokens de API ainda não possuem vínculo seguro com empresas.',403);
  if(Auth::isPreview())fail('Saia da visualização de cliente para selecionar uma empresa.',403);
  return (int)(Auth::$actor['id']??Auth::$user['id']??0);
 }

 private static function allowed(array $membership): array {
  if(($membership['account_type']??'')==='internal')return $membership;
  $flags=DB::all('SELECT `key`,value FROM account_settings WHERE account_id=? AND `key` IN (?,?)',
   [(int)$membership['account_id'],'saas.external_signup_enabled','saas.multi_account_enabled']);
  $f=[];foreach($flags as $flag)$f[$flag['key']]=$flag['value'];
  // Tenant onboarding must not be unlocked by a guessed id or one missing flag.
  if(($f['saas.multi_account_enabled']??'')!=='1'||($f['saas.external_signup_enabled']??'')!=='1')
   fail('Acesso a empresas externas ainda não habilitado.',403);
  return $membership;
 }

 /** Select only an account with an active membership. Never use an id from
  * arbitrary GET/POST fields to scope subsequent queries.
  */
 public static function select(array $p): array {
  self::ready();
  $userId=self::currentUser();
  $accountId=integer($p['account_id']??0,1);
  $membership=self::allowed(AccountContext::forUser($userId,$accountId));
  if(session_status()!==PHP_SESSION_ACTIVE)fail('Sessão de conta indisponível.',503);
  $_SESSION[self::SESSION_KEY]=(int)$membership['account_id'];
  return ['account'=>self::publicMembership($membership),'selected'=>true];
 }

 public static function resolve(): array {
  self::ready();
  $userId=self::currentUser();
  $chosen=(int)($_SESSION[self::SESSION_KEY]??0);
  $membership=$chosen>0?AccountContext::forUser($userId,$chosen):AccountContext::legacyForUser($userId);
  return self::allowed($membership);
 }

 public static function status(): array {
  return ['account'=>self::publicMembership(self::resolve()),'external_tenants_operational'=>false];
 }

 private static function publicMembership(array $m): array {
  return ['id'=>(int)$m['account_id'],'name'=>$m['name'],'slug'=>$m['slug'],
   'type'=>$m['account_type'],'membership_role'=>$m['membership_role']];
 }

 /** During B1.2c-1, scoped reads are restricted to account owners.
  * RBAC roles arrive in B2; we must never equate global admin with tenant owner.
  */
 public static function owner(): array {
  $membership=self::resolve();
  Auth::admin();
  if(($membership['membership_role']??'')!=='owner')fail('Apenas o administrador proprietário pode acessar esta consulta.',403);
  return $membership;
 }

 public static function clearForTests(): void {
  // Intentionally unavailable to HTTP clients. Tests clear $_SESSION directly.
  throw new LogicException('Use the session lifecycle to clear account selection.');
 }
}
