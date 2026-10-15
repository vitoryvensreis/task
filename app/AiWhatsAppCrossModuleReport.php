<?php
declare(strict_types=1);

/**
 * Combined read-only TaskEsc snapshot.
 * All business data is fetched through existing authenticated domain gateways.
 * No model-generated SQL, no cross-tenant reads, no financial data in groups.
 */
final class AiWhatsAppCrossModuleReport
{
 private const MAX_TASKS=100;
 private const PERIODS=['today','tomorrow','week','month'];
 private const SECTIONS=['tasks','projects','agenda','finance'];

 public static function catalog(bool $private): array {
  if(!$private||(Auth::$user['role']??'')!=='admin'||!Auth::can('tasks.read'))return [];
  return [['type'=>'function','function'=>[
   'name'=>'cross_module_report',
   'description'=>'RESUMO COMBINADO somente leitura de 2 a 4 módulos na mesma resposta: tarefas, projetos ligados a demandas visíveis, agenda e/ou financeiro. Para perguntas que relacionem dois ou mais temas. Informe true somente para módulos SOLICITADOS; NUNCA inclua financeiro sem pedido explícito. O período hoje/amanhã/7 dias/mês filtra tarefas por prazo, agenda por data e financeiro por lançamentos. Apenas ADM no privado, dados reais, sem escrita.',
   'parameters'=>['type'=>'object','properties'=>[
    'tasks'=>['type'=>'boolean','description'=>'Consultar tarefas com prazo no período'],
    'projects'=>['type'=>'boolean','description'=>'Agrupar projetos com tarefas visíveis no período; não é catálogo completo de projetos'],
    'agenda'=>['type'=>'boolean','description'=>'Consultar compromissos das agendas selecionadas no período'],
    'finance'=>['type'=>'boolean','description'=>'Consultar indicadores financeiros da empresa somente se o usuário pedir explicitamente'],
    'period'=>['type'=>'string','enum'=>self::PERIODS,'description'=>'today, tomorrow, week (hoje + 6 dias), month (mês atual)'],
    'task_query'=>['type'=>'string','description'=>'Busca opcional por título, cliente, categoria ou responsável, somente em tarefas'],
    'finance_view'=>['type'=>'string','enum'=>['summary','pending_expenses','receivables'],
      'description'=>'summary: resumo geral; pending_expenses: só despesas pendentes; receivables: contas a receber.']
   ],'additionalProperties'=>false]
  ]]];
 }

 /**
  * Conservative no-LLM planner for complete, explicit requests for a combined
  * summary. Unsupported grammar or extra verbs always falls back to the model.
  */
 public static function fastPlan(string $message,bool $private): ?array {
  if(!$private||mb_strlen($message,'UTF-8')>210||(Auth::$user['role']??'')!=='admin')return null;
  $t=AiWhatsAppFinancialConversation::normalize($message);
  if(!preg_match('/\A(?:(?:me\s+)?(?:faz|faca|mostre|mostra|mande|manda|gere|gera|quero|da)\s+)?(?:um\s+)?(?:resumo|panorama)\s+(?:de|das|dos)\s+(.+)\z/u',$t,$m))return null;
  $subject=str_replace([',','/'], ' ',$m[1]);
  $tokens=preg_split('/\s+/u',trim($subject));
  $vocabulary=['de','das','dos','do','da','as','os','meus','minhas','minha','meu','e','com','a','o',
   'tarefas','tasks','projetos','agenda','compromissos','eventos','financeiro','financeiras','financas',
   'despesas','custos','contas','receber','recebimentos','faturamento','cobrancas','hoje','amanha',
   'neste','este','esse','deste','desse','nesta','mes','desta','nessa','semana','pendentes'];
  if(!$tokens||array_diff($tokens,$vocabulary))return null;
  $args=[
   'tasks'=>(bool)preg_match('/\b(?:tarefas|tasks)\b/u',$t),
   'projects'=>(bool)preg_match('/\bprojetos\b/u',$t),
   'agenda'=>(bool)preg_match('/\b(?:agenda|compromissos|eventos)\b/u',$t),
   'finance'=>self::explicitlyFinancial($message),
   'period'=>'week'
  ];
  if(preg_match('/\bamanha\b/u',$t))$args['period']='tomorrow';
  elseif(preg_match('/\bhoje\b/u',$t))$args['period']='today';
  elseif(preg_match('/\bmes\b/u',$t))$args['period']='month';
  if($args['finance']){
   if(preg_match('/\b(?:despesas|custos)\b/u',$t)&&preg_match('/\bpendentes\b/u',$t))
    $args['finance_view']='pending_expenses';
   elseif(preg_match('/\b(?:contas\s+a\s+receber|cobrancas)\b/u',$t))
    $args['finance_view']='receivables';
  }
  if(array_sum(array_map(static fn($v)=>(int)$v,[$args['tasks'],$args['projects'],$args['agenda'],$args['finance']]))<2)return null;
  return ['tool'=>'cross_module_report','arguments'=>$args];
 }

