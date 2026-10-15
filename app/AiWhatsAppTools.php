<?php
declare(strict_types=1);

/** An LLM can propose only these four operations; the server authorizes each one. */
final class AiWhatsAppTools
{
 public static function catalog(bool $privateChat=false): array {
  $tools=[
   ['type'=>'function','function'=>['name'=>'list_tasks','description'=>'Listar tarefas visíveis ao usuário no TaskEsc.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Busca por título ou cliente (opcional)'],'client_id'=>['type'=>'integer','description'=>'ID do cliente, se fornecido'],'stage_id'=>['type'=>'integer','description'=>'ID da etapa do Kanban, se fornecido'],'stage_name'=>['type'=>'string','description'=>'Nome exato da etapa do Kanban, se fornecido; não combine com stage_id'],'show_other_stages'=>['type'=>'boolean','description'=>'Ao consultar uma etapa, se não houver resultados, mostrar tarefas correspondentes de outras etapas sem inventar status'],'overdue'=>['type'=>'boolean','description'=>'Filtrar apenas tarefas atrasadas e ainda abertas'],'limit'=>['type'=>'integer','description'=>'Mostrar até 10 tasks (opcional)']], 'additionalProperties'=>false]]]
  ];
  if(Auth::can('tasks.read'))$tools[]=['type'=>'function','function'=>[
   'name'=>'attention_report',
   'description'=>'Analisar quais demandas, projetos e clientes exigem atenção: prazos vencidos, próximos sete dias, prioridades alta/urgente e andamento real. Resumo somente leitura com dados atuais; usar quando o usuário pedir análise, não lista simples.',
   'parameters'=>['type'=>'object','properties'=>[],'additionalProperties'=>false]
  ]];
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
  // Financial details may only be sent to the verified ADM in a PRIVATE chat.
  // In groups, everyone can read the response; never expose finance tools there.
  if($privateChat&&Auth::can('finance.read')&&(Auth::$user['role']??'')==='admin'){
   $range=['from'=>['type'=>'string','description'=>'Data inicial YYYY-MM-DD, opcional; padrão início do mês'],
           'to'=>['type'=>'string','description'=>'Data final YYYY-MM-DD, opcional; padrão fim do mês']];
   $tools[]=['type'=>'function','function'=>['name'=>'finance_summary','description'=>'Consultar resumo financeiro da empresa no período: faturamento, recebidos, custos, lucro de caixa, contas a receber e vencidas. Apenas ADM em conversa privada. Nenhum lançamento é criado.','parameters'=>['type'=>'object','properties'=>$range,'additionalProperties'=>false]]];
   $tools[]=['type'=>'function','function'=>['name'=>'finance_receivables','description'=>'Consultar quanto falta RECEBER de clientes, cobranças pendentes e vencidas. scope=month usa o mês atual; scope=all mostra todas as cobranças registradas. Não registra pagamentos. Apenas ADM em conversa privada.','parameters'=>['type'=>'object','properties'=>[
     'scope'=>['type'=>'string','enum'=>['month','all']],
     'from'=>$range['from'],'to'=>$range['to'],
     'client_id'=>['type'=>'integer','description'=>'Cliente específico, somente ID existente'],
     'limit'=>['type'=>'integer','description'=>'Até 10 cobranças detalhadas']
    ],'additionalProperties'=>false]]];
   $tools[]=['type'=>'function','function'=>['name'=>'finance_expenses','description'=>'Consultar despesas/custos pagos, pendentes ou todos no período, incluindo TOTAL EM REAIS calculado pelo TaskEsc, não apenas a lista. Para "total de despesas pendentes" use payment_status=pending; para somatório geral use all. Apenas ADM no privado; não altera lançamentos.','parameters'=>['type'=>'object','properties'=>[
     'from'=>$range['from'],'to'=>$range['to'],
     'payment_status'=>['type'=>'string','enum'=>['all','pending','paid'],
       'description'=>'Filtrar por despesas pendentes, pagas ou todas; padrão all'],
     'limit'=>['type'=>'integer','description'=>'Exibir até 10 detalhes; o total considera TODOS os registros']
    ],'additionalProperties'=>false]]];
  }
  return array_merge($tools,AiWhatsAppCrossModuleReport::catalog($privateChat));
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
   case 'attention_report':return AiWhatsAppAttentionReport::run($args);
   case 'finance_summary': return TaskEscFinanceReadApi::summary($args);
   case 'finance_receivables': return TaskEscFinanceReadApi::receivables($args);
   case 'finance_expenses': return TaskEscFinanceReadApi::expenses($args);
   case 'list_tasks':
    Auth::requireAbility('tasks.read');
    $query=$args['query']??'';
    if(!is_string($query)||mb_strlen($query)>90)fail('Busca inválida.',422);
    $limit=integer($args['limit']??10,1,10);
    $params=['paginate'=>1,'per_page'=>10,'q'=>$query];
    if(array_key_exists('overdue',$args)){
     if(!is_bool($args['overdue']))fail('Filtro de atraso inválido.',422);
     if($args['overdue'])$params['overdue']=1;
    }
    if(array_key_exists('client_id',$args))$params['client_id']=integer($args['client_id'],1);
    if(isset($args['stage_id'])&&isset($args['stage_name']))fail('Selecione etapa por ID ou por nome.',422);
    if(array_key_exists('stage_id',$args))$params['stage_id']=integer($args['stage_id'],1);
    if(array_key_exists('stage_name',$args)){
     $stageName=required($args,'stage_name',100);
     $stage=DB::one('SELECT id FROM stages WHERE name=? AND active=1 ORDER BY id LIMIT 1',[$stageName]);
     if(!$stage)fail('Não encontrei essa etapa do Kanban. Informe o nome exato.',422);
     $params['stage_id']=(int)$stage['id'];
    }
    $otherStages=$args['show_other_stages']??false;
    if(!is_bool($otherStages))fail('Filtro de outras etapas inválido.',422);
    if($otherStages&&!isset($params['stage_id']))fail('Informe uma etapa para consultar alternativas.',422);
    $data=TaskEscTaskApi::list($params);
    $tasks=array_map(static fn($d)=>[
      'id'=>(int)$d['id'],'title'=>$d['title'],'client'=>$d['client_name'],'stage'=>$d['stage_name'],'due_at'=>$d['due_at']
    ],array_slice($data['rows'],0,$limit));
    $result=['kind'=>'list','total'=>(int)$data['pagination']['total'],'tasks'=>$tasks];
    if($otherStages&&$result['total']===0){
     // Do not misrepresent a stage-filtered empty result as no tasks in the system.
     // Reuse the same authorized TaskEscTaskApi gateway, now without the stage filter.
     $otherParams=$params;unset($otherParams['stage_id']);
     $alternatives=TaskEscTaskApi::list($otherParams);
     $result['searched_stage']=$stageName??(string)(DB::one('SELECT name FROM stages WHERE id=?',[$params['stage_id']])['name']??'etapa solicitada');
     $result['alternative_total']=(int)$alternatives['pagination']['total'];
     $result['alternatives']=array_map(static fn($d)=>[
       'id'=>(int)$d['id'],'title'=>$d['title'],'stage'=>$d['stage_name']
     ],array_slice($alternatives['rows'],0,$limit));
    }
    return $result;
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
