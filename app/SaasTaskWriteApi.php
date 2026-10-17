<?php
declare(strict_types=1);

/**
 * SaaS B1.2c-2b.1: task mutations for a VERIFIED internal account owner only.
 *
 * Explicit staged API: no legacy notifications, automation, billing, Drive,
 * realtime, WhatsApp or external side effects. Do not wire this into the
 * normal Kanban or allow external tenants before the remaining modules are
 * fully tenant-scoped and events are transactional. Events are now staged
 * atomically in saas_event_outbox, never delivered to legacy consumers.
 */
final class SaasTaskWriteApi {
 private const SAVE_FIELDS=[
  'id','version','client_id','project_id','category_id','title','brief',
  'due_at','priority','assigned_to','draft'
 ];
 private const MUTATION_FIELDS=['id','version','stage_id'];
 private const ARCHIVE_FIELDS=['id','version'];

 private static function only(array $p,array $allowed): void {
  foreach(array_keys($p) as $key)
   if(!in_array($key,$allowed,true))
    fail('Campo não permitido nesta operação: '.$key.'.',422);
 }

 private static function tx(callable $operation): array {
  return DB::tx(static function()use($operation): array {
   $member=SaasRequestScope::owner();
   // Every staged task mutation MUST commit its event atomically.
   SaasEventOutbox::ready();
   $account=(int)$member['account_id'];
   $actor=(int)(Auth::$actor['id']??0);
   if($account<=0||$actor<=0)fail('Sessão de empresa inválida.',403);
   return $operation($account,$actor);
  });
 }

 /** Locks tenant and row together. A guessed ID is never sufficient. */
 private static function task(int $id,int $account): array {
  $row=DB::one('SELECT * FROM demands WHERE id=? AND tenant_account_id=? AND deleted_at IS NULL FOR UPDATE',
   [$id,$account]);
  if(!$row)fail('Task não encontrada nesta empresa.',404);
  return $row;
 }

 private static function version(array $p,array $task): void {
  if(integer($p['version']??0,1)!==(int)$task['version'])
   fail('A task foi alterada. Atualize antes de salvar.',409);
 }

 private static function client(int $id,int $account): array {
  $row=DB::one('SELECT id,commercial_type FROM clients WHERE id=? AND tenant_account_id=? AND active=1 FOR UPDATE',
   [$id,$account]);
  if(!$row)fail('Cliente não encontrado nesta empresa.',404);
  return $row;
 }

 private static function category(int $id,int $account): array {
  $row=DB::one('SELECT id,default_due_hours,default_priority,default_revisions FROM categories WHERE id=? AND tenant_account_id=? AND active=1 FOR UPDATE',
   [$id,$account]);
  if(!$row)fail('Categoria não encontrada nesta empresa.',404);
  return $row;
 }

 private static function stage(int $id,int $account): array {
  $row=DB::one('SELECT id,kind FROM stages WHERE id=? AND tenant_account_id=? AND active=1 FOR UPDATE',
   [$id,$account]);
  if(!$row)fail('Etapa não encontrada nesta empresa.',404);
  return $row;
 }

 private static function project(?int $id,int $client,int $account): ?int {
  if($id===null)return null;
  $row=DB::one('SELECT id,client_id FROM projects WHERE id=? AND tenant_account_id=? FOR UPDATE',
   [$id,$account]);
  if(!$row)fail('Projeto não encontrado nesta empresa.',404);
  if((int)$row['client_id']!==$client)
   fail('O projeto não pertence ao cliente informado.',422);
  return $id;
 }

 private static function assignee(int $user,int $client,int $account): ?int {
  if(!$user)return null;
  $member=DB::one(
   "SELECT u.id,u.role FROM users u JOIN account_members m ON m.user_id=u.id
    WHERE u.id=? AND u.active=1 AND u.role IN ('admin','staff')
    AND m.account_id=? AND m.status='active' FOR UPDATE",
   [$user,$account]
  );
  if(!$member)fail('Responsável não pertence à equipe desta empresa.',422);
  if($member['role']==='staff'&&!DB::one('SELECT 1 FROM user_clients WHERE user_id=? AND client_id=?',[$user,$client]))
   fail('Responsável não está vinculado ao cliente.',422);
  return $user;
 }

 private static function optionalBrief(array $p,?array $old): string {
  if(!array_key_exists('brief',$p)&&$old)return (string)$old['brief'];
  return required($p,'brief',20000);
 }

