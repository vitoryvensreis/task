<?php
declare(strict_types=1);

/**
 * Strict account-scoped access building blocks, not yet wired to the legacy
 * controllers. Every new operation MUST choose a verified membership.
 */
final class AccountOwnership {
 private static function safeTable(string $table): string {
  if(!in_array($table,SaasDomainMigration::ROOT_TABLES,true))
   throw new InvalidArgumentException('This resource is not explicitly tenant-scoped.');
  return '`'.$table.'`';
 }

 /** Only rows owned by this account can ever be fetched. */
 public static function row(string $table,int $id,int $accountId,int $userId): array {
  AccountContext::forUser($userId,$accountId);
  $name=self::safeTable($table);
  $row=DB::one("SELECT * FROM $name WHERE id=? AND tenant_account_id=? LIMIT 1",[$id,$accountId]);
  if(!$row)fail('Recurso não encontrado nesta empresa.',404);
  return $row;
 }

 /** Paginate exclusively within a verified account, never using client input as SQL. */
 public static function listing(string $table,int $accountId,int $userId,int $limit=50,int $offset=0): array {
  AccountContext::forUser($userId,$accountId);
  $name=self::safeTable($table);
  $limit=max(1,min(100,$limit));$offset=max(0,$offset);
  return DB::all("SELECT * FROM $name WHERE tenant_account_id=? ORDER BY id DESC LIMIT $limit OFFSET $offset",[$accountId]);
 }

 /** Validate all referenced parents before inserting/editing a task. */
 public static function assertTaskParents(
  int $accountId,int $userId,int $clientId,int $categoryId,int $stageId,?int $projectId=null
 ): void {
  self::row('clients',$clientId,$accountId,$userId);
  self::row('categories',$categoryId,$accountId,$userId);
  self::row('stages',$stageId,$accountId,$userId);
  if($projectId!==null&&$projectId>0){
   $project=self::row('projects',$projectId,$accountId,$userId);
   if((int)$project['client_id']!==$clientId)fail('O projeto não pertence ao cliente desta task.',422);
  }
 }

 /** Fail closed on tenantless rows; do not auto-assign a tenant in controllers. */
 public static function ensureOwner(string $table,int $id,int $accountId,int $userId): void {
  self::row($table,$id,$accountId,$userId);
 }
}
