<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');
if(!$port)throw new RuntimeException('Run only against the disposable CI database.');

$configPath=ROOT.'/config/config.php';
if(is_file($configPath))throw new RuntimeException('Refusing to overwrite an existing config.');
$config=[
 'base_url'=>'https://task.escotilhaprodutora.com',
 'timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32)),
 'smtp'=>['host'=>''],
];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);
touch(ROOT.'/storage/installed.lock');

require ROOT.'/app/bootstrap.php';

function domainCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function domainUser(int $id): array {
 $u=DB::one('SELECT id,name,email,role,active,email_notifications,weekly_report,twofa_enabled,last_login_at FROM users WHERE id=?',[$id]);
 if(!$u)throw new RuntimeException('Missing realtime domain user.');
 $u['is_preview']=0;return $u;
}
function domainAs(int $id): void {
 Auth::$user=domainUser($id);Auth::$actor=Auth::$user;Auth::$previewClientId=null;Auth::$origin='web';Auth::$scopes='tools.read tools.write';
}
function domainChannels(string $type): array {
 $rows=DB::all('SELECT channel FROM event_outbox WHERE event_type=? ORDER BY id',[$type]);
 return array_column($rows,'channel');
}
function domainLast(string $type): array {
 $row=DB::one('SELECT * FROM event_outbox WHERE event_type=? ORDER BY id DESC LIMIT 1',[$type]);
 if(!$row)throw new RuntimeException('Missing realtime event '.$type);
 $row['payload']=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);
 return $row;
}

