<?php
final class Demands {
 public static function list(array $p): array {
  [$w,$a]=Auth::demandFilter();
  if(Auth::$user['role']==='client'){$w.=' AND (d.draft=0 OR d.created_by=?)';$a[]=Auth::$user['id'];}
  foreach(['client_id','category_id','stage_id','assigned_to','project_id'] as $key)if(!empty($p[$key])){$w.=' AND d.'.$key.'=?';$a[]=integer($p[$key],1);}
  if(!empty($p['priority'])){$w.=' AND d.priority=?';$a[]=enumv((string)$p['priority'],['low','normal','high','urgent'],'prioridade');}
  if(!empty($p['q'])){$w.=' AND (d.title LIKE ? OR c.name LIKE ? OR ca.name LIKE ? OR u.name LIKE ? OR d.id=?)';$like='%'.trim((string)$p['q']).'%';array_push($a,$like,$like,$like,$like,(int)$p['q']);}
  if(!empty($p['overdue'])){$w.=" AND d.due_at<? AND s.kind NOT IN ('completed','cancelled','archived')";$a[]=now();}
  if(!empty($p['due_from'])){$w.=' AND d.due_at>=?';$a[]=datev($p['due_from'],false).' 00:00:00';}
  if(!empty($p['due_to'])){$w.=' AND d.due_at<=?';$a[]=datev($p['due_to'],false).' 23:59:59';}
  if(empty($p['include_closed']))$w.=" AND s.kind NOT IN ('cancelled','archived')";
  $sortMap=['updated'=>'d.updated_at DESC','due'=>'d.due_at ASC','created'=>'d.created_at DESC','title'=>'d.title ASC','priority'=>"FIELD(d.priority,'urgent','high','normal','low'),d.due_at ASC"];$order=$sortMap[(string)($p['sort']??'updated')]??$sortMap['updated'];
  $base=' FROM demands d JOIN clients c ON c.id=d.client_id JOIN categories ca ON ca.id=d.category_id JOIN stages s ON s.id=d.stage_id LEFT JOIN users u ON u.id=d.assigned_to WHERE '.$w;
  $select='SELECT d.*,c.name AS client_name,c.commercial_type AS client_commercial_type,c.monthly_amount_cents,ca.name AS category_name,s.name AS stage_name,s.kind AS stage_kind,s.color AS stage_color,u.name AS assigned_name';
  if(!empty($p['paginate'])){
   $page=max(1,(int)($p['page']??1));$per=max(10,min(100,(int)($p['per_page']??30)));$total=(int)(DB::one('SELECT COUNT(*) AS n'.$base,$a)['n']??0);$offset=($page-1)*$per;
   $rows=DB::all($select.$base.' ORDER BY '.$order.' LIMIT '.$per.' OFFSET '.$offset,$a);
   return ['rows'=>array_map([self::class,'visible'],$rows),'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'pages'=>max(1,(int)ceil($total/$per))]];
  }
  $rows=DB::all($select.$base.' ORDER BY d.sort_order,'.$order.' LIMIT 2000',$a);return array_map([self::class,'visible'],$rows);
 }

 public static function visible(array $d): array {
  if(Auth::$user['role']!=='admin'){
   unset($d['internal_notes']);
   if(Auth::$user['role']==='staff')unset($d['amount_cents']);
   elseif(!Auth::isPreview()&&!DB::one('SELECT client_id FROM user_clients WHERE user_id=? AND client_id=? AND can_finance=1',[Auth::$user['id'],$d['client_id']]))unset($d['amount_cents']);
  }
  return $d;
 }

 public static function get(array $p): array {
  $d=Auth::demand(integer($p['id']??0,1));
  if($d['draft']&&Auth::$user['role']==='client'&&$d['created_by']!=Auth::$user['id'])fail('Rascunho privado.',403);
  $id=(int)$d['id'];$client=Auth::$user['role']==='client';
  $labels=DB::one('SELECT c.name AS client_name,c.commercial_type AS client_commercial_type,c.monthly_amount_cents,c.monthly_payment_day,ca.name AS category_name,s.name AS stage_name,s.kind AS stage_kind,s.color AS stage_color,u.name AS assigned_name FROM demands d JOIN clients c ON c.id=d.client_id JOIN categories ca ON ca.id=d.category_id JOIN stages s ON s.id=d.stage_id LEFT JOIN users u ON u.id=d.assigned_to WHERE d.id=?',[$id])??[];
  $d=self::visible(array_merge($d,$labels));
  $d['details']=decode($d['details_json']);
  $d['comments']=DB::all('SELECT c.*,u.name AS author_name FROM comments c JOIN users u ON u.id=c.author_id WHERE demand_id=?'.($client?' AND internal=0':'').' ORDER BY c.id',[$id]);
  $d['files']=DB::all('SELECT f.*,u.name AS author_name FROM files f JOIN users u ON u.id=f.author_id WHERE demand_id=?'.($client?' AND shared=1':'').' ORDER BY f.id DESC',[$id]);
  foreach($d['files'] as &$f){
   unset($f['disk_name']);$f['download_url']=$f['url']?:base('download.php?id='.$f['id']);$mime=strtolower((string)($f['mime']??''));
   $inline=str_starts_with($mime,'image/')||str_starts_with($mime,'video/')||str_starts_with($mime,'audio/')||str_starts_with($mime,'text/')||$mime==='application/pdf';
   $f['preview_url']=$inline&&!$f['url']?base('download.php?id='.$f['id'].'&inline=1'):null;$f['view_url']=$f['url']?:($f['drive_web_view_url']?:$f['preview_url']);$f['is_drive']=($f['storage_provider']??'local')==='drive';
  }
  unset($f);
  $d['final_delivery_link']=null;foreach($d['files'] as $fileRow)if(($fileRow['kind']??'')==='final'&&($fileRow['storage_provider']??'')==='drive'){$d['final_delivery_link']=Google::finalDeliveryShareLink($d);break;}
  $d['checklist']=DB::all('SELECT * FROM checklists WHERE demand_id=? ORDER BY id',[$id]);
  $d['changes']=DB::all('SELECT r.*,u.name AS author_name FROM change_requests r JOIN users u ON u.id=r.user_id WHERE demand_id=? ORDER BY r.id DESC',[$id]);
  if(!$client){
   $d['time']=DB::all('SELECT t.*,u.name AS user_name FROM time_entries t JOIN users u ON u.id=t.user_id WHERE demand_id=? ORDER BY t.id DESC',[$id]);
   $d['history']=DB::all("SELECT a.*,u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE entity='demand' AND entity_id=? ORDER BY a.id DESC LIMIT 100",[$id]);
  }
  if(Auth::$user['role']==='admin')$d['costs']=DB::all('SELECT * FROM costs WHERE demand_id=? ORDER BY id DESC',[$id]);
  $timeline=[];
  foreach($d['comments'] as $x)$timeline[]=['at'=>$x['created_at'],'type'=>'comment','label'=>'Comentário','user_name'=>$x['author_name'],'detail'=>mb_substr((string)$x['body'],0,300)];
  foreach($d['files'] as $x)$timeline[]=['at'=>$x['created_at'],'type'=>'file','label'=>($x['kind']??'')==='final'?'Entrega final enviada':'Arquivo enviado','user_name'=>$x['author_name'],'detail'=>$x['original_name']??$x['label']??'Arquivo'];
  foreach(DB::all('SELECT t.happened_at,t.from_stage,t.to_stage,u.name AS user_name,s1.name AS from_name,s2.name AS to_name FROM transitions t LEFT JOIN users u ON u.id=t.actor_id LEFT JOIN stages s1 ON s1.id=t.from_stage LEFT JOIN stages s2 ON s2.id=t.to_stage WHERE t.demand_id=? ORDER BY t.id',[$id]) as $x)$timeline[]=['at'=>$x['happened_at'],'type'=>'stage','label'=>'Etapa alterada','user_name'=>$x['user_name'],'detail'=>trim(($x['from_name']?:'Início').' → '.($x['to_name']?:'Etapa'))];
  if(!$client)foreach($d['history']??[] as $x)$timeline[]=['at'=>$x['created_at'],'type'=>'audit','label'=>$x['action'],'user_name'=>$x['user_name']??'Sistema','detail'=>''];
  usort($timeline,fn($a,$b)=>strcmp((string)$b['at'],(string)$a['at']));$d['timeline']=array_slice($timeline,0,150);
  return $d;
 }

 public static function save(array $p): array {
  return DB::tx(function() use($p): array {
   $id=(int)($p['id']??0);$old=$id?Auth::demand($id,true):null;$role=Auth::$user['role'];
   if($role==='staff')fail('Use o Kanban para atualizar as tasks atribuídas a você.',403);
   if($old&&integer($p['version']??0,1)!==(int)$old['version'])fail('A task foi alterada. Atualize antes de salvar.',409);

   $client=integer($p['client_id']??($old['client_id']??0),1);$clientRow=Auth::client($client);
   if($old&&$client!==(int)$old['client_id'])fail('O cliente de uma task existente não pode ser trocado.');
   $category=integer($p['category_id']??($old['category_id']??0),1);$categoryRow=DB::one('SELECT * FROM categories WHERE id=? AND active=1',[$category]);if(!$categoryRow)fail('Categoria inválida.');
   $project=$old['project_id']??null;if(isset($p['project_id']))$project=!empty($p['project_id'])?(int)$p['project_id']:null;if($project&&!DB::one('SELECT id FROM projects WHERE id=? AND client_id=?',[$project,$client]))fail('Projeto de outro cliente.');

   $dueInput=trim((string)($p['due_at']??($old['due_at']??'')));if($dueInput===''&&!$old)$dueInput=businessDeadline(max(1,(int)($categoryRow['default_due_hours']??24)))->format('Y-m-d\\TH:i');if($dueInput==='')fail('Informe a data de entrega.');$due=timestamp($dueInput);if(!$due)fail('Informe a data de entrega.');validateDelivery($due,$old['due_at']??null);
   $priority=enumv($p['priority']??($old['priority']??($categoryRow['default_priority']??'normal')),['low','normal','high','urgent'],'prioridade');
   if(!$old&&empty($p['allow_duplicate'])){$duplicate=DB::one('SELECT id,title FROM demands WHERE client_id=? AND category_id=? AND title=? AND due_at=? AND deleted_at IS NULL LIMIT 1',[$client,$category,trim((string)($p['title']??'')),$due]);if($duplicate)fail('Já existe uma task igual para este cliente, serviço e entrega.',409,['duplicate_id'=>(int)$duplicate['id']]);}
   $details=$old?decode($old['details_json']??'{}'):[];
   $row=[
    'client_id'=>$client,'project_id'=>$project,'category_id'=>$category,'title'=>required($p,'title',190),'brief'=>required($p,'brief',20000),
    'details_json'=>json($details),'due_at'=>$due,'priority'=>$priority,'draft'=>(int)(bool)($p['draft']??0),'updated_at'=>now()
   ];

   $firstStage=DB::one('SELECT * FROM stages WHERE active=1 ORDER BY position,id LIMIT 1');if(!$firstStage)fail('Crie ao menos uma coluna ativa no Kanban.');
   $stage=$old?DB::one('SELECT * FROM stages WHERE id=? AND active=1',[$old['stage_id']]):$firstStage;if(!$stage)fail('A etapa atual foi removida. Mova a task pelo Kanban.',409);
   $row['stage_id']=(int)$stage['id'];

   if($role==='admin'){
    $assigned=!empty($p['assigned_to'])?(int)$p['assigned_to']:(!$old&&!empty($categoryRow['default_assigned_to'])?(int)$categoryRow['default_assigned_to']:null);if($assigned&&!DB::one("SELECT id FROM users WHERE id=? AND active=1 AND role IN ('admin','staff')",[$assigned]))fail('Responsável inválido.');
    $clientType=($clientRow['commercial_type']??'one_off')==='monthly'?'monthly':'one_off';
    $mode=$clientType==='monthly'?enumv($p['billing_mode']??($old['billing_mode']??'included'),['included','one_off'],'modelo comercial'):'one_off';
    $amount=$mode==='included'?0:integer($p['amount_cents']??($old['amount_cents']??(!$old?(int)($categoryRow['default_amount_cents']??0):0)),0,1000000000);
    if($old&&((int)$old['amount_cents']!==$amount||(string)$old['billing_mode']!==$mode)){
     $paid=DB::one("SELECT COALESCE(SUM(p.amount_cents),0) AS paid_cents FROM invoices i LEFT JOIN payments p ON p.invoice_id=i.id WHERE i.demand_id=? AND i.status<>'cancelled'",[$id]);
     if((int)($paid['paid_cents']??0)!==0)fail('A task já possui pagamento registrado. Estorne o pagamento antes de alterar o valor.',409);
    }
    $row+=['assigned_to'=>$assigned,'billing_mode'=>$mode,'amount_cents'=>$amount,'commercial_status'=>enumv($p['commercial_status']??($old['commercial_status']??'pending'),['pending','approved','rejected'],'aprovação comercial'),'blocked_reason'=>$old['blocked_reason']??'','internal_notes'=>$old['internal_notes']??'','revisions_limit'=>(int)($old['revisions_limit']??($categoryRow['default_revisions']??2)),'estimated_minutes'=>(int)($old['estimated_minutes']??0)];
   }else{
    if($old){$row+=['assigned_to'=>$old['assigned_to'],'billing_mode'=>$old['billing_mode'],'amount_cents'=>$old['amount_cents'],'commercial_status'=>$old['commercial_status'],'blocked_reason'=>$old['blocked_reason'],'internal_notes'=>$old['internal_notes'],'revisions_limit'=>$old['revisions_limit'],'estimated_minutes'=>$old['estimated_minutes']];}
    else{$mode=($clientRow['commercial_type']??'one_off')==='monthly'?'included':'one_off';$row+=['assigned_to'=>!empty($categoryRow['default_assigned_to'])?(int)$categoryRow['default_assigned_to']:null,'billing_mode'=>$mode,'amount_cents'=>0,'commercial_status'=>'pending','blocked_reason'=>'','internal_notes'=>'','revisions_limit'=>(int)($categoryRow['default_revisions']??2),'estimated_minutes'=>0];}
   }

   $accepted=in_array($stage['kind'],['planned','production','approval','completed'],true);$row['accepted_at']=$old['accepted_at']??($accepted?now():null);
   $row['completed_at']=$stage['kind']==='completed'?($old['completed_at']??now()):null;$row['first_completed_at']=$old['first_completed_at']??$row['completed_at'];
   if($row['draft']&&$accepted)fail('Publique o rascunho antes de iniciar a produção.');

   if($old){$row['version']=(int)$old['version']+1;DB::update('demands',$id,$row);}else{$id=DB::insert('demands',$row+['created_by'=>Auth::$user['id'],'created_at'=>now()]);}
   $fresh=DB::one('SELECT d.*,s.kind AS stage_kind FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.id=?',[$id]);
   if(!$old)DB::insert('transitions',['demand_id'=>$id,'from_stage'=>null,'to_stage'=>$fresh['stage_id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]);
   audit('demand',$id,$old?'updated':'created',$old,$row);if(!$fresh['draft'])Notify::demand($fresh,$old?'Task atualizada':'Nova task',$fresh['title']);
   if(!$old){$labels=[];if(!empty($p['template_id']))$labels=decode(DB::one('SELECT checklist_json FROM templates WHERE id=?',[(int)$p['template_id']])['checklist_json']??'[]');elseif(!empty($categoryRow['checklist_json']))$labels=decode($categoryRow['checklist_json'],[]);foreach($labels as $label)if(trim((string)$label)!=='')DB::insert('checklists',['demand_id'=>$id,'label'=>mb_substr((string)$label,0,255),'done'=>0]);}
   return ['id'=>$id,'version'=>(int)$fresh['version'],'url'=>base('kanban/task/'.$id)];
  });
 }

 public static function stage(array $p): array {
  Auth::staff();$d=Auth::demand(integer($p['id']??0,1),true);if((int)$d['version']!==integer($p['version']??0,1))fail('O cartão mudou. Atualize e tente novamente.',409);
  $s=DB::one('SELECT * FROM stages WHERE id=? AND active=1',[integer($p['stage_id']??0,1)]);if(!$s)fail('Etapa inválida.');if($d['draft'])fail('Publique a task antes de mudar a etapa.');
  $row=['stage_id'=>$s['id'],'version'=>(int)$d['version']+1,'updated_at'=>now(),'completed_at'=>$s['kind']==='completed'?($d['completed_at']??now()):null];if($s['kind']==='completed')$row['first_completed_at']=$d['first_completed_at']??now();if(in_array($s['kind'],['planned','production','approval','completed']))$row['accepted_at']=$d['accepted_at']??now();if(isset($p['sort_order']))$row['sort_order']=integer($p['sort_order'],0,100000);
  DB::update('demands',$d['id'],$row);DB::insert('transitions',['demand_id'=>$d['id'],'from_stage'=>$d['stage_id'],'to_stage'=>$s['id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]);audit('demand',$d['id'],'stage',['stage_id'=>$d['stage_id']],$row);Notify::demand($d,'Etapa atualizada',$d['title'].' • '.$s['name']);return ['id'=>$d['id'],'version'=>$row['version']];
 }

 public static function reorder(array $p): array {
  Auth::load();
  return DB::tx(function() use($p): array {
   $d=Auth::demand(integer($p['id']??0,1),true);if((int)$d['version']!==integer($p['version']??0,1))fail('O cartão mudou. Atualize e tente novamente.',409);
   $stage=DB::one('SELECT * FROM stages WHERE id=? AND active=1',[integer($p['stage_id']??0,1)]);if(!$stage)fail('Etapa inválida.');if($d['draft'])fail('Publique a task antes de reorganizar o Kanban.');
   $beforeId=(int)($p['before_id']??0);if($beforeId===(int)$d['id'])$beforeId=0;
   $cards=DB::all('SELECT id FROM demands WHERE stage_id=? AND deleted_at IS NULL AND id<>? ORDER BY sort_order,updated_at DESC,id FOR UPDATE',[(int)$stage['id'],(int)$d['id']]);
   $ids=array_map(fn($r)=>(int)$r['id'],$cards);$insertAt=count($ids);
   if($beforeId){$idx=array_search($beforeId,$ids,true);if($idx===false)fail('A posição escolhida não está mais disponível. Atualize o Kanban.',409);$insertAt=$idx;}
   array_splice($ids,$insertAt,0,[(int)$d['id']]);
   foreach($ids as $i=>$cardId){if($cardId===(int)$d['id'])continue;DB::update('demands',$cardId,['sort_order'=>($i+1)*1000]);}
   $newSort=($insertAt+1)*1000;$moved=(int)$d['stage_id']!==(int)$stage['id'];
   if($moved){
    $row=['stage_id'=>(int)$stage['id'],'sort_order'=>$newSort,'version'=>(int)$d['version']+1,'updated_at'=>now(),'completed_at'=>$stage['kind']==='completed'?($d['completed_at']??now()):null];
    if($stage['kind']==='completed')$row['first_completed_at']=$d['first_completed_at']??now();if(in_array($stage['kind'],['planned','production','approval','completed'],true))$row['accepted_at']=$d['accepted_at']??now();
    DB::update('demands',$d['id'],$row);DB::insert('transitions',['demand_id'=>$d['id'],'from_stage'=>$d['stage_id'],'to_stage'=>$stage['id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]);audit('demand',$d['id'],'stage',['stage_id'=>$d['stage_id'],'sort_order'=>$d['sort_order']],$row);Notify::demand($d,'Etapa atualizada',$d['title'].' • '.$stage['name']);
    return ['id'=>(int)$d['id'],'version'=>(int)$row['version'],'stage_id'=>(int)$stage['id'],'sort_order'=>$newSort];
   }
   DB::update('demands',$d['id'],['sort_order'=>$newSort,'updated_at'=>now()]);audit('demand',$d['id'],'reordered',['sort_order'=>$d['sort_order']],['sort_order'=>$newSort]);
   return ['id'=>(int)$d['id'],'version'=>(int)$d['version'],'stage_id'=>(int)$stage['id'],'sort_order'=>$newSort];
  });
 }

 public static function bulk(array $p): array {
  Auth::requireAbility('tasks.edit');$ids=$p['ids']??[];if(!is_array($ids)||!$ids)fail('Selecione ao menos uma task.');$ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));if(count($ids)>100)fail('A ação em lote aceita até 100 tasks por vez.');
  $action=enumv((string)($p['bulk_action']??''),['stage','priority','assigned'],'ação em lote');$updated=0;
  foreach($ids as $id){$d=Auth::demand($id,true);$row=['updated_at'=>now(),'version'=>(int)$d['version']+1];
   if($action==='priority')$row['priority']=enumv((string)($p['priority']??''),['low','normal','high','urgent'],'prioridade');
   elseif($action==='assigned'){$assigned=!empty($p['assigned_to'])?(int)$p['assigned_to']:null;if($assigned&&!DB::one("SELECT id FROM users WHERE id=? AND active=1 AND role IN ('admin','staff')",[$assigned]))fail('Responsável inválido.');$row['assigned_to']=$assigned;}
   else{$stage=DB::one('SELECT * FROM stages WHERE id=? AND active=1',[integer($p['stage_id']??0,1)]);if(!$stage)fail('Etapa inválida.');$row['stage_id']=$stage['id'];$row['completed_at']=$stage['kind']==='completed'?($d['completed_at']??now()):null;if($stage['kind']==='completed')$row['first_completed_at']=$d['first_completed_at']??now();DB::insert('transitions',['demand_id'=>$id,'from_stage'=>$d['stage_id'],'to_stage'=>$stage['id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]);}
   DB::update('demands',$id,$row);audit('demand',$id,'bulk.'.$action,$d,$row);$fresh=DB::one('SELECT * FROM demands WHERE id=?',[$id])?:array_merge($d,$row);
   if(empty($fresh['draft'])){if($action==='assigned')Notify::demand($fresh,'Responsável atualizado',$fresh['title'],true);elseif($action==='stage')Notify::demand($fresh,'Etapa atualizada',$fresh['title'],true);elseif($action==='priority')Notify::demand($fresh,'Prioridade atualizada',$fresh['title'],true);}
   $updated++;
  }return ['updated'=>$updated];
 }
 public static function delete(array $p): array {Auth::admin();$d=Auth::demand(integer($p['id']??0,1),true);Notify::demand($d,'Task excluída',$d['title']);DB::update('demands',$d['id'],['deleted_at'=>now(),'version'=>(int)$d['version']+1,'updated_at'=>now()]);audit('demand',$d['id'],'deleted',$d,['deleted_at'=>now()]);return ['deleted'=>true,'id'=>$d['id']];}
 public static function comment(array $p): array {$d=Auth::demand(integer($p['demand_id']??0,1));$internal=Auth::$user['role']!=='client'&&(bool)($p['internal']??false);$version=!empty($p['version_id'])?(int)$p['version_id']:null;if($version&&!DB::one('SELECT id FROM files WHERE id=? AND demand_id=?'.(Auth::$user['role']==='client'?' AND shared=1':''),[$version,$d['id']]))fail('Versão inválida.');$body=required($p,'body',10000);$id=DB::insert('comments',['demand_id'=>$d['id'],'author_id'=>Auth::$user['id'],'body'=>$body,'internal'=>(int)$internal,'version_id'=>$version,'created_at'=>now()]);Notify::demand($d,'Novo comentário',mb_substr($body,0,250),$internal);return ['id'=>$id];}
 public static function checklist(array $p): array {Auth::staff();$d=Auth::demand(integer($p['demand_id'],1));$id=(int)($p['id']??0);if($id){if(!DB::one('SELECT id FROM checklists WHERE id=? AND demand_id=?',[$id,$d['id']]))fail('Item inválido.',404);DB::update('checklists',$id,['done'=>(int)(bool)$p['done']]);}else $id=DB::insert('checklists',['demand_id'=>$d['id'],'label'=>required($p,'label',255),'done'=>0]);return ['id'=>$id];}
 public static function time(array $p): array {Auth::staff();$d=Auth::demand(integer($p['demand_id'],1));return ['id'=>DB::insert('time_entries',['demand_id'=>$d['id'],'user_id'=>Auth::$user['id'],'minutes'=>integer($p['minutes']??0,1,1440),'description'=>mb_substr($p['description']??'',0,255),'worked_on'=>datev($p['worked_on']??'',false),'created_at'=>now()])];}
 public static function change(array $p): array {$d=Auth::demand(integer($p['demand_id'],1));if(!empty($p['id'])){Auth::admin();if(!DB::one('SELECT id FROM change_requests WHERE id=? AND demand_id=?',[$p['id'],$d['id']]))fail('Solicitação inválida.');DB::update('change_requests',(int)$p['id'],['status'=>enumv($p['status'],['accepted','rejected'],'situação'),'response'=>$p['response']??'']);$id=(int)$p['id'];}else $id=DB::insert('change_requests',['demand_id'=>$d['id'],'user_id'=>Auth::$user['id'],'description'=>required($p,'description'),'created_at'=>now()]);Notify::demand($d,'Alteração de escopo',$d['title']);return ['id'=>$id];}
 public static function quote(array $p): array {$d=Auth::demand(integer($p['id'],1),true);Auth::client((int)$d['client_id'],'approve');if($d['billing_mode']!=='one_off')fail('Esta task está incluída no pacote mensal.');$decision=enumv($p['decision']??'',['approved','rejected'],'decisão');DB::update('demands',$d['id'],['commercial_status'=>$decision,'version'=>$d['version']+1,'updated_at'=>now()]);audit('demand',$d['id'],'quote.'.$decision);Notify::demand($d,'Resposta ao orçamento',$d['title']);return ['saved'=>true];}
}
