<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$status=200;
$checks=[
 'php'=>['ok'=>version_compare(PHP_VERSION,'8.1.0','>='),'version'=>PHP_VERSION],
 'database'=>['ok'=>false],
 'schema'=>['ok'=>false,'current'=>null,'expected'=>TASKESC_SCHEMA_VERSION],
 'storage'=>['ok'=>false,'readable'=>false,'writable'=>false],
 'read'=>['ok'=>false],
 'installed'=>['ok'=>is_file(ROOT.'/storage/installed.lock')],
];

try{
 if(!config())throw new RuntimeException('Configuração indisponível.');
 DB::one('SELECT 1 AS ok');
 $checks['database']['ok']=true;

 $schema=(int)setting('schema_version','0');
 $checks['schema']['current']=$schema;
 $checks['schema']['ok']=$schema>=TASKESC_SCHEMA_VERSION;

 $brand=setting('brand_name','TaskEsc');
 $checks['read']=['ok'=>$brand!=='','sample'=>'settings'];

 $storage=ROOT.'/storage';
 $checks['storage']['readable']=is_dir($storage)&&is_readable($storage);
 $checks['storage']['writable']=is_dir($storage)&&is_writable($storage);
 if($checks['storage']['writable']){
  $probe=$storage.'/.health-'.bin2hex(random_bytes(6));
  $payload='taskesc-health';
  $written=@file_put_contents($probe,$payload,LOCK_EX);
  $read=$written!==false&&@file_get_contents($probe)===$payload;
  if(is_file($probe))@unlink($probe);
  $checks['storage']['ok']=$read;
 }
}catch(Throwable){
 $checks['database']['ok']=false;
}

foreach($checks as $check){if(($check['ok']??false)!==true){$status=503;break;}}
$info=SystemInfo::info();
$out=[
 'status'=>$status===200?'ok':'error',
 'version'=>$info['version'],
 'commit'=>$info['commit_short'],
 'schema'=>$checks['schema']['current'],
 'database'=>$checks['database']['ok'],
 'storage'=>$checks['storage']['ok'],
 'installed'=>$checks['installed']['ok'],
 'checks'=>$checks,
 'time'=>gmdate('c'),
];

http_response_code($status);
echo json($out);
