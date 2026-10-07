<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));
function config(?string $key=null,mixed $default=null): mixed {
    if(!getenv('OUTBOX_TEST_PORT')) throw new RuntimeException('Run only against the explicit disposable CI database.');
    $c=['db'=>['host'=>'127.0.0.1','port'=>(int)getenv('OUTBOX_TEST_PORT'),'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only']];
    return $key===null?$c:($c[$key]??$default);
}
function now(): string { return gmdate('Y-m-d H:i:s'); }
require ROOT.'/app/DB.php';
require ROOT.'/app/EventOutbox.php';
require ROOT.'/app/DemandRealtime.php';

function check(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function rejects(callable $operation,string $message): void {
    try { $operation(); } catch (LogicException|InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}

$db=DB::conn();
// Previous realtime tests intentionally share the same CI database in this job.
// Reset it here so this test owns a clean schema on both MySQL and MariaDB.
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(DB::all('SHOW TABLES') as $tableRow){
    $table=(string)array_values($tableRow)[0];
    if(!preg_match('/\\A[A-Za-z0-9_]+\\z/D',$table)) throw new RuntimeException('Unexpected table name in CI database.');
    $db->exec('DROP TABLE `'.$table.'`');
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$db->exec(file_get_contents(ROOT.'/database/schema.sql'));

$demand=[
    'id'=>41,
    'client_id'=>7,
    'project_id'=>9,
    'stage_id'=>3,
    'version'=>6,
    'draft'=>0,
    'updated_at'=>'2026-10-06 23:30:00',
    // Sensitive/business-heavy fields must never be copied automatically.
    'title'=>'Segredo de fixture',
    'brief'=>'brief privado',
    'internal_notes'=>'não publicar',
    'amount_cents'=>999999,
];

rejects(
    fn()=>DemandRealtime::emit('demand.updated',$demand,[],12),
    'Realtime demand events must require the caller business transaction.'
);

$ids=EventOutbox::transaction(fn()=>DemandRealtime::emit(
    'demand.updated',
    $demand,
    ['change'=>'save.updated','previous_project_id'=>8,'priority'=>'high'],
    12,
    [8,9]
));
check(array_keys($ids)===['kanban/global','client:7','demand:41','project:9','project:8'],'Published demand must fan out once per authorized collection/entity channel.');

$rows=DB::all('SELECT * FROM event_outbox ORDER BY id');
check(count($rows)===5,'Expected five outbox rows for global, client, demand, current project and previous project.');
$operationIds=[];
foreach($rows as $row){
    check($row['event_type']==='demand.updated'&&$row['entity_type']==='demand'&&(int)$row['entity_id']===41,'Outbox envelope must identify the demand.');
    check((int)$row['created_by']===12,'Outbox actor must be preserved.');
    $payload=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);
    check($payload['demand_id']===41&&$payload['client_id']===7&&$payload['project_id']===9&&$payload['version']===6,'Payload must carry refresh identifiers.');
    check(is_string($payload['operation_id']??null)&&preg_match('/\\A[a-f0-9]{32}\\z/D',$payload['operation_id'])===1,'Payload must carry a safe fanout operation id.');
    $operationIds[]=$payload['operation_id'];
    check($payload['change']==='save.updated'&&$payload['previous_project_id']===8&&$payload['priority']==='high','Allowed change metadata must be preserved.');
    foreach(['title','brief','internal_notes','amount_cents'] as $forbidden) check(!array_key_exists($forbidden,$payload),'Sensitive/heavy demand fields must not be published.');
}
check(count(array_unique($operationIds))===1,'All channel fanout rows from one mutation must share one operation id.');

$staffIds=EventOutbox::transaction(fn()=>DemandRealtime::emit(
    'demand.comment_created',
    $demand,
    ['change'=>'comment.created','comment_id'=>77,'internal'=>1],
    12,
    [],
    'staff'
));
check(array_keys($staffIds)===['kanban/global','staff/global'],'Staff-only demand details must never fan out to client/demand/project channels.');
$staffPayload=json_decode((string)DB::one("SELECT payload_json FROM event_outbox WHERE event_type='demand.comment_created' ORDER BY id DESC LIMIT 1")['payload_json'],true,16,JSON_THROW_ON_ERROR);
check((int)$staffPayload['internal']===1&&!array_key_exists('body',$staffPayload),'Internal realtime payload must stay metadata-only.');

$before=(int)(DB::one('SELECT COUNT(*) AS n FROM event_outbox')['n']??0);
try {
    EventOutbox::transaction(function() use($demand): void {
        DemandRealtime::emit('demand.deleted',$demand,['change'=>'delete','deleted_at'=>'2026-10-06 23:31:00'],12);
        throw new RuntimeException('rollback-fixture');
    });
} catch (RuntimeException $e) {
    check($e->getMessage()==='rollback-fixture','Unexpected rollback exception.');
}
check((int)(DB::one('SELECT COUNT(*) AS n FROM event_outbox')['n']??0)===$before,'Business rollback must also roll back realtime events.');

$draft=$demand;$draft['id']=42;$draft['draft']=1;$draft['project_id']=10;
$draftIds=EventOutbox::transaction(fn()=>DemandRealtime::emit('demand.created',$draft,['change'=>'save.created'],12));
check(array_keys($draftIds)===['kanban/global'],'Draft events must stay on the admin-only global channel.');

$source=file_get_contents(ROOT.'/app/Demands.php');
foreach(['demand.created','demand.updated','demand.stage_changed','demand.reordered','demand.deleted','demand.comment_created','demand.checklist_changed','demand.time_logged','demand.change_changed'] as $type){
    check(str_contains($source,$type),'Demands integration missing '.$type);
}
check(str_contains($source,"public static function stage(array \$p): array {\n  return DB::tx("),'Stage mutation must be transactional with its event.');
check(str_contains($source,"public static function delete(array \$p): array {\n  return DB::tx("),'Delete mutation must be transactional with its event.');
check(str_contains($source,"public static function quote(array \$p): array {\n  return DB::tx("),'Quote mutation must be transactional with its event.');

echo "Realtime business events: transactional fanout, rollback, draft isolation, minimal payload and Demands wiring OK\n";
