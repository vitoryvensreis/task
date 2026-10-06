<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
function config(?string $key=null,mixed $default=null): mixed {
 if(!getenv('OUTBOX_TEST_PORT'))throw new RuntimeException('Run only against the explicit disposable CI database.');
 $c=['db'=>['host'=>'127.0.0.1','port'=>(int)getenv('OUTBOX_TEST_PORT'),'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only']];
 return $key===null?$c:($c[$key]??$default);
}
require ROOT.'/app/DB.php';
require ROOT.'/app/Migrations.php';
require ROOT.'/app/EventOutbox.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function rejects(callable $operation,string $message): void {
 try{$operation();}catch(LogicException|RuntimeException $e){return;}
 throw new RuntimeException($message);
}
function schemaVersion(int $version): void {DB::run("UPDATE settings SET value=? WHERE `key`='schema_version'",[(string)$version]);}
function event(string $key,array $payload=['b'=>2,'a'=>1]): int {return EventOutbox::enqueue($key,'task.updated','task',1,'task:1',$payload);}
function business(): string {return (string)(DB::one("SELECT value FROM settings WHERE `key`='outbox_ci_business'")['value']??'');}
function businessSet(string $value): void {DB::run("UPDATE settings SET value=? WHERE `key`='outbox_ci_business'",[$value]);}

if(in_array('--duplicate-worker',$argv,true)){
 try{
  DB::conn()->beginTransaction();
  DB::one('SELECT COUNT(*) n FROM event_outbox'); // Establish a snapshot before parent commits.
  echo "ready\n";flush();
  $id=event('concurrent-event');
  DB::conn()->commit();
  echo $id."\n";exit(0);
 }catch(Throwable){fwrite(STDERR,"Concurrent worker failed\n");exit(1);}
}

$db=DB::conn();
$db->exec(file_get_contents(ROOT.'/database/schema.sql'));
check(!Migrations::outboxIssues(),'Fresh schema must match migration including JSON/indexes');
$tables=DB::all('SHOW TABLES');
$ddl=file_get_contents(ROOT.'/database/migrations/021_event_outbox.sql');

