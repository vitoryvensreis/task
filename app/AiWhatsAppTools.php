<?php
declare(strict_types=1);

/** An LLM can propose only these four operations; the server authorizes each one. */
final class AiWhatsAppTools
{
 public static function catalog(): array {
  $tools=[
   ['type'=>'function','function'=>['name'=>'list_tasks','description'=>'Listar tarefas visíveis ao usuário no TaskEsc.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Busca por título ou cliente (opcional)'],'client_id'=>['type'=>'integer','description'=>'ID do cliente, se fornecido']], 'additionalProperties'=>false]]]
  ];
  if(Auth::can('tasks.create'))$tools[]=['type'=>'function','function'=>['name'=>'create_task','description'=>'Criar uma task nova somente em cliente acessível; exige título, briefing, cliente e categoria. Prazo usa padrão mínimo do sistema se omitido.','parameters'=>['type'=>'object','properties'=>[
   'title'=>['type'=>'string'],'brief'=>['type'=>'string'],'client_id'=>['type'=>'integer'],
   'category_id'=>['type'=>'integer'],'assigned_to'=>['type'=>'integer','description'=>'ID de um responsável válido; não invente.'],'assign_to_me'=>['type'=>'boolean','description'=>'Use true quando o autor pedir "eu mesmo", "para mim" ou seu próprio nome e for da equipe. Não combine com assigned_to.'], 
   'due_at'=>['type'=>'string','description'=>'Data e hora local YYYY-MM-DDTHH:mm; não invente prazo'],
   'priority'=>['type'=>'string','enum'=>['low','normal','high','urgent']]
  ],'required'=>['title','brief','client_id','category_id'],'additionalProperties'=>false]]];
  if(Auth::can('tasks.edit'))$tools[]=['type'=>'function','function'=>['name'=>'edit_task','description'=>'Editar SOMENTE título, briefing, prioridade ou prazo de uma task existente. Exige task_id inequívoco e ao menos um campo a alterar; não modifica valores, clientes, responsáveis, status, disparos ou acessos.','parameters'=>['type'=>'object','properties'=>[
   'task_id'=>['type'=>'integer'],'title'=>['type'=>'string'],'brief'=>['type'=>'string'],
   'due_at'=>['type'=>'string','description'=>'Novo prazo local exato YYYY-MM-DDTHH:mm; não invente data'],
   'priority'=>['type'=>'string','enum'=>['low','normal','high','urgent']]
  ],'required'=>['task_id'],'additionalProperties'=>false]]];
  if(Auth::can('tasks.move')&&Auth::$user['role']!=='client')$tools[]=['type'=>'function','function'=>['name'=>'move_task','description'=>'Mover task existente para uma etapa do Kanban.','parameters'=>['type'=>'object','properties'=>['task_id'=>['type'=>'integer'],'stage_id'=>['type'=>'integer']], 'required'=>['task_id','stage_id'],'additionalProperties'=>false]]];
  return $tools;
 }

 /** Only metadata that this user may see; never prompt with finance or unrelated tenants. */
 public static function context(): array {
  [$where,$params]=Auth::clientFilter('c.id');
  $clients=DB::all('SELECT c.id,c.name FROM clients c WHERE c.active=1 AND '.$where.' ORDER BY c.name LIMIT 40',$params);
  $categories=DB::all('SELECT id,name,default_assigned_to FROM categories WHERE active=1 ORDER BY name LIMIT 40');
  $stages=DB::all('SELECT id,name,kind FROM stages WHERE active=1 ORDER BY position,id LIMIT 30');
  $actor=Auth::$user??[];
  $selfRole=(string)($actor['role']??'');
  $self=in_array($selfRole,['admin','staff'],true)?['id'=>(int)($actor['id']??0),'name'=>(string)($actor['name']??''),'role'=>$selfRole]:null;
  return ['clients'=>$clients,'categories'=>$categories,'stages'=>$stages,'current_user'=>$self,'timezone'=>(string)config('timezone','America/Fortaleza')];
 }

 public static function run(string $name,array $args): array {
  switch($name){
   case 'list_tasks':
    Auth::requireAbility('tasks.read');
    $query=$args['query']??'';
    if(!is_string($query)||mb_strlen($query)>90)fail('Busca inválida.',422);
    $params=['paginate'=>1,'per_page'=>10,'q'=>$query];
    if(array_key_exists('client_id',$args))$params['client_id']=integer($args['client_id'],1);
    $data=TaskEscTaskApi::list($params);
    return ['kind'=>'list','total'=>(int)$data['pagination']['total'],'tasks'=>array_map(static fn($d)=>[
      'id'=>(int)$d['id'],'title'=>$d['title'],'client'=>$d['client_name'],'stage'=>$d['stage_name'],'due_at'=>$d['due_at']
    ],$data['rows'])];
   case 'create_task':
    Auth::requireAbility('tasks.create');
    foreach(['title','brief','client_id','category_id'] as $key)if(!array_key_exists($key,$args))fail('Dados insuficientes para criar task.',422);
    $input=['client_id'=>integer($args['client_id'],1),'category_id'=>integer($args['category_id'],1),
      'title'=>required($args,'title',190),'brief'=>required($args,'brief',3000),'priority'=>enumv($args['priority']??'normal',['low','normal','high','urgent'],'prioridade'),
      'amount_cents'=>0,'billing_mode'=>'included'];
    // Only the authenticated actor can represent "me". The model cannot choose an arbitrary identity.
    $toMe=$args['assign_to_me']??false;
    if(!is_bool($toMe))fail('Indicação do próprio responsável inválida.',422);
    if($toMe&&array_key_exists('assigned_to',$args))fail('Informe apenas uma opção de responsável.',422);
    if($toMe){
     if(!in_array((string)(Auth::$user['role']??''),['admin','staff'],true))fail('O responsável deve ser alguém da equipe.',422);
     $input['assigned_to']=(int)Auth::$user['id'];
    }elseif(array_key_exists('assigned_to',$args)){
     $input['assigned_to']=integer($args['assigned_to'],1);
    }else{
     // Preserve the category's designated default. With no default, safely use the authenticated staff/ADM.
     $category=DB::one('SELECT default_assigned_to FROM categories WHERE id=? AND active=1',[$input['category_id']]);
     if(!$category)fail('Categoria inválida.',422);
     if((int)($category['default_assigned_to']??0)<1&&in_array((string)(Auth::$user['role']??''),['admin','staff'],true)){
      $input['assigned_to']=(int)Auth::$user['id'];
     }
    }
    // All assignments still pass Demands::responsible, which checks active staff and client links.
    if(isset($args['due_at'])){$dt=(string)$args['due_at'];if(!preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}\\z/D',$dt))fail('Data inválida.',422);$input['due_at']=$dt;}
    $result=TaskEscTaskApi::create($input);
    return ['kind'=>'created','id'=>(int)$result['id'],'url'=>$result['url']];
   case 'edit_task':
    Auth::requireAbility('tasks.edit');
    // The model cannot smuggle administrative fields through Demands::save.
    $allowed=['task_id','title','brief','priority','due_at'];
    foreach(array_keys($args) as $key)if(!in_array($key,$allowed,true))fail('Campo não permitido para edição via WhatsApp.',422);
    $changes=array_diff(array_keys($args),['task_id']);
    if(!$changes)fail('Informe o que deseja alterar na task.',422);
    $id=integer($args['task_id']??null,1);
    return DB::tx(static function() use($id,$args,$changes): array {
     $old=Auth::demand($id,true); // Confere autorização e versão sob lock.
     $tz=new DateTimeZone((string)config('timezone','America/Fortaleza'));
     $oldDue=(new DateTimeImmutable((string)$old['due_at'],new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d\TH:i:s');
     $input=[
      'id'=>$id,'version'=>(int)$old['version'],
      'client_id'=>(int)$old['client_id'],'category_id'=>(int)$old['category_id'],
      'draft'=>(int)$old['draft'], // Never publish an existing draft implicitly.
      'title'=>(string)$old['title'],'brief'=>(string)$old['brief'],
      'due_at'=>$oldDue,'priority'=>(string)$old['priority']
     ];
     if(array_key_exists('title',$args))$input['title']=required($args,'title',190);
     if(array_key_exists('brief',$args))$input['brief']=required($args,'brief',3000);
     if(array_key_exists('priority',$args))$input['priority']=enumv($args['priority'],['low','normal','high','urgent'],'prioridade');
     if(array_key_exists('due_at',$args)){
      $due=$args['due_at'];
      if(!is_string($due)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$due))fail('Data inválida. Use AAAA-MM-DDTHH:mm.',422);
      $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$due,$tz);
      if(!$parsed||$parsed->format('Y-m-d\TH:i')!==$due)fail('Data de entrega inválida.',422);
      $input['due_at']=$due;
     }
     // Preserve financial snapshot exactly; changes to billing are never authorized here.
     $before=[(string)$old['billing_mode'],(int)$old['amount_cents'],(string)$old['commercial_status']];
     $result=TaskEscTaskApi::update($input);
     $after=DB::one('SELECT billing_mode,amount_cents,commercial_status,draft,client_id,category_id,assigned_to,project_id,stage_id FROM demands WHERE id=?',[$id]);
     if(!$after||$before!==[(string)$after['billing_mode'],(int)$after['amount_cents'],(string)$after['commercial_status']]
      ||(int)$after['draft']!==(int)$old['draft']||(int)$after['client_id']!==(int)$old['client_id']
      ||(int)$after['category_id']!==(int)$old['category_id']||(int)($after['assigned_to']??0)!==(int)($old['assigned_to']??0)
      ||(int)($after['project_id']??0)!==(int)($old['project_id']??0)
      ||(int)$after['stage_id']!==(int)$old['stage_id'])fail('A edição envolveria campos não autorizados; nenhuma alteração foi salva.',409);
     return ['kind'=>'edited','id'=>$id,'version'=>(int)$result['version'],'fields'=>array_values($changes)];
    });
   case 'move_task':
    Auth::requireAbility('tasks.move');
    Auth::staff(); // matches the existing Kanban stage endpoint for client users
    $id=integer($args['task_id']??null,1);$stageId=integer($args['stage_id']??null,1);
    $d=Auth::demand($id);
    $result=TaskEscTaskApi::move(['id'=>$id,'stage_id'=>$stageId,'version'=>(int)$d['version']]);
    $stage=DB::one('SELECT name FROM stages WHERE id=?',[$stageId]);
    return ['kind'=>'moved','id'=>$id,'stage'=>$stage['name']??'','version'=>$result['version']];
  }
  fail('Ferramenta indisponível.',403);
 }
}