try{
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){$table=(string)array_values($row)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected table.');$db->exec('DROP TABLE `'.$table.'`');}
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));

 $password=password_hash('Taskesc-domain-ci!',PASSWORD_DEFAULT);
 $admin=DB::insert('users',['name'=>'Admin Domain','email'=>'admin-domain@ci.invalid','password_hash'=>$password,'role'=>'admin','active'=>1,'email_notifications'=>0,'created_at'=>now()]);
 $staff=DB::insert('users',['name'=>'Staff Domain','email'=>'staff-domain@ci.invalid','password_hash'=>$password,'role'=>'staff','active'=>1,'email_notifications'=>0,'created_at'=>now()]);
 $clientUser=DB::insert('users',['name'=>'Client Domain','email'=>'client-domain@ci.invalid','password_hash'=>$password,'role'=>'client','active'=>1,'email_notifications'=>0,'created_at'=>now()]);
 $client=DB::insert('clients',['name'=>'Realtime Domain Client','created_at'=>now()]);
 DB::run('INSERT INTO user_clients (user_id,client_id,can_approve,can_finance) VALUES (?,?,1,0),(?,?,1,0)',[$staff,$client,$clientUser,$client]);
 $stage=DB::insert('stages',['name'=>'Produção','kind'=>'production','position'=>100]);
 $category=DB::insert('categories',['name'=>'Vídeo','fields_json'=>'[]']);
 $demand=DB::insert('demands',[
  'client_id'=>$client,'category_id'=>$category,'stage_id'=>$stage,'title'=>'Task realtime domain','brief'=>'Teste',
  'details_json'=>'{}','due_at'=>gmdate('Y-m-d H:i:s',time()+86400),'created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()
 ]);

 domainAs($staff);
 $publicComment=Demands::comment(['demand_id'=>$demand,'body'=>'comentário público']);
 domainCheck((int)$publicComment['id']>0,'Public comment must be created.');
 $publicChannels=domainChannels('demand.comment_created');
 domainCheck(in_array('client:'.$client,$publicChannels,true)&&in_array('demand:'.$demand,$publicChannels,true),'Public comment must invalidate client and demand channels.');

 Demands::comment(['demand_id'=>$demand,'body'=>'comentário interno','internal'=>1]);
 $commentRows=DB::all("SELECT channel,payload_json FROM event_outbox WHERE event_type='demand.comment_created' ORDER BY id");
 $internalRows=array_slice($commentRows,count($publicChannels));
 $internalChannels=array_column($internalRows,'channel');
 sort($internalChannels);
 domainCheck($internalChannels===['kanban/global','staff/global'],'Internal comment must stay on admin/staff channels only.');
 foreach($internalRows as $row){$payload=json_decode($row['payload_json'],true,16,JSON_THROW_ON_ERROR);domainCheck((int)$payload['internal']===1,'Internal comment payload must mark privacy.');domainCheck(!array_key_exists('body',$payload),'Comment body must never be published.');}

 $check=Demands::checklist(['demand_id'=>$demand,'label'=>'Conferir áudio']);
 domainCheck((int)$check['id']>0&&domainLast('demand.checklist_changed')['channel']!=='','Checklist must emit realtime.');
 $time=Demands::time(['demand_id'=>$demand,'minutes'=>30,'description'=>'Edição','worked_on'=>gmdate('Y-m-d')]);
 domainCheck((int)$time['id']>0,'Time entry must be created.');
 $timeChannels=domainChannels('demand.time_logged');sort($timeChannels);
 domainCheck($timeChannels===['kanban/global','staff/global'],'Time tracking must stay staff-only.');

 domainAs($clientUser);
 $change=Demands::change(['demand_id'=>$demand,'description'=>'Trocar a abertura']);
 domainCheck((int)$change['id']>0&&in_array('client:'.$client,domainChannels('demand.change_changed'),true),'Scope change must emit to authorized client collection.');

 domainAs($admin);
 $publicFile=Files::add(['demand_id'=>$demand,'label'=>'Referência pública','url'=>'https://example.com/ref.mp4','kind'=>'reference','shared'=>1]);
 domainCheck((int)$publicFile['id']>0&&in_array('client:'.$client,domainChannels('demand.file_added'),true),'Shared file must emit to client channel.');
 $privateFile=Files::add(['demand_id'=>$demand,'label'=>'Arquivo interno','url'=>'https://example.com/internal.txt','kind'=>'input','shared'=>0]);
 $allFileRows=DB::all("SELECT channel,payload_json FROM event_outbox WHERE event_type='demand.file_added' ORDER BY id");
 $privateRows=array_slice($allFileRows,-2);
 $privateChannels=array_column($privateRows,'channel');sort($privateChannels);
 domainCheck($privateChannels===['kanban/global','staff/global'],'Private file must stay staff-only.');
 foreach($privateRows as $row){$payload=json_decode($row['payload_json'],true,16,JSON_THROW_ON_ERROR);domainCheck(!array_key_exists('label',$payload)&&!array_key_exists('url',$payload),'File metadata content must not leak into websocket payload.');}
 Files::delete(['file_id'=>$privateFile['id']]);
 domainCheck(domainLast('demand.file_deleted')['entity_type']==='demand','File deletion must emit demand invalidation.');

 $reviewFile=Files::add(['demand_id'=>$demand,'label'=>'Versão 1','url'=>'https://example.com/review.mp4','kind'=>'review','shared'=>1]);
 $review=Files::review(['file_id'=>$reviewFile['id'],'decision'=>'approved']);
 domainCheck($review['saved']===true&&domainLast('demand.file_reviewed')['payload']['approval']==='approved','File review must emit realtime approval.');

 $calendar=DB::insert('calendars',['name'=>'Agenda Local Realtime','color'=>'#737373','access_role'=>'owner','selected'=>1,'write_enabled'=>1,'dirty'=>0]);
 $start=gmdate('Y-m-d\TH:i',time()+7200);$end=gmdate('Y-m-d\TH:i',time()+10800);
 $event=Agenda::save(['calendar_id'=>$calendar,'demand_id'=>$demand,'title'=>'Reunião realtime','starts_at'=>$start,'ends_at'=>$end,'all_day'=>0,'private'=>0,'buffer_minutes'=>0]);
 domainCheck((int)$event['id']>0,'Local agenda event must be created.');
 $agendaCreated=domainLast('agenda.event_created');
 domainCheck($agendaCreated['channel']==='agenda/global'&&$agendaCreated['entity_type']==='calendar_event','Agenda create must publish only minimal global invalidation.');
 foreach(['title','description','location','attendees','organizer_email'] as $forbidden)domainCheck(!array_key_exists($forbidden,$agendaCreated['payload']),'Agenda websocket payload must not expose calendar content.');

 $eventRow=DB::one('SELECT * FROM events WHERE id=?',[(int)$event['id']]);
 Agenda::cancel(['id'=>$event['id'],'version'=>$eventRow['version']]);
 domainCheck(domainLast('agenda.event_cancelled')['payload']['status']==='cancelled','Agenda cancel must emit realtime.');

 EventOutbox::transaction(fn()=>AgendaRealtime::calendar('agenda.calendar_refreshed',$calendar,'google.pull',null,3));
 $calendarEvent=domainLast('agenda.calendar_refreshed');
 domainCheck((int)$calendarEvent['payload']['changed_count']===3&&$calendarEvent['channel']==='agenda/global','Google pull invalidation must be compact and count changes.');

 $sourceDemands=file_get_contents(ROOT.'/app/Demands.php');
 foreach(['demand.comment_created','demand.checklist_changed','demand.time_logged','demand.change_changed'] as $type)domainCheck(str_contains($sourceDemands,$type),'Demands missing '.$type);
 $sourceFiles=file_get_contents(ROOT.'/app/Files.php');
 foreach(['demand.file_added','demand.file_deleted','demand.file_reviewed'] as $type)domainCheck(str_contains($sourceFiles,$type),'Files missing '.$type);
 $sourceGoogle=file_get_contents(ROOT.'/app/Google.php');
 domainCheck(str_contains($sourceGoogle,"AgendaRealtime::calendar('agenda.calendar_refreshed'"),'Google pull must notify realtime calendar refresh.');

 echo "Realtime domain: comments, checklist, time, scope, files, reviews and agenda invalidation OK\n";
}finally{
 Auth::$user=null;Auth::$actor=null;Auth::$previewClientId=null;
 @unlink($configPath);@unlink(ROOT.'/storage/installed.lock');
}