// Simulate interrupted migration (table exists, marker still 19), then retry.
schemaVersion(19);
check(Migrations::outbox()['current']===21,'Interrupted migration must resume safely');
check(Migrations::outbox()['changed']===false,'Repeated migration must be a no-op');
check($tables===DB::all('SHOW TABLES'),'Migration must not recreate removed tables');
$db->exec('DROP TABLE event_outbox');schemaVersion(20);
rejects(fn()=>Migrations::outbox(),'Retired schema 20 must be rejected');
check(!DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='event_outbox'"),'Rejected schema 20 must not execute DDL');
schemaVersion(19);$db->beginTransaction();
rejects(fn()=>Migrations::outbox(),'DDL inside a business transaction must be rejected');
check($db->inTransaction(),'Migration must not implicitly commit caller transaction');$db->rollBack();
$db->exec('CREATE TABLE event_outbox (id INT PRIMARY KEY) ENGINE=InnoDB');
rejects(fn()=>Migrations::outbox(),'Incompatible pre-existing table must block migration');
check((DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value'])==='19','Failed migration must not advance marker');
$db->exec('DROP TABLE event_outbox');Migrations::outbox();
check(!Migrations::outboxIssues(),'Upgrade schema 19 must match fresh schema');
DB::run("INSERT INTO settings (`key`,value) VALUES ('outbox_ci_business','original')");

rejects(fn()=>event('outside-transaction'),'Enqueue outside a transaction must be rejected');
$id=EventOutbox::transaction(function(){businessSet('committed');return event('committed-event');});
check(business()==='committed'&&(int)(DB::one('SELECT COUNT(*) n FROM event_outbox')['n'])===1,'Business mutation and event must commit together');
rejects(fn()=>EventOutbox::transaction(function(){businessSet('rollback');event('rollback-event');throw new RuntimeException('fixture');}),'Expected rollback');
check(business()==='committed'&&(int)(DB::one('SELECT COUNT(*) n FROM event_outbox')['n'])===1,'Business mutation and event must roll back together');
$db->beginTransaction();businessSet('outer');
rejects(fn()=>EventOutbox::transaction(function(){businessSet('inner');event('nested-event');throw new RuntimeException('fixture');}),'Expected nested rollback');
check($db->inTransaction()&&business()==='outer','Nested failure must roll back to savepoint only');
check((int)(DB::one('SELECT COUNT(*) n FROM event_outbox')['n'])===1,'Nested event must be rolled back');$db->rollBack();
$duplicate=EventOutbox::transaction(fn()=>event('committed-event',['a'=>1,'b'=>2]));
check($duplicate===$id,'Reordered object keys must deduplicate to the same event ID');
rejects(fn()=>EventOutbox::transaction(function(){businessSet('conflict');event('committed-event',['a'=>99]);}),'Reused key with changed content must fail');
check(business()==='committed','Idempotency conflict must roll back the business mutation');

$db->beginTransaction();$concurrentId=event('concurrent-event');$workers=[];
for($i=0;$i<2;$i++){
 $process=proc_open([PHP_BINARY,__FILE__,'--duplicate-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 check(is_resource($process),'Worker start failed');fclose($pipes[0]);
 $read=[$pipes[1]];$write=$except=null;
 check(stream_select($read,$write,$except,10)===1&&trim((string)fgets($pipes[1]))==='ready','Worker barrier failed');
 $workers[]=[$process,$pipes];
}
$db->commit();
foreach($workers as [$process,$pipes]){
 $read=[$pipes[1]];$write=$except=null;check(stream_select($read,$write,$except,10)===1,'Worker timeout');
 $workerId=(int)trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$errors===''&&$workerId===$concurrentId,'Concurrent duplicate must reuse committed event ID');
}
check((int)(DB::one('SELECT COUNT(*) n FROM event_outbox')['n'])===2,'Concurrent duplicates must not add extra rows');
check(EventOutbox::transaction(fn()=>EventOutbox::retry($id,0,'transport_unavailable',60)),'First retry must reschedule');
check(!EventOutbox::transaction(fn()=>EventOutbox::retry($id,0,'transport_unavailable',60)),'Duplicate retry must not increment twice');
$row=DB::one('SELECT * FROM event_outbox WHERE id=?',[$id]);
check((int)$row['attempts']===1&&$row['published_at']===null&&$row['last_error']==='transport_unavailable','Retry must preserve unpublished event');
check(!in_array($id,array_column(EventOutbox::pending(),'id')),'Delayed retry must not appear as due');
rejects(fn()=>EventOutbox::transaction(fn()=>EventOutbox::retry($id,1,"raw error\nsecret")),'Raw error messages must not enter last_error');

// Verify bounded reads and the due index with a meaningful backlog.
EventOutbox::transaction(function(){for($i=0;$i<1200;$i++)event('backlog-'.$i);});
check(count(EventOutbox::pending(50))===50,'Pending batches must respect the limit');
$plan=DB::one('EXPLAIN SELECT id FROM event_outbox WHERE published_at IS NULL AND available_at<=UTC_TIMESTAMP(6) ORDER BY available_at,id LIMIT 100');
check(str_contains((string)$plan['possible_keys'],'idx_event_outbox_ready'),'Due query must be indexable');
check(!str_contains(strtolower((string)$plan['Extra']),'filesort'),'Due query must use index ordering');
check(!DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('workspaces','workspace_members','workspace_settings')"),'No SaaS structures may be created');
check(session_status()===PHP_SESSION_NONE,'Outbox must not change sessions');
echo "Outbox: fresh/upgrade migration, retired-schema guard, atomic commit/rollback, nested savepoints, concurrent idempotency, CAS retry and indexed batches OK\n";
