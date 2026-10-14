<?php
declare(strict_types=1);

/** An LLM can propose only these three operations; the server authorizes each one. */
final class AiWhatsAppTools
{
 public static function catalog(): array {
  $tools=[
   ['type'=>'function','function'=>['name'=>'list_tasks','description'=>'Listar tarefas visíveis ao usuário no TaskEsc.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Busca por título ou cliente (opcional)'],'client_id'=>['type'=>'integer','description'=>'ID do cliente, se fornecido']], 'additionalProperties'=>false]]]
  ];
  if(Auth::can('tasks.create'))$tools[]=['type'=>'function','function'=>['name'=>'create_task','description'=>'Criar uma task nova somente em cliente acessível; exige título, briefing, cliente e categoria. Prazo usa padrão mínimo do sistema se omitido.','parameters'=>['type'=>'object','properties'=>[
   'title'=>['type'=>'string'],'brief'=>['type'=>'string'],'client_id'=>['type'=>'integer'],
   'category_id'=>['type'=>'integer'],'assigned_to'=>['type'=>'integer'],
   'due_at'=>['type'=>'string','description'=>'Data e hora local YYYY-MM-DDTHH:mm; não invente prazo'],
   'priority'=>['type'=>'string','enum'=>['low','normal','high','urgent']]
  ],'required'=>['title','brief','client_id','category_id'],'additionalProperties'=>false]]];
  if(Auth::can('tasks.move')&&Auth::$user['role']!=='client')$tools[]=['type'=>'function','function'=>['name'=>'move_task','description'=>'Mover task existente para uma etapa do Kanban.','parameters'=>['type'=>'object','properties'=>['task_id'=>['type'=>'integer'],'stage_id'=>['type'=>'integer']], 'required'=>['task_id','stage_id'],'additionalProperties'=>false]]];
  return $tools;
 }

 /** Only metadata that this user may see; never prompt with finance or unrelated tenants. */
 public static function context(): array {
  [$where,$params]=Auth::clientFilter('c.id');
  $clients=DB::all('SELECT c.id,c.name FROM clients c WHERE c.active=1 AND '.$where.' ORDER BY c.name LIMIT 40',$params);
  $categories=DB::all('SELECT id,name,default_assigned_to FROM categories WHERE active=1 ORDER BY name LIMIT 40');
  $stages=DB::all('SELECT id,name,kind FROM stages WHERE active=1 ORDER BY position,id LIMIT 30');
  return ['clients'=>$clients,'categories'=>$categories,'stages'=>$stages,'timezone'=>(string)config('timezone','America/Fortaleza')];
 }

 public static function run(string $name,array $args): array {
  switch($name){
   case 'list_tasks':
    Auth::requireAbility('tasks.read');
    $query=$args['query']??'';
    if(!is_string($query)||mb_strlen($query)>90)fail('Busca inválida.',422);
    $params=['paginate'=>1,'per_page'=>10,'q'=>$query];
    if(array_key_exists('client_id',$args))$params['client_id']=integer($args['client_id'],1);
    $data=Demands::list($params);
    return ['kind'=>'list','total'=>(int)$data['pagination']['total'],'tasks'=>array_map(static fn($d)=>[
      'id'=>(int)$d['id'],'title'=>$d['title'],'client'=>$d['client_name'],'stage'=>$d['stage_name'],'due_at'=>$d['due_at']
    ],$data['rows'])];
   case 'create_task':
    Auth::requireAbility('tasks.create');
    foreach(['title','brief','client_id','category_id'] as $key)if(!array_key_exists($key,$args))fail('Dados insuficientes para criar task.',422);
    $input=['client_id'=>integer($args['client_id'],1),'category_id'=>integer($args['category_id'],1),
      'title'=>required($args,'title',190),'brief'=>required($args,'brief',3000),'priority'=>enumv($args['priority']??'normal',['low','normal','high','urgent'],'prioridade')];
    // No finance, arbitrary WhatsApp recipient, deletions, bulk actions, or changes to existing tasks.
    if(isset($args['assigned_to']))$input['assigned_to']=integer($args['assigned_to'],1);
    if(isset($args['due_at'])){$dt=(string)$args['due_at'];if(!preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}\\z/D',$dt))fail('Data inválida.',422);$input['due_at']=$dt;}
    $result=Demands::save($input);
    return ['kind'=>'created','id'=>(int)$result['id'],'url'=>$result['url']];
   case 'move_task':
    Auth::requireAbility('tasks.move');
    Auth::staff(); // matches the existing Kanban stage endpoint for client users
    $id=integer($args['task_id']??null,1);$stageId=integer($args['stage_id']??null,1);
    $d=Auth::demand($id);
    $result=Demands::stage(['id'=>$id,'stage_id'=>$stageId,'version'=>(int)$d['version']]);
    $stage=DB::one('SELECT name FROM stages WHERE id=?',[$stageId]);
    return ['kind'=>'moved','id'=>$id,'stage'=>$stage['name']??'','version'=>$result['version']];
  }
  fail('Ferramenta indisponível.',403);
 }
}