 /**
  * Creates a task in the first scoped stage, or updates a versioned task
  * without silently moving stages or changing commercial/WhatsApp settings.
  */
 public static function save(array $p): array {
  self::only($p,self::SAVE_FIELDS);
  return self::tx(static function(int $account,int $actor)use($p): array {
   $id=integer($p['id']??0,0);
   $old=$id?self::task($id,$account):null;
   if($old)self::version($p,$old);
   $clientId=integer($p['client_id']??($old['client_id']??0),1);
   if($old&&$clientId!==(int)$old['client_id'])fail('O cliente de uma task existente não pode ser trocado.',409);
   $client=self::client($clientId,$account);
   $categoryId=integer($p['category_id']??($old['category_id']??0),1);
   $category=self::category($categoryId,$account);

   $projectRaw=array_key_exists('project_id',$p)?$p['project_id']:($old['project_id']??null);
   $projectId=($projectRaw===null||$projectRaw==='')?null:integer($projectRaw,1);
   $projectId=self::project($projectId,$clientId,$account);
   $assignedRaw=array_key_exists('assigned_to',$p)?$p['assigned_to']:($old['assigned_to']??null);
   $assignedId=($assignedRaw===null||$assignedRaw==='')?0:integer($assignedRaw,0);
   $assigned=self::assignee($assignedId,$clientId,$account);

   $title=array_key_exists('title',$p)?required($p,'title',190):($old?((string)$old['title']):required($p,'title',190));
   $brief=self::optionalBrief($p,$old);
   if(array_key_exists('due_at',$p)){
    if(!is_string($p['due_at'])||trim($p['due_at'])==='')fail('Informe a data de entrega.',422);
    $due=timestamp($p['due_at']);
    if(!$due)fail('Data de entrega inválida.',422);
    validateDelivery($due,$old['due_at']??null);
   }elseif($old){
    // Persisted datetimes are already UTC; never reinterpret as local time.
    $due=(string)$old['due_at'];
   }else {
    $due=businessDeadline(max(1,(int)$category['default_due_hours']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
   }
   $priority=enumv($p['priority']??($old['priority']??$category['default_priority']),
    ['low','normal','high','urgent'],'prioridade');
   $draft=array_key_exists('draft',$p)?(int)(bool)$p['draft']:(int)($old['draft']??0);

   if($old){
    $stage=self::stage((int)$old['stage_id'],$account);
   }else{
    $stage=DB::one('SELECT id,kind FROM stages WHERE tenant_account_id=? AND active=1 ORDER BY position,id LIMIT 1 FOR UPDATE',[$account]);
    if(!$stage)fail('Nenhuma etapa disponível nesta empresa.',409);
   }
   $stageId=(int)$stage['id'];
   if($draft&&in_array($stage['kind'],['planned','production','approval','completed'],true))
    fail('Publique o rascunho antes de iniciar a produção.',409);

   // Match the existing duplicate invariant without scanning other tenants.
   $duplicate=DB::one(
    'SELECT id FROM demands WHERE tenant_account_id=? AND client_id=? AND category_id=?'.
    ' AND title=? AND due_at=? AND deleted_at IS NULL AND id<>? LIMIT 1',
    [$account,$clientId,$categoryId,$title,$due,$id]
   );
   if($duplicate)fail('Já existe uma task igual para este cliente, serviço e entrega.',409);

   $now=now();
   $fields=['client_id'=>$clientId,'project_id'=>$projectId,'category_id'=>$categoryId,
    'title'=>$title,'brief'=>$brief,'due_at'=>$due,'priority'=>$priority,
    'assigned_to'=>$assigned,'draft'=>$draft,'updated_at'=>$now];
   if($old){
    $fields['version']=(int)$old['version']+1;
    $affected=DB::run(
     'UPDATE demands SET client_id=?,project_id=?,category_id=?,title=?,brief=?,due_at=?,priority=?,assigned_to=?,draft=?,updated_at=?,version=?'.
     ' WHERE id=? AND tenant_account_id=? AND version=? AND deleted_at IS NULL',
     [...array_values($fields),$id,$account,(int)$old['version']]
    );
    if($affected->rowCount()!==1)fail('Task atualizada por outra pessoa. Recarregue.',409);
   }else{
    $billing=$client['commercial_type']==='monthly'?'included':'one_off';
    $id=DB::insert('demands',$fields+[
     'tenant_account_id'=>$account,'stage_id'=>$stageId,
     'details_json'=>'{}','created_by'=>$actor,'created_at'=>$now,
     'billing_mode'=>$billing,'amount_cents'=>0,
     'commercial_status'=>'pending','whatsapp_recipient_mode'=>'none',
     'revisions_limit'=>(int)$category['default_revisions'],
     'version'=>1
    ]);
    DB::insert('transitions',['tenant_account_id'=>$account,'demand_id'=>$id,
     'from_stage'=>null,'to_stage'=>$stageId,'actor_id'=>$actor,'happened_at'=>$now]);
   }
   // Only inert SaaS outbox staging: legacy automation/realtime workers cannot consume it.
   SaasEventOutbox::task($account,$id,(int)($fields['version']??1),$old?'demand.updated':'demand.created',$actor);
   // The legacy audit log has no tenant column. Staged writes remain INTERNAL-
   // ONLY until audit/notifications/outbox are tenantized in later B1.2c work.
   audit('demand',$id,$old?'saas.update':'saas.create',
    $old?['version'=>$old['version'],'title'=>$old['title']]:null,
    ['tenant_account_id'=>$account,'version'=>$fields['version']??1,'title'=>$title]);
   return ['id'=>$id,'version'=>$fields['version']??1,'created'=>$old===null,'stage_id'=>$stageId];
  });
 }

 /** A simple versioned Kanban stage transition, no reorder or async effects. */
 public static function move(array $p): array {
  self::only($p,self::MUTATION_FIELDS);
  return self::tx(static function(int $account,int $actor)use($p): array {
   $task=self::task(integer($p['id']??0,1),$account);
   self::version($p,$task);
   if((int)$task['draft']===1)fail('Publique a task antes de mudar a etapa.',409);
   $to=self::stage(integer($p['stage_id']??0,1),$account);
   $from=(int)$task['stage_id'];
   if($from===(int)$to['id'])fail('A task já está nesta etapa.',409);
   self::stage($from,$account);
   $now=now();$kind=(string)$to['kind'];
   $completed=$kind==='completed'?($task['completed_at']??$now):null;
   $first=$task['first_completed_at']??($kind==='completed'?$now:null);
   $accepted=in_array($kind,['planned','production','approval','completed'],true)
    ?($task['accepted_at']??$now):($task['accepted_at']??null);
   $version=(int)$task['version']+1;
   $affected=DB::run(
    'UPDATE demands SET stage_id=?,version=?,updated_at=?,completed_at=?,first_completed_at=?,accepted_at=?'.
    ' WHERE id=? AND tenant_account_id=? AND version=? AND deleted_at IS NULL',
    [(int)$to['id'],$version,$now,$completed,$first,$accepted,$task['id'],$account,(int)$task['version']]
   );
   if($affected->rowCount()!==1)fail('A task foi alterada. Atualize e tente novamente.',409);
   DB::insert('transitions',['tenant_account_id'=>$account,'demand_id'=>(int)$task['id'],
    'from_stage'=>$from,'to_stage'=>(int)$to['id'],'actor_id'=>$actor,'happened_at'=>$now]);
   SaasEventOutbox::task($account,(int)$task['id'],$version,'demand.stage_changed',$actor,['previous_stage_id'=>$from]);
   audit('demand',(int)$task['id'],'saas.stage',
    ['stage_id'=>$from,'version'=>$task['version']],
    ['stage_id'=>(int)$to['id'],'version'=>$version,'tenant_account_id'=>$account]);
   return ['id'=>(int)$task['id'],'version'=>$version,'stage_id'=>(int)$to['id']];
  });
 }

 /**
  * Conservative soft-deletion: any external/file/financial/automation link
  * blocks removal until those modules have tenant-aware cleanup policies.
  */
 public static function archive(array $p): array {
  self::only($p,self::ARCHIVE_FIELDS);
  return self::tx(static function(int $account,int $actor)use($p): array {
   $task=self::task(integer($p['id']??0,1),$account);
   self::version($p,$task);
   foreach([
    'invoices','files','costs','demand_usage','events',
    'automation_runs','chat_message_tasks','whatsapp_messages'
   ] as $table){
    if($table==='automation_runs')
     $found=DB::one('SELECT 1 FROM automation_runs WHERE entity_type=? AND entity_id=? LIMIT 1',
      ['demand',$task['id']]);
    else $found=DB::one('SELECT 1 FROM `'.$table.'` WHERE demand_id=? LIMIT 1',[$task['id']]);
    if($found)fail('Task possui registros vinculados e não pode ser arquivada nesta etapa.',409);
   }
   $now=now();$version=(int)$task['version']+1;
   $affected=DB::run('UPDATE demands SET deleted_at=?,updated_at=?,version=?'.
    ' WHERE id=? AND tenant_account_id=? AND version=? AND deleted_at IS NULL',
    [$now,$now,$version,$task['id'],$account,(int)$task['version']]);
   if($affected->rowCount()!==1)fail('A task foi alterada. Atualize e tente novamente.',409);
   SaasEventOutbox::task($account,(int)$task['id'],$version,'demand.archived',$actor);
   audit('demand',(int)$task['id'],'saas.archive',
    ['version'=>$task['version'],'deleted_at'=>null],
    ['version'=>$version,'deleted_at'=>$now,'tenant_account_id'=>$account]);
   return ['id'=>(int)$task['id'],'version'=>$version,'archived'=>true];
  });
 }
}
