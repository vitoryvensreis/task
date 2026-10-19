<?php
declare(strict_types=1);
/**
 * Explicit, backup-gated, idempotent installation of access plan metadata.
 * This is NOT the removed SaaS schema. No second workspace is created.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$manifestArg='';
foreach($argv as $arg)if(str_starts_with($arg,'--backup-manifest='))$manifestArg=substr($arg,18);
try {
 if(!in_array('--apply',$argv,true))throw new RuntimeException('Specify --apply with verified --backup-manifest.');
 $folder=realpath(ROOT.'/storage/backups');
 $manifest=realpath($manifestArg);
 if(!$folder||!$manifest||dirname($manifest)!==$folder||!is_file($manifest))
  throw new RuntimeException('Backup manifest not inside TaskEsc backups directory.');
 $lines=file_get_contents($manifest);
 if($lines===false||!preg_match('/^Arquivo do banco: (taskesc-predeploy-[^\r\n\/]+\.sql\.gz)$/m',$lines,$m))
  throw new RuntimeException('Invalid predeploy backup manifest.');
 $backup=$folder.'/'.$m[1];$shaFile=$backup.'.sha256';
 if(!is_file($backup)||!is_file($shaFile))throw new RuntimeException('Database backup or checksum missing.');
 $expected=preg_split('/\s+/',trim((string)file_get_contents($shaFile)))[0]??'';
 if(!preg_match('/\A[a-f0-9]{64}\z/D',$expected)||!hash_equals($expected,hash_file('sha256',$backup)))
  throw new RuntimeException('Database backup checksum mismatch.');
 $schema=(int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']??0);
 if($schema!==30||!WorkspaceFoundationGuard::valid())
  throw new RuntimeException('Workspace schema 30 foundation is required.');
 $ws=DB::one('SELECT id FROM workspaces WHERE slug=?',[WorkspaceFoundation::SLUG]);
 $multi=WorkspaceTenantMode::enabled();
 $count=(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0);
 if(!$ws||$count!==($multi?2:1))
  throw new RuntimeException('Unexpected workspace plan installation state.');
 $sql=file_get_contents(ROOT.'/database/migrations/031_platform_access_plans.sql');
 if($sql===false)throw new RuntimeException('Migration missing.');
 foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement)
  if(trim($statement)!=='')DB::run($statement);
 $features=json(['tasks'=>true,'automations'=>true,'contacts'=>true]);
 DB::tx(static function()use($ws,$features):void {
  DB::run("INSERT INTO platform_access_plans (name,code,price_cents,billing_period,features_json,team_limit,public_visible,active,created_at,updated_at)
    VALUES ('Legado ilimitado','legacy-unlimited',0,'monthly',?,NULL,0,1,?,?)
    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",[$features,now(),now()]);
  $id=(int)(DB::one('SELECT id FROM platform_access_plans WHERE code=?',['legacy-unlimited'])['id']??0);
  if($id<1)throw new RuntimeException('Unlimited plan unavailable.');
  DB::run('INSERT IGNORE INTO workspace_access_plans (workspace_id,plan_id,assigned_by,assigned_at) VALUES (?,?,NULL,?)',[(int)$ws['id'],$id,now()]);
  DB::run("INSERT INTO settings (`key`,value) VALUES ('platform_access_v1','1') ON DUPLICATE KEY UPDATE value=VALUES(value)");
 });
 echo json(['applied'=>true,'schema'=>30,'workspace_count'=>$count,'legacy_plan'=>'legacy-unlimited','second_workspace_enabled'=>$multi]).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,json(['applied'=>false,'error'=>'platform_access_install_blocked','message'=>$e->getMessage()]).PHP_EOL);exit(2);}
