<?php
declare(strict_types=1);
/** Agenda through existing TaskEsc domain, private confirmed writes and safe group read. */
final class AiWhatsAppAgendaTools {
 private static function tool(string $name,string $description,array $props,array $required=[]): array {
  return ['type'=>'function','function'=>['name'=>$name,'description'=>$description,'parameters'=>['type'=>'object','properties'=>$props,'required'=>$required,'additionalProperties'=>false]]];
 }
 public static function sensitive(string $name): bool {return in_array($name,['agenda_create','agenda_update','agenda_cancel'],true);}
 public static function supported(string $name): bool {return in_array($name,['agenda_list','agenda_calendars','agenda_detail','agenda_conflicts'],true);}
 public static function catalog(bool $private): array {
  if((Auth::$user['role']??'')!=='admin')return [];
  $date=['type'=>'string','description'=>'Hora local YYYY-MM-DDTHH:mm, não inventar horário'];$id=['type'=>'integer'];
  $tools=[self::tool('agenda_list',$private?'Consultar compromissos, inclusive privados.':'Listar SOMENTE eventos NÃO PRIVADOS em grupos.',[
   'from'=>['type'=>'string','description'=>'YYYY-MM-DD opcional'],'to'=>['type'=>'string','description'=>'YYYY-MM-DD opcional'],'limit'=>$id])];
  if(!$private)return $tools;
  $tools[]=self::tool('agenda_calendars','Listar agendas Google e locais com seus IDs e permissão de edição.',[]);
  $tools[]=self::tool('agenda_detail','Ver detalhes de um compromisso pelo ID.',['event_id'=>$id],['event_id']);
  $tools[]=self::tool('agenda_conflicts','Ver horários ocupados sem realizar reserva.',['starts_at'=>$date,'ends_at'=>$date],['starts_at','ends_at']);
  if(AiWhatsAppAdminActions::enabled()){
   $tools[]=self::tool('agenda_create','PREPARAR criação de compromisso; só executa após CONFIRMAR em chat privado.',[
    'calendar_id'=>$id,'title'=>['type'=>'string'],'starts_at'=>$date,'ends_at'=>$date,'location'=>['type'=>'string'],'description'=>['type'=>'string'],'private'=>['type'=>'boolean'],'demand_id'=>$id],
    ['calendar_id','title','starts_at','ends_at']);
   $tools[]=self::tool('agenda_update','PREPARAR edição de um compromisso existente, sempre com confirmação.',['event_id'=>$id,'title'=>['type'=>'string'],'starts_at'=>$date,'ends_at'=>$date,'location'=>['type'=>'string'],'description'=>['type'=>'string'],'private'=>['type'=>'boolean']],['event_id']);
   $tools[]=self::tool('agenda_cancel','PREPARAR cancelamento de compromisso, sempre com confirmação.',['event_id'=>$id],['event_id']);
  }
  return $tools;
 }
 private static function keys(array $args,array $allowed): void {foreach(array_keys($args) as $key)if(!in_array($key,$allowed,true))fail('Campo de agenda não permitido.',422);}
 private static function dt(mixed $value): string {
  if(!is_string($value)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$value))fail('Use YYYY-MM-DDTHH:mm.',422);
  $z=new DateTimeZone((string)config('timezone','America/Fortaleza'));$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$z);
  if(!$d||$d->format('Y-m-d\TH:i')!==$value)fail('Data e hora inválidas.',422);
  return $d->format('Y-m-d\TH:i:sP');
 }
 private static function fromUtc(string $utc): string {return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)config('timezone','America/Fortaleza')))->format('Y-m-d\TH:i:sP');}
 private static function date(mixed $value): string {return (string)datev($value,false);}
 private static function event(int $id): array {
  $e=DB::one('SELECT e.*,c.write_enabled FROM events e JOIN calendars c ON c.id=e.calendar_id WHERE e.id=?',[$id]);
  if(!$e||$e['status']==='cancelled')fail('Compromisso não encontrado.',404);
  return $e;
 }
 private static function label(string $iso): string {return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone((string)config('timezone','America/Fortaleza')))->format('d/m/Y H:i');}
 public static function context(bool $private): array {
  if(!$private||(Auth::$user['role']??'')!=='admin')return [];
  return ['calendars'=>array_map(static fn($c)=>['id'=>(int)$c['id'],'name'=>$c['name'],'write_enabled'=>(bool)$c['write_enabled']],array_slice(Agenda::calendars(),0,25))];
 }
 public static function run(string $name,array $args,bool $private): array {
  Auth::admin();if(!self::supported($name))fail('Ferramenta de agenda indisponível.',403);
  if($name==='agenda_calendars'){if(!$private)fail('Agendas somente no privado.',403);self::keys($args,[]);return ['kind'=>'agenda_calendars','rows'=>array_slice(Agenda::calendars(),0,20)];}
  if($name==='agenda_detail'){if(!$private)fail('Detalhes somente no privado.',403);self::keys($args,['event_id']);return ['kind'=>'agenda_detail','event'=>Agenda::detail(['id'=>integer($args['event_id']??null,1)])];}
  if($name==='agenda_conflicts'){
   if(!$private)fail('Disponibilidade detalhada somente no privado.',403);
   self::keys($args,['starts_at','ends_at']);$s=self::dt($args['starts_at']??null);$e=self::dt($args['ends_at']??null);
   if(timestamp($e)<=timestamp($s))fail('Fim antes do início.',422);
   return ['kind'=>'agenda_conflicts','rows'=>array_slice(Agenda::conflicts(timestamp($s),timestamp($e),0),0,10)];
  }
  self::keys($args,['from','to','limit']);
  $zone=new DateTimeZone((string)config('timezone','America/Fortaleza'));$today=new DateTimeImmutable('today',$zone);
  $from=isset($args['from'])?self::date($args['from']):$today->format('Y-m-d');
  $to=isset($args['to'])?self::date($args['to']):$today->modify('+7 days')->format('Y-m-d');
  if($from>$to||(new DateTimeImmutable($from,$zone))->diff(new DateTimeImmutable($to,$zone))->days>90)fail('O período máximo é 90 dias.',422);
  $limit=integer($args['limit']??10,1,10);
  $sql="SELECT e.id,e.title,e.starts_at,e.ends_at,e.private,e.location,c.name calendar_name FROM events e JOIN calendars c ON c.id=e.calendar_id WHERE e.status<>'cancelled' AND c.selected=1 AND e.ends_at>? AND e.starts_at<?";
  if(!$private)$sql.=" AND e.private=0";
  $rows=DB::all($sql.' ORDER BY e.starts_at,e.id LIMIT '.($limit+1),[timestamp($from.'T00:00:00'),timestamp($to.'T23:59:59')]);
  return ['kind'=>'agenda_list','rows'=>array_slice($rows,0,$limit),'has_more'=>count($rows)>$limit,'public_only'=>!$private];
 }
 public static function prepare(string $name,array $p): array {
  Auth::admin();if(!AiWhatsAppAdminActions::enabled()||!self::sensitive($name))fail('Edição da agenda desativada.',403);
  if($name==='agenda_create'){
   self::keys($p,['calendar_id','title','starts_at','ends_at','location','description','private','demand_id']);
   $calId=integer($p['calendar_id']??null,1);
   $c=DB::one('SELECT * FROM calendars WHERE id=?',[$calId]);
   if(!$c||!$c['write_enabled']||!in_array($c['access_role'],['owner','writer','writerWithoutPrivateAccess'],true))fail('Agenda indisponível para gravação.',403);
   $title=required($p,'title',255);$start=self::dt($p['starts_at']??null);$end=self::dt($p['ends_at']??null);
   if(timestamp($end)<=timestamp($start))fail('O fim deve ser posterior ao início.',422);
   if(Agenda::conflicts(timestamp($start),timestamp($end),0))fail('Existe conflito de horário. Revise antes de confirmar.',409);
   $priv=$p['private']??false;if(!is_bool($priv))fail('Visibilidade inválida.',422);
   $payload=['calendar_id'=>$calId,'title'=>$title,'starts_at'=>$start,'ends_at'=>$end,'location'=>mb_substr((string)($p['location']??''),0,500),
    'description'=>mb_substr((string)($p['description']??''),0,2000),'private'=>(int)$priv];
   if(isset($p['demand_id'])){$payload['demand_id']=integer($p['demand_id'],1);Auth::demand($payload['demand_id']);}
   return [$payload,"Criar compromisso '$title' em {$c['name']}, ".self::label($start).' até '.self::label($end).'.'];
  }
  $id=integer($p['event_id']??null,1);$old=self::event($id);
  if(!$old['write_enabled'])fail('Agenda somente leitura.',403);
  if($name==='agenda_cancel'){
   self::keys($p,['event_id']);
   return [['id'=>$id,'version'=>(int)$old['version']],"Cancelar compromisso #$id: {$old['title']}?"];
  }
  self::keys($p,['event_id','title','starts_at','ends_at','location','description','private']);
  if(count($p)<2)fail('Nenhum campo para editar.',422);
  $payload=['id'=>$id,'version'=>(int)$old['version'],'calendar_id'=>(int)$old['calendar_id'],'title'=>(string)$old['title'],
   'starts_at'=>self::fromUtc($old['starts_at']),'ends_at'=>self::fromUtc($old['ends_at']),'location'=>(string)$old['location'],
   'description'=>(string)$old['description'],'private'=>(int)$old['private'],'all_day'=>(int)$old['all_day'],
   'buffer_minutes'=>(int)$old['buffer_minutes'],'demand_id'=>$old['demand_id']];
  if(isset($p['title']))$payload['title']=required($p,'title',255);
  foreach(['starts_at','ends_at'] as $k)if(array_key_exists($k,$p))$payload[$k]=self::dt($p[$k]);
  foreach(['location','description'] as $k)if(array_key_exists($k,$p))$payload[$k]=mb_substr((string)$p[$k],0,$k==='location'?500:2000);
  if(array_key_exists('private',$p)){if(!is_bool($p['private']))fail('Visibilidade inválida.',422);$payload['private']=(int)$p['private'];}
  if(timestamp($payload['ends_at'])<=timestamp($payload['starts_at']))fail('Horário inválido.',422);
  if(Agenda::conflicts(timestamp($payload['starts_at']),timestamp($payload['ends_at']),(int)$payload['buffer_minutes'],$id))fail('Conflito de horário.',409);
  return [$payload,"Editar compromisso #$id '{$old['title']}' (versão {$old['version']})."];
 }
 public static function runConfirmed(string $name,array $p): array {
  Auth::admin();if(!AiWhatsAppAdminActions::enabled())fail('Controle da agenda desativado.',403);
  // Never call Google while the WhatsApp approval transaction is uncommitted; cron syncs pending events.
  if(in_array($name,['agenda_create','agenda_update'],true)){
   $r=Agenda::save($p,true);return ['kind'=>'admin_done','action'=>'Compromisso salvo (sincronização Google agendada)','id'=>(int)$r['id']];
  }
  if($name==='agenda_cancel'){
   Agenda::cancel($p,true);return ['kind'=>'admin_done','action'=>'Compromisso cancelado (sincronização Google agendada)','id'=>(int)$p['id']];
  }
  fail('Operação de agenda não autorizada.',403);
 }
 public static function format(array $r): string {
  switch($r['kind']??''){
   case 'agenda_calendars':
    $lines=['Agendas:'];foreach($r['rows'] as $c)$lines[]='#'.$c['id'].' '.$c['name'].($c['write_enabled']?' (editável)':' (somente leitura)');
    return count($lines)>1?implode("\n",$lines):'Nenhuma agenda disponível.';
   case 'agenda_list':
    $lines=[$r['public_only']?'Agenda compartilhável:':'Agenda:'];
    foreach($r['rows'] as $e)$lines[]='#'.$e['id'].' '.self::label(self::fromUtc($e['starts_at'])).' '.$e['title'].' — '.$e['calendar_name'];
    if(count($lines)===1)return 'Nenhum compromisso encontrado no período.';
    if($r['has_more'])$lines[]='Há outros compromissos neste período.';
    return implode("\n",$lines);
   case 'agenda_detail':
    $e=$r['event'];
    return 'Compromisso #'.$e['id'].' '.$e['title']."\n".self::label(self::fromUtc($e['starts_at'])).' até '.self::label(self::fromUtc($e['ends_at']))."\nAgenda: ".$e['calendar_name'];
   case 'agenda_conflicts':return empty($r['rows'])?'Período livre nas agendas selecionadas.':'Foram encontrados '.count($r['rows']).' conflito(s) de horário.';
  }
  throw new RuntimeException('agenda_result_invalid');
 }
}
