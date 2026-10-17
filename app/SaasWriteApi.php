<?php
declare(strict_types=1);

/**
 * B1.2c-2a. Explicit, narrowly scoped staged mutations for clients and projects.
 * These are NOT replacements for Catalog::clientSave/projectSave; the legacy
 * functions trigger user creation, automations and notifications that have not
 * yet been isolated by tenant. No external tenant can reach this API until B1.3.
 */
final class SaasWriteApi {
 private static function guardInput(array $p): void {
  foreach(['tenant_account_id','account_id','owner_id','created_by'] as $key)
   if(array_key_exists($key,$p))fail('A empresa e o autor são definidos pela sessão, não pela solicitação.',403);
 }

 private static function ownerTx(callable $operation): array {
  return DB::tx(static function()use($operation): array {
   $membership=SaasRequestScope::owner();
   $accountId=(int)$membership['account_id'];
   $actorId=(int)(Auth::$actor['id']??0);
   if($actorId<1)fail('Usuário não autenticado.',401);
   return $operation($accountId,$actorId);
  });
 }

 private static function current(string $table,int $id,int $accountId): array {
  // Only call with hardcoded, audited table names.
  if(!in_array($table,['clients','projects'],true))throw new LogicException('Invalid SaaS mutation table.');
  $row=DB::one('SELECT * FROM `'.$table.'` WHERE id=? AND tenant_account_id=? FOR UPDATE',[$id,$accountId]);
  if(!$row)fail('Registro não encontrado nesta empresa.',404);
  return $row;
 }

 private static function activeClient(int $id,int $accountId): array {
  $client=self::current('clients',$id,$accountId);
  if((int)$client['active']!==1)fail('Cliente inativo.',409);
  return $client;
 }

 private static function optionalString(array $p,string $key,string $default,int $max): string {
  if(!array_key_exists($key,$p))return $default;
  if(!is_string($p[$key]))fail('Valor inválido para '.$key.'.',422);
  $value=trim($p[$key]);
  if(mb_strlen($value)>$max)fail('Valor muito longo para '.$key.'.',422);
  return $value;
 }

 /** Creates or updates a subset of client attributes; never changes tokens,
  * Drive credentials, users, automated messages, plan/account or finance.
  */
 public static function clientSave(array $p): array {
  self::guardInput($p);
  return self::ownerTx(static function(int $account,int $actor)use($p): array {
   $id=integer($p['id']??0,0);
   $old=$id?self::current('clients',$id,$account):null;
   if($old&&(int)$old['active']!==1)fail('Cliente inativo.',409);
   $name=required($p,'name',190);
   $brand=self::optionalString($p,'brand',(string)($old['brand']??''),190);
   $email=self::optionalString($p,'email',(string)($old['email']??''),190);
   if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('E-mail inválido.',422);
   $type=enumv($p['commercial_type']??($old['commercial_type']??'one_off'),['one_off','monthly'],'modelo comercial');
   $amount=$type==='monthly'?integer($p['monthly_amount_cents']??($old['monthly_amount_cents']??0),0,1000000000):0;
   $day=$type==='monthly'?integer($p['monthly_payment_day']??($old['monthly_payment_day']??10),1,31):10;
   $fields=['name'=>$name,'brand'=>$brand,'email'=>$email,
    'commercial_type'=>$type,'monthly_amount_cents'=>$amount,'monthly_payment_day'=>$day];
   if($id){
    $set='name=?,brand=?,email=?,commercial_type=?,monthly_amount_cents=?,monthly_payment_day=?';
    $updated=DB::run('UPDATE clients SET '.$set.' WHERE id=? AND tenant_account_id=? AND active=1',
     [...array_values($fields),$id,$account]);
    // The locked row plus account predicate ensures no cross-account upsert.
   }else {
    $id=DB::insert('clients',$fields+['tenant_account_id'=>$account,'active'=>1,'created_at'=>now()]);
   }
   audit('client',$id,$old?'saas.update':'saas.create',
    $old?['name'=>$old['name'],'brand'=>$old['brand']]:null,
    ['name'=>$name,'brand'=>$brand,'tenant_account_id'=>$account]);
   return ['id'=>$id,'saved'=>true,'created'=>$old===null];
  });
 }