 public static function normalize(array $args): array {
  foreach(array_keys($args) as $key)
   if(!in_array($key,[...self::SECTIONS,'period','task_query','finance_view'],true))fail('Filtro do resumo combinado não permitido.',422);
  $out=[];
  foreach(self::SECTIONS as $key){
   if(isset($args[$key])&&!is_bool($args[$key]))fail('Seleção de módulo inválida.',422);
   $out[$key]=$args[$key]??false;
  }
  if(array_sum(array_map(static fn($v)=>(int)$v,array_values($out)))<2)fail('Escolha pelo menos dois módulos para combinar.',422);
  $out['period']=enumv($args['period']??'week',self::PERIODS,'período do resumo');
  $query=$args['task_query']??'';
  if(!is_string($query)||mb_strlen($query,'UTF-8')>70)fail('Busca de tarefas inválida.',422);
  if($query!==''&&preg_match('/[\r\n\x00-\x1F]/u',$query))fail('Busca de tarefas inválida.',422);
  $out['task_query']=trim($query);
  $out['finance_view']=enumv($args['finance_view']??'summary',['summary','pending_expenses','receivables'],'visão financeira');
  if(!$out['finance']&&isset($args['finance_view']))fail('Não solicite uma visão financeira sem incluir o módulo financeiro.',422);
  return $out;
 }

 public static function explicitlyFinancial(string $message): bool {
  $t=AiWhatsAppFinancialConversation::normalize($message);
  // Negative financial requests cannot result in a private financial section.
  if(preg_match('/\b(?:sem|nao\s+(?:quero|inclua|mostre|preciso\s+de))\s+(?:(?:o|os|as|de|meu|minhas)\s+)?(?:financeiro|financas|despesas|custos|recebimentos|cobrancas)\b/u',$t))return false;
  return (bool)preg_match('/\b(?:financeir[oa]s?|financas|despesas|custos|a\s+receber|recebimentos|faturamento|cobrancas|lucro)\b/u',$t);
 }

 public static function dates(string $period,?DateTimeImmutable $now=null): array {
  $zone=new DateTimeZone((string)config('timezone','America/Fortaleza'));
  $today=($now??new DateTimeImmutable('now',$zone))->setTimezone($zone)->setTime(0,0);
  $from=match($period){
   'tomorrow'=>$today->modify('+1 day'),
   'month'=>$today->modify('first day of this month'),
   default=>$today
  };
  $to=match($period){
   'today','tomorrow'=>$from,
   'week'=>$today->modify('+6 days'),
   'month'=>$today->modify('last day of this month'),
   default=>throw new InvalidArgumentException('period')
  };
  return [$from->format('Y-m-d'),$to->format('Y-m-d')];
 }

 public static function run(array $args,bool $private,string $message,bool $trustedFollowup=false): array {
  Auth::admin();
  if(!$private)fail('Relatórios combinados apenas na conversa privada do administrador.',403);
  $p=self::normalize($args);
  if(($p['tasks']||$p['projects'])&&!Auth::can('tasks.read'))fail('Sem acesso às tarefas.',403);
  if($p['finance']){
   if(!Auth::can('finance.read'))fail('Sem acesso ao financeiro.',403);
   if(!$trustedFollowup&&!self::explicitlyFinancial($message))
    fail('Inclua um pedido explícito de informações financeiras para combinar esses dados.',422);
  }
  [$from,$to]=self::dates($p['period']);
  $result=['kind'=>'cross_module_report','period'=>$p['period'],'from'=>$from,'to'=>$to,
   'sections'=>[],'read_only'=>true];
  if($p['tasks']||$p['projects']){
   $params=['paginate'=>1,'per_page'=>self::MAX_TASKS,'page'=>1,'sort'=>'due',
    'due_from'=>$from,'due_to'=>$to];
   if($p['task_query']!=='')$params['q']=$p['task_query'];
   $data=TaskEscTaskApi::list($params);
   $all=$data['rows']??[];
   $total=(int)($data['pagination']['total']??count($all));
   $partial=$total>count($all);
   if($p['tasks']){
    $result['sections']['tasks']=[
     'total'=>$total,'partial'=>$partial,'sampled'=>count($all),
     'items'=>array_map(static fn($d)=>[
      'id'=>(int)$d['id'],'title'=>mb_substr((string)$d['title'],0,65),
      'stage'=>mb_substr((string)$d['stage_name'],0,35),
      'client'=>mb_substr((string)$d['client_name'],0,50),
      'due_at'=>(string)($d['due_at']??'')],array_slice($all,0,5))
    ];
   }
   if($p['projects']){
    $ids=[];
    foreach($all as $d){
     $id=(int)($d['project_id']??0);
     if($id>0)$ids[$id]=($ids[$id]??0)+1;
    }
    $projects=[];
    if($ids){
     $keys=array_keys($ids);
     $placeholders=implode(',',array_fill(0,count($keys),'?'));
     $rows=DB::all('SELECT id,name FROM projects WHERE id IN ('.$placeholders.')',$keys);
     foreach($rows as $d){$id=(int)$d['id'];$projects[]=['id'=>$id,'name'=>mb_substr((string)$d['name'],0,65),'task_count'=>$ids[$id]];}
     usort($projects,static fn($a,$b)=>($b['task_count']<=>$a['task_count'])?:($a['id']<=>$b['id']));
    }
    $result['sections']['projects']=[
     'total_visible_projects'=>count($projects),'partial'=>$partial,
     'items'=>array_slice($projects,0,5),
     'scope'=>'Somente projetos vinculados às tarefas visíveis com prazo no período; não é o cadastro completo.'];
   }
  }
  if($p['agenda']){
   $events=AiWhatsAppAgendaTools::run('agenda_list',['from'=>$from,'to'=>$to,'limit'=>5],true);
   $result['sections']['agenda']=[
    'items'=>array_map(static fn($e)=>['id'=>(int)$e['id'],
     'title'=>mb_substr((string)$e['title'],0,65),
     'starts_at'=>(string)$e['starts_at']],$events['rows']??[]),
    'has_more'=>(bool)($events['has_more']??false),
    'scope'=>'Agendas selecionadas no TaskEsc'];
  }
  if($p['finance']){
   $financial=match($p['finance_view']){
    'summary'=>TaskEscFinanceReadApi::summary(['from'=>$from,'to'=>$to]),
    'pending_expenses'=>TaskEscFinanceReadApi::expenses(['from'=>$from,'to'=>$to,'payment_status'=>'pending','limit'=>5]),
    'receivables'=>TaskEscFinanceReadApi::receivables(['scope'=>'month','from'=>$from,'to'=>$to,'limit'=>5]),
   };
   $result['sections']['finance']=[
    'view'=>$p['finance_view'],'data'=>$financial,
    'scope'=>'Totais da empresa inteira; não atribuídos a projeto ou cliente sem vínculo comprovado'];
  }
  return $result;
 }

