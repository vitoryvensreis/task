<?php
declare(strict_types=1);

/**
 * B1.2c-1: explicitly gated, read-only tenant-scoped projections.
 * No SELECT *, no global query fallback, no user-supplied SQL identifier.
 * Existing single-operation endpoints are deliberately unchanged.
 */
final class SaasReadApi {
 private const QUERIES=[
  'clients'=>[
   'from'=>'clients c',
   'where'=>'c.tenant_account_id=?',
   'fields'=>'c.id,c.name,c.brand,c.commercial_type,c.active,c.created_at',
   'sort'=>'c.id',
   'id'=>'c.id'
  ],
  'projects'=>[
   'from'=>'projects p INNER JOIN clients c ON c.id=p.client_id AND c.tenant_account_id=p.tenant_account_id',
   'where'=>'p.tenant_account_id=?',
   'fields'=>'p.id,p.client_id,p.name,p.description,p.created_at',
   'sort'=>'p.id',
   'id'=>'p.id'
  ],
  'tasks'=>[
   'from'=>'demands d INNER JOIN clients c ON c.id=d.client_id AND c.tenant_account_id=d.tenant_account_id INNER JOIN categories k ON k.id=d.category_id AND k.tenant_account_id=d.tenant_account_id INNER JOIN stages s ON s.id=d.stage_id AND s.tenant_account_id=d.tenant_account_id LEFT JOIN projects p ON p.id=d.project_id AND p.tenant_account_id=d.tenant_account_id AND p.client_id=d.client_id',
   'where'=>'d.tenant_account_id=? AND d.deleted_at IS NULL AND (d.project_id IS NULL OR p.id IS NOT NULL)',
   'fields'=>'d.id,d.client_id,d.project_id,d.category_id,d.stage_id,d.title,d.priority,d.due_at,d.assigned_to,d.created_at,d.updated_at',
   'sort'=>'d.id',
   'id'=>'d.id'
  ]
 ];

 private static function query(string $name): array {
  if(!array_key_exists($name,self::QUERIES))fail('Recurso indisponível para leitura por empresa.',404);
  return self::QUERIES[$name];
 }

 public static function listing(array $p): array {
  $membership=SaasRequestScope::owner();
  $resource=trim((string)($p['resource']??''));
  $q=self::query($resource);
  $account=(int)$membership['account_id'];
  $limit=integer($p['limit']??30,1,100);
  $offset=integer($p['offset']??0,0,10000);
  $sql='SELECT '.$q['fields'].' FROM '.$q['from'].' WHERE '.$q['where'].
   ' ORDER BY '.$q['sort'].' DESC LIMIT '.$limit.' OFFSET '.$offset;
  return ['resource'=>$resource,'account_id'=>$account,
   'rows'=>DB::all($sql,[$account]),'limit'=>$limit,'offset'=>$offset];
 }

 public static function get(array $p): array {
  $membership=SaasRequestScope::owner();
  $q=self::query(trim((string)($p['resource']??'')));
  $id=integer($p['id']??0,1);
  $row=DB::one(
   'SELECT '.$q['fields'].' FROM '.$q['from'].' WHERE '.$q['where'].' AND '.$q['id'].'=? LIMIT 1',
   [(int)$membership['account_id'],$id]
  );
  if(!$row)fail('Registro não encontrado nesta empresa.',404);
  return $row;
 }
}
