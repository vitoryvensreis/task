<?php
declare(strict_types=1);

// Isolado: testes anteriores limpam config/config.php ao terminar.
define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');
if(!$port)throw new RuntimeException('Testes Google Health apenas no CI descartável.');
$configPath=ROOT.'/config/config.php';
if(is_file($configPath))@unlink($configPath);
$config=[
 'base_url'=>'https://task.escotilhaprodutora.com','timezone'=>'America/Fortaleza',
 'db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32)),'smtp'=>['host'=>'']
];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);
touch(ROOT.'/storage/installed.lock');
require ROOT.'/app/bootstrap.php';
function googleCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$db=DB::conn();$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(DB::all('SHOW TABLES') as $table){$name=(string)array_values($table)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))throw new RuntimeException('Tabela inesperada.');$db->exec('DROP TABLE `'.$name.'`');}
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$db->exec(file_get_contents(ROOT.'/database/schema.sql'));
$adminId=DB::insert('users',[
 'name'=>'Admin Google Health','email'=>'admin-health@ci.invalid',
 'password_hash'=>password_hash('Taskesc-ci-health!',PASSWORD_DEFAULT),
 'role'=>'admin','active'=>1,'email_notifications'=>0,'created_at'=>now()
]);
$admin=DB::one('SELECT * FROM users WHERE id=?',[$adminId]);
Auth::$user=$admin;Auth::$actor=$admin;Auth::$origin='web';Auth::$previewClientId=null;

googleCheck(Google::refreshErrorCategory(new AppError('Google: invalid_grant (400)',502,['oauth_error'=>'invalid_grant','google_status'=>400]))==='reconnect','invalid_grant must require reconnect');
googleCheck(Google::refreshErrorCategory(new AppError('Google: quota (429)',502,['google_status'=>429]))==='retry','429 must be retried');
googleCheck(Google::refreshErrorCategory(new RuntimeException('DNS unavailable'))==='retry','DNS failure must remain temporary');
googleCheck(Google::refreshErrorCategory(new AppError('Google: access denied (403)',502,['google_status'=>403]))==='review','Calendar 403 must not revoke account');

$account=DB::insert('google_accounts',[
 'user_id'=>(int)$admin['id'],'google_sub'=>'monitor-test-disconnected','email'=>'disconnected@ci.invalid',
 'access_token'=>'no-real-token','refresh_token'=>'no-real-refresh','expires_at'=>now(),'status'=>'disconnected',
 'calendar_enabled'=>1,'drive_enabled'=>0,'last_error'=>'invalid_grant'
]);
$calendar=DB::insert('calendars',['account_id'=>$account,'google_id'=>'disconnected-ci','name'=>'Agenda desconectada','selected'=>1,'last_error'=>'Google: 403']);
$baseline=(int)(DB::one("SELECT COUNT(*) n FROM notifications WHERE user_id=? AND title='Atenção no TaskEsc'",[$admin['id']])['n']??0);
$clean=SystemHealth::watch();
googleCheck(empty($clean['issues'])&&!$clean['notified'],'Disconnected account and calendar must not generate alerts');

DB::update('google_accounts',$account,['google_sub'=>'monitor-test-active','email'=>'active@ci.invalid','status'=>'connected','last_error'=>'Google: 503']);
DB::update('calendars',$calendar,['last_error'=>'Google: 503','name'=>'Agenda com erro']);
$health=SystemHealth::health();
$accountStatus=current(array_filter($health['items'],static fn($item)=>$item['key']==='google'));
$calendarStatus=current(array_filter($health['items'],static fn($item)=>$item['key']==='calendar'));
googleCheck($accountStatus['status']==='warn'&&$calendarStatus['status']==='warn','Health must expose account/calendar warnings');
googleCheck(($accountStatus['meta'][0]['hint']??'')!=='','Google health must include a diagnostic hint');

$first=SystemHealth::watch();
googleCheck($first['notified']&&count($first['issues'])===2,'First Google incident must alert');
$again=SystemHealth::watch();
googleCheck(!$again['notified'],'Same incident cannot notify again during cooldown');
$current=(int)(DB::one("SELECT COUNT(*) n FROM notifications WHERE user_id=? AND title='Atenção no TaskEsc'",[$admin['id']])['n']??0);
googleCheck($current===$baseline+1,'Only one notification on repeated cron runs');
DB::run("UPDATE settings SET value=? WHERE `key`='system_health_watch_last_sent_at'",[gmdate('Y-m-d H:i:s',time()-90000)]);
$daily=SystemHealth::watch();
googleCheck($daily['notified'],'Persistent issue should remind after 24h');
$current=(int)(DB::one("SELECT COUNT(*) n FROM notifications WHERE user_id=? AND title='Atenção no TaskEsc'",[$admin['id']])['n']??0);
googleCheck($current===$baseline+2,'Reminder must be saved only once');

DB::update('google_accounts',$account,['last_error'=>null]);
DB::update('calendars',$calendar,['last_error'=>null,'synced_at'=>now()]);
$recovered=SystemHealth::watch();
googleCheck($recovered['recovered']&&$recovered['notified'],'Recovery must send one event');
googleCheck(!SystemHealth::watch()['notified'],'Recovered status must not notify twice');
$healthy=SystemHealth::health();
$google=current(array_filter($healthy['items'],static fn($item)=>$item['key']==='google'));
googleCheck($google['status']==='ok','Healthy connection must clear warning after sync');
echo "Google health: transient OAuth, calendar diagnostics, daily dedupe and recovery OK\n";