 /** Soft-delete only empty clients. Do not revoke team members or alter
  * existing projects, tasks, contracts, invoices or payment records.
  */
 public static function clientArchive(array $p): array {
  self::guardInput($p);
  return self::ownerTx(static function(int $account,int $actor)use($p): array {
   $id=integer($p['id']??0,1);
   $old=self::activeClient($id,$account);
   foreach([
    'projects'=>'client_id','demands'=>'client_id','contracts'=>'client_id',
    'invoices'=>'client_id','user_clients'=>'client_id','templates'=>'recurring_client_id'
   ] as $table=>$fk){
    if(DB::one('SELECT 1 FROM `'.$table.'` WHERE `'.$fk.'`=? LIMIT 1',[$id]))
     fail('Cliente possui registros vinculados e não pode ser arquivado por esta operação.',409);
   }
   DB::run('UPDATE clients SET active=0 WHERE id=? AND tenant_account_id=? AND active=1',[$id,$account]);
   audit('client',$id,'saas.archive',['name'=>$old['name']],['active'=>0,'tenant_account_id'=>$account]);
   return ['id'=>$id,'archived'=>true];
  });
 }

 public static function projectSave(array $p): array {
  self::guardInput($p);
  return self::ownerTx(static function(int $account,int $actor)use($p): array {
   $id=integer($p['id']??0,0);
   $old=$id?self::current('projects',$id,$account):null;
   $clientId=integer($p['client_id']??($old['client_id']??0),1);
   self::activeClient($clientId,$account);
   $name=required($p,'name',190);
   $description=self::optionalString($p,'description',(string)($old['description']??''),5000);
   if($old&&(int)$old['client_id']!==$clientId){
    // This also covers soft-deleted tasks: no historical cross-client change.
    foreach(['demands','invoices'] as $table)
     if(DB::one('SELECT 1 FROM `'.$table.'` WHERE project_id=? LIMIT 1',[$id]))
      fail('Não é possível mudar o cliente de um projeto com tasks ou cobranças.',409);
   }
   if($id){
    DB::run('UPDATE projects SET client_id=?,name=?,description=? WHERE id=? AND tenant_account_id=?',
     [$clientId,$name,$description,$id,$account]);
   }else{
    $id=DB::insert('projects',['tenant_account_id'=>$account,'client_id'=>$clientId,
     'name'=>$name,'description'=>$description,'created_at'=>now()]);
   }
   audit('project',$id,$old?'saas.update':'saas.create',
    $old?['name'=>$old['name'],'client_id'=>$old['client_id']]:null,
    ['name'=>$name,'client_id'=>$clientId,'tenant_account_id'=>$account]);
   return ['id'=>$id,'saved'=>true,'created'=>$old===null];
  });
 }

 /** Hard deletion of an EMPTY project only. For populated projects, wait for
  * the future tenant-aware archive/migration policy instead of cascading.
  */
 public static function projectDelete(array $p): array {
  self::guardInput($p);
  return self::ownerTx(static function(int $account,int $actor)use($p): array {
   $id=integer($p['id']??0,1);
   $old=self::current('projects',$id,$account);
   foreach(['demands','invoices'] as $table)
    if(DB::one('SELECT 1 FROM `'.$table.'` WHERE project_id=? LIMIT 1',[$id]))
     fail('Projeto com tasks ou cobranças não pode ser excluído.',409);
   $deleted=DB::run('DELETE FROM projects WHERE id=? AND tenant_account_id=?',[$id,$account]);
   if($deleted->rowCount()!==1)fail('Projeto indisponível para exclusão.',409);
   audit('project',$id,'saas.delete',['name'=>$old['name'],'client_id'=>$old['client_id']],['tenant_account_id'=>$account]);
   return ['id'=>$id,'deleted'=>true];
  });
 }
}
