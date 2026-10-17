<?php
declare(strict_types=1);

// Integration suite; abort unless the GitHub Actions disposable DB is configured.
$port=getenv('OUTBOX_TEST_PORT');
if(!$port||!ctype_digit((string)$port))throw new RuntimeException('Only disposable OUTBOX_TEST_PORT is allowed.');
$root=dirname(__DIR__);
@unlink($root.'/config/config.php');
$config=[
 'base_url'=>'http://localhost','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32))
];
file_put_contents($root.'/config/config.php',"<?php\nreturn ".var_export($config,true).";\n");
require $root.'/app/bootstrap.php';
function childCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function childReject(callable $fn,string $message): void {
 try{$fn();}catch(AppError|RuntimeException|LogicException){return;}
 throw new RuntimeException($message);
}
try {
 $db=DB::conn();
 $db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){
  $table=(string)array_values($row)[0];
  if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected fixture table');
  $db->exec('DROP TABLE `'.$table.'`');
 }
 $db->exec('SET FOREIGN_KEY_CHECKS=1');
 $db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 childCheck(count(SaasChildMigration::CHILDREN)===16,'Expected 16 child ownership tables.');
 childCheck(SaasChildMigration::issues()===[],'Fresh schema must contain child columns and indices.');

 $user=DB::insert('users',['name'=>'Legacy Owner','email'=>'b12b-owner@ci.invalid','password_hash'=>'placeholder','role'=>'admin','created_at'=>now()]);
 $outsider=DB::insert('users',['name'=>'Tenant B User','email'=>'b12b-other@ci.invalid','password_hash'=>'placeholder','role'=>'staff','created_at'=>now()]);
 $client=DB::insert('clients',['name'=>'Existing Client','created_at'=>now()]);
 $contract=DB::insert('contracts',['client_id'=>$client,'name'=>'Legacy Contract','amount_cents'=>1000,'starts_on'=>'2026-10-01','created_at'=>now()]);
 $project=DB::insert('projects',['client_id'=>$client,'name'=>'Existing Project','created_at'=>now()]);
 $demand=DB::insert('demands',[
  'client_id'=>$client,'project_id'=>$project,'category_id'=>1,'stage_id'=>1,
  'title'=>'Existing task','brief'=>'Legacy description','details_json'=>'{}',
  'due_at'=>now(),'created_by'=>$user,'created_at'=>now(),'updated_at'=>now()
 ]);
 $invoice=DB::insert('invoices',['client_id'=>$client,'demand_id'=>$demand,'description'=>'Legacy invoice','amount_cents'=>100,'due_on'=>'2026-12-01','created_at'=>now()]);
 $automation=DB::insert('automations',[
  'name'=>'Legacy Workflow','trigger_event'=>'demand.created','definition_json'=>'{"version":1,"nodes":[],"edges":[]}',
  'created_by'=>$user,'created_at'=>now(),'updated_at'=>now()
 ]);

 $foundation=SaasFoundationMigration::apply();
 $legacy=(int)$foundation['account_id'];
 childCheck(SaasDomainMigration::apply()['domain_version']===2,'Root staging must complete before children.');

 // Current legacy writes do not supply tenant_account_id, so all child
 // fixture rows intentionally start unowned.
 $item=DB::insert('contract_items',['contract_id'=>$contract,'category_id'=>1,'unit'=>'video','quantity'=>1]);
 $cycle=DB::insert('cycles',['contract_id'=>$contract,'starts_on'=>'2026-10-01','ends_on'=>'2026-10-31','amount_cents'=>1000]);
 $cycleItem=DB::insert('cycle_items',['cycle_id'=>$cycle,'category_id'=>1,'unit'=>'video','quantity'=>1]);
 $usage=DB::insert('demand_usage',['demand_id'=>$demand,'cycle_item_id'=>$cycleItem,'quantity'=>1]);
 $transition=DB::insert('transitions',['demand_id'=>$demand,'to_stage'=>2,'actor_id'=>$user,'happened_at'=>now()]);
 $comment=DB::insert('comments',['demand_id'=>$demand,'author_id'=>$user,'body'=>'Existing comment','created_at'=>now()]);
 $checklist=DB::insert('checklists',['demand_id'=>$demand,'label'=>'Existing checkbox']);
 $time=DB::insert('time_entries',['demand_id'=>$demand,'user_id'=>$user,'minutes'=>20,'worked_on'=>'2026-10-16','created_at'=>now()]);
 $file=DB::insert('files',['demand_id'=>$demand,'author_id'=>$user,'label'=>'Existing video','created_at'=>now()]);
 $review=DB::insert('reviews',['file_id'=>$file,'user_id'=>$user,'decision'=>'approved','created_at'=>now()]);
 $change=DB::insert('change_requests',['demand_id'=>$demand,'user_id'=>$user,'description'=>'Existing correction','created_at'=>now()]);
 $cost=DB::insert('costs',['demand_id'=>$demand,'description'=>'Cost','amount_cents'=>35,'spent_on'=>'2026-10-16','created_at'=>now()]);
 $payment=DB::insert('payments',['invoice_id'=>$invoice,'amount_cents'=>100,'paid_on'=>'2026-10-16','method'=>'pix','created_by'=>$user,'created_at'=>now()]);
 $event=DB::insert('events',['calendar_id'=>1,'demand_id'=>$demand,'title'=>'Existing meeting','starts_at'=>now(),'ends_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
 $run=DB::insert('automation_runs',[
  'automation_id'=>$automation,'operation_id'=>str_repeat('a',32),
  'trigger_event'=>'demand.created','entity_type'=>'demand','entity_id'=>$demand,
  'context_json'=>'{}','available_at'=>now(),'created_at'=>now()
 ]);
 $step=DB::insert('automation_run_steps',['run_id'=>$run,'node_id'=>'node-1','node_type'=>'end','status'=>'success','input_json'=>'{}','started_at'=>now()]);
 $existing=[
  'contract_items'=>$item,'cycles'=>$cycle,'cycle_items'=>$cycleItem,'demand_usage'=>$usage,
  'transitions'=>$transition,'comments'=>$comment,'checklists'=>$checklist,'time_entries'=>$time,
  'files'=>$file,'reviews'=>$review,'change_requests'=>$change,'costs'=>$cost,'payments'=>$payment,
  'events'=>$event,'automation_runs'=>$run,'automation_run_steps'=>$step
 ];

 // Simulate an existing schema-30 DB before the staged B1.2b DDL.
 foreach(SaasChildMigration::CHILDREN as $table=>$map){
  $db->exec('ALTER TABLE `'.$table.'` DROP INDEX `idx_saas_'.$table.'`');
  $db->exec('ALTER TABLE `'.$table.'` DROP COLUMN tenant_account_id');
 }
 childCheck(count(SaasChildMigration::issues())===16,'All child columns must be detected as absent.');
 $db->beginTransaction();
 childReject(fn()=>SaasChildMigration::apply(),'DDL inside a transaction must be rejected.');
 childCheck($db->inTransaction(),'Rejected DDL must leave the caller transaction open.');
 $db->rollBack();
 DB::run("UPDATE settings SET value='20' WHERE `key`='schema_version'");
 childReject(fn()=>SaasChildMigration::apply(),'Removed SaaS schema 20 must remain rejected.');
 DB::run("UPDATE settings SET value='30' WHERE `key`='schema_version'");

 $result=SaasChildMigration::apply();
 childCheck($result['children_version']===3&&$result['multi_account_enabled']===false,'Migration must complete without activating SaaS.');
 childCheck(SaasChildMigration::issues(true)===[],'All child owner columns must be populated.');
 childCheck(SaasChildMigration::relationIssues(true)===[],'Every primary and secondary owner relation must agree.');
 foreach($existing as $table=>$id){
  $stored=DB::one('SELECT id,tenant_account_id FROM `'.$table.'` WHERE id=?',[$id]);
  childCheck((int)($stored['id']??0)===$id&&(int)($stored['tenant_account_id']??0)===$legacy,
   'Migrating must preserve primary keys and ownership of '.$table);
 }
 childCheck((int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0)===30,'Operational schema marker remains 30.');
 childCheck((int)(DB::one('SELECT value FROM settings WHERE `key`=?',[SaasChildMigration::MARKER])['value']??0)===3,'Stage-specific marker is written.');
 childCheck(SaasChildMigration::apply()['internal_account_id']===$legacy,'Repeated B1.2b migrations must not duplicate or change rows.');

 // B1.2b owner-aware access wrapper denies foreign resources.
 childCheck(AccountOwnership::row('files',$file,$legacy,$user)['label']==='Existing video','Member must read own file through verified scope.');
 childCheck(AccountOwnership::row('payments',$payment,$legacy,$user)['amount_cents']==100,'Member must read own payment through verified scope.');
 $other=DB::insert('accounts',['slug'=>'ci-tenant-b','name'=>'Other test account','account_type'=>'customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
 DB::insert('account_members',['account_id'=>$other,'user_id'=>$outsider,'membership_role'=>'member','status'=>'active','joined_at'=>now()]);
 DB::run('DELETE FROM account_members WHERE account_id=? AND user_id=?',[$legacy,$outsider]);
 $foreignClient=DB::insert('clients',['tenant_account_id'=>$other,'name'=>'Foreign client','created_at'=>now()]);
 $foreignDemand=DB::insert('demands',[
  'tenant_account_id'=>$other,'client_id'=>$foreignClient,'category_id'=>1,'stage_id'=>1,
  'title'=>'Another account task','brief'=>'isolated','details_json'=>'{}',
  'due_at'=>now(),'created_by'=>$outsider,'created_at'=>now(),'updated_at'=>now()
 ]);
 $foreignFile=DB::insert('files',['tenant_account_id'=>$other,'demand_id'=>$foreignDemand,'author_id'=>$outsider,'label'=>'Other file','created_at'=>now()]);
 childCheck(count(AccountOwnership::listing('files',$legacy,$user))===1,'Legacy scoped file list must not include other tenant files.');
 childCheck(count(AccountOwnership::listing('files',$other,$outsider))===1,'Other scoped file list must not include legacy files.');
 childReject(fn()=>AccountOwnership::row('files',$foreignFile,$legacy,$user),'Tenant A cannot read tenant B file by ID.');
 childReject(fn()=>AccountOwnership::row('files',$file,$other,$outsider),'Tenant B cannot read tenant A file by ID.');
 childReject(fn()=>AccountOwnership::row('payments',$payment,$other,$outsider),'Tenant B cannot read tenant A payments.');
 childReject(fn()=>AccountOwnership::row('files',$file,$legacy,$outsider),'Membership must be checked, even if row is owned by account.');

 // A true two-parent relationship must be rejected before activation.
 $foreignCategory=DB::insert('categories',['tenant_account_id'=>$other,'name'=>'Foreign category','fields_json'=>'[]']);
 DB::run('UPDATE contract_items SET category_id=? WHERE id=?',[$foreignCategory,$item]);
 childCheck(in_array('secondary_contract_items_category_id',SaasChildMigration::relationIssues(true),true),
  'Foreign contract-item category must be detected.');
 DB::run('UPDATE contract_items SET category_id=1 WHERE id=?',[$item]);
 DB::run('UPDATE events SET demand_id=? WHERE id=?',[$foreignDemand,$event]);
 childCheck(in_array('secondary_events_demand_id',SaasChildMigration::relationIssues(true),true),
  'Event linked to another tenant task must be detected.');
 DB::run('UPDATE events SET demand_id=? WHERE id=?',[$demand,$event]);
 DB::run('UPDATE files SET tenant_account_id=? WHERE id=?',[$legacy,$foreignFile]);
 childCheck(in_array('parent_files',SaasChildMigration::relationIssues(true),true),
  'File ownership differing from its parent task must be detected.');
 DB::run('UPDATE files SET tenant_account_id=? WHERE id=?',[$other,$foreignFile]);
 childCheck(SaasChildMigration::relationIssues(true)===[],
  'The restored foreign and legacy child graph must be consistent.');
 childReject(fn()=>SaasChildMigration::apply(),'Legacy backfill must not execute when more than one account exists.');
 echo "SaaS B1.2b: child backfill, related parents, A/B isolation, retries, schema-20 block and CI guard OK\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
