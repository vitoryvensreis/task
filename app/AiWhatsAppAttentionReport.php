<?php
declare(strict_types=1);

/** Deterministic read-only triage of tasks visible to the current actor. */
final class AiWhatsAppAttentionReport
{
 private const PAGE_SIZE=100;
 private const MAX_ROWS=2000;

 public static function run(array $args=[]): array
 {
  Auth::requireAbility('tasks.read');
  if($args!==[])fail('A análise de atenção não recebe parâmetros.',422);
  $rows=[];$total=0;
  for($page=1;$page<=self::MAX_ROWS/self::PAGE_SIZE;$page++){
   $data=TaskEscTaskApi::list(['paginate'=>1,'per_page'=>self::PAGE_SIZE,'page'=>$page,'sort'=>'due']);
   $total=(int)($data['pagination']['total']??0);
   $new=$data['rows']??[];
   if(!is_array($new))throw new RuntimeException('ai_invalid_response');
   array_push($rows,...$new);
   if(count($rows)>=$total||$new===[])break;
  }
  $projectIds=[];
  foreach($rows as $d)if((int)($d['project_id']??0)>0)$projectIds[(int)$d['project_id']]=true;
  $projectNames=[];
  if($projectIds){
   $ids=array_keys($projectIds);
   $placeholders=implode(',',array_fill(0,count($ids),'?'));
   foreach(DB::all('SELECT id,name FROM projects WHERE id IN ('.$placeholders.')',$ids) as $p)
    $projectNames[(int)$p['id']]=(string)$p['name'];
  }
  $timezone=new DateTimeZone((string)config('timezone','America/Fortaleza'));
  return self::summarize($rows,$projectNames,new DateTimeImmutable('now',$timezone),$total);
 }

 /** Pure method to test all sorting and grouping without modifying business data. */
 public static function summarize(array $rows,array $projectNames,DateTimeImmutable $now,int $visibleTotal): array
 {
  $soonEnd=$now->modify('+7 days');
  $groups=[];$active=0;$completed=0;$overdue=0;$soon=0;$high=0;
  foreach($rows as $d){
   $kind=(string)($d['stage_kind']??'');
   if(in_array($kind,['completed','cancelled','archived'],true)){$completed++;continue;}
   $active++;
   $priority=(string)($d['priority']??'normal');
   $isHigh=in_array($priority,['high','urgent'],true);
   if($isHigh)$high++;
   $due=null;
   if(is_string($d['due_at']??null)&&$d['due_at']!==''){
    try{$due=new DateTimeImmutable($d['due_at'],$now->getTimezone());}catch(Throwable){$due=null;}
   }
   $isOverdue=$due!==null&&$due<$now;
   $isSoon=$due!==null&&!$isOverdue&&$due<=$soonEnd;
   if($isOverdue)$overdue++;
   if($isSoon)$soon++;
   $projectId=(int)($d['project_id']??0);
   $clientId=(int)($d['client_id']??0);
   $client=mb_substr((string)($d['client_name']??'Cliente'),0,60);
   $groupKey=$projectId>0?'project:'.$projectId:'client:'.$clientId;
   $groupTitle=$projectId>0
    ?'Projeto: '.mb_substr($projectNames[$projectId]??('Projeto #'.$projectId),0,65)
    :'Cliente: '.$client.' (sem projeto vinculado)';
   if(!isset($groups[$groupKey])){
    $groups[$groupKey]=['label'=>$groupTitle,'client'=>$client,'count'=>0,'late'=>0,
     'upcoming'=>0,'high'=>0,'score'=>0,'tasks'=>[]];
   }
   $g=&$groups[$groupKey];$g['count']++;
   $g['late']+=(int)$isOverdue;$g['upcoming']+=(int)$isSoon;$g['high']+=(int)$isHigh;
   $points=($isOverdue?100:0)+($isSoon?45:0)+($priority==='urgent'?55:($priority==='high'?30:0));
   // Projects with open work but no urgent signal still appear after critical work.
   $g['score']+=$points+1;
   $g['tasks'][]=['id'=>(int)($d['id']??0),
    'title'=>mb_substr((string)($d['title']??''),0,72),
    'stage'=>mb_substr((string)($d['stage_name']??'Sem etapa'),0,40),
    'due'=>$due?->format('d/m/Y'),
    'priority'=>$priority,'overdue'=>$isOverdue,'soon'=>$isSoon,'score'=>$points];
   unset($g);
  }
  $ranked=array_values($groups);
  foreach($ranked as &$g){
   usort($g['tasks'],static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));
   $g['tasks']=array_slice($g['tasks'],0,2);
  }unset($g);
  usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['label'],$b['label']));
  return ['kind'=>'attention_report','visible_total'=>$visibleTotal,
    'analyzed'=>count($rows),'partial'=>count($rows)<$visibleTotal,
    'active'=>$active,'completed'=>$completed,'overdue'=>$overdue,
    'upcoming'=>$soon,'high'=>$high,'groups'=>array_slice($ranked,0,5),
    'group_count'=>count($ranked)];
 }

 public static function format(array $r): string
 {
  if(($r['kind']??'')!=='attention_report')throw new RuntimeException('ai_unknown_result');
  $lines=['Análise de demandas — dados atuais do TaskEsc',
   'Tarefas em aberto: '.(int)$r['active'].' | atrasadas: '.(int)$r['overdue']
   .' | próximas 7 dias: '.(int)$r['upcoming'].' | prioridade alta/urgente: '.(int)$r['high']];
  if((int)$r['active']===0)$lines[]='Nenhuma tarefa em aberto encontrada para seu usuário.';
  else{
   $lines[]='Projetos/clientes que merecem atenção (ordem por atraso, prazo e prioridade):';
   foreach($r['groups'] as $index=>$g){
    $signals=[];
    if($g['late'])$signals[]=$g['late'].' atrasada(s)';
    if($g['upcoming'])$signals[]=$g['upcoming'].' com prazo próximo';
    if($g['high'])$signals[]=$g['high'].' alta/urgente';
    if(!$signals)$signals[]='tarefas em andamento, sem alerta de prazo ou prioridade';
    $lines[]=($index+1).'. '.$g['label'].' — '.$g['count'].' aberta(s); '.implode(', ',$signals);
    foreach($g['tasks'] as $d){
     $details=['#'.$d['id'].' '.$d['title'],'etapa: '.$d['stage']];
     if($d['due'])$details[]='prazo: '.$d['due'].($d['overdue']?' (vencido)':'');
     if(in_array($d['priority'],['high','urgent'],true))$details[]='prioridade: '.$d['priority'];
     $lines[]='  • '.implode(' | ',$details);
    }
   }
   if($r['group_count']>count($r['groups']))$lines[]='Exibindo os 5 grupos com sinais mais relevantes.';
  }
  if($r['partial'])$lines[]='Atenção: análise parcial de '.(int)$r['analyzed'].' de '.(int)$r['visible_total'].' tarefas visíveis.';
  $lines[]='Somente leitura. Nenhuma tarefa foi alterada.';
  return mb_substr(implode("\n",$lines),0,3200);
 }
}