 private static function money(int $cents): string {
  return 'R$ '.number_format($cents/100,2,',','.');
 }
 public static function format(array $r): string {
  if(($r['kind']??'')!=='cross_module_report')throw new RuntimeException('ai_unknown_result');
  $lines=['Resumo integrado do TaskEsc — '.date('d/m/Y',strtotime($r['from'])).' a '.date('d/m/Y',strtotime($r['to']))];
  foreach($r['sections'] as $name=>$data){
   if($name==='tasks'){
    $lines[]="\nTAREFAS: ".$data['total'].' com prazo no período.';
    foreach($data['items'] as $v)$lines[]='#'.$v['id'].' '.$v['title'].' | '.$v['stage'].' | '.date('d/m/Y',strtotime($v['due_at']));
    if($data['partial'])$lines[]='Amostra parcial de '.(int)$data['sampled'].' tarefa(s).';
    elseif($data['total']>count($data['items']))$lines[]='Exibindo as 5 primeiras tarefas.';
   }elseif($name==='projects'){
    $lines[]="\nPROJETOS: ".$data['total_visible_projects'].' com tarefas visíveis no período'.($data['partial']?' (amostra parcial)':'').'.';
    foreach($data['items'] as $v)$lines[]='#'.$v['id'].' '.$v['name'].' — '.$v['task_count'].' tarefa(s) na amostra.';
    $lines[]=$data['scope'];
   }elseif($name==='agenda'){
    $lines[]="\nAGENDA: ".count($data['items']).' compromisso(s) exibidos.';
    foreach($data['items'] as $v){
     $date=(new DateTimeImmutable($v['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)config('timezone','America/Fortaleza')));
     $lines[]= $date->format('d/m H:i').' '.$v['title'];
    }
    if($data['has_more'])$lines[]='Existem mais compromissos; mostrando até cinco.';
   }elseif($name==='finance'){
    $f=$data['data'];$lines[]="\nFINANCEIRO DA EMPRESA (não atribuído a projeto):";
    if($data['view']==='summary'){
     $lines[]='Faturado: '.self::money((int)$f['billed_cents']).' | Recebido: '.self::money((int)$f['received_cents']);
     $lines[]='Custos registrados: '.self::money((int)$f['expense_cents']).' | Resultado de caixa: '.self::money((int)$f['profit_cents']);
    }elseif($data['view']==='pending_expenses'){
     $lines[]='Despesas/custos pendentes: '.self::money((int)$f['total_cents']).' ('.(int)$f['count'].' registro(s))';
    }else{
     $lines[]='A receber no período: '.self::money((int)$f['total_cents']).' ('.(int)$f['count'].' cobrança(s))';
     $lines[]='Vencido no período: '.self::money((int)$f['overdue_cents']);
    }
    $lines[]='Somente lançamentos já registrados no TaskEsc.';
   }
  }
  $lines[]="\nConsulta somente leitura. Nenhuma tarefa, evento ou lançamento foi alterado.";
  return mb_substr(implode("\n",$lines),0,3300);
 }
}
