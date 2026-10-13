<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
$port = getenv('OUTBOX_TEST_PORT');
if (!$port) throw new RuntimeException('Run only against the disposable CI database.');

$configPath = ROOT.'/config/config.php';
if (is_file($configPath)) throw new RuntimeException('Refusing to overwrite an existing config.');
$config = [
    'base_url' => 'https://task.escotilhaprodutora.com',
    'timezone' => 'America/Fortaleza',
    'session_lifetime_seconds' => 86400,
    'db' => ['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
    'app_key' => bin2hex(random_bytes(32)),
    'cron_key' => '',
];
file_put_contents($configPath, "<?php\nreturn ".var_export($config, true).";\n");
@mkdir(ROOT.'/storage/sessions', 0700, true);
touch(ROOT.'/storage/installed.lock');

require ROOT.'/app/bootstrap.php';

function rtCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rtStatus(callable $fn, int $status): void {
    try { $fn(); } catch (AppError $e) { rtCheck($e->status === $status, 'Unexpected authorization status'); return; }
    throw new RuntimeException('Expected authorization failure');
}
function rtReset(int $userId, ?int $previewClientId = null): array {
    $_SESSION = ['user_id'=>$userId, 'csrf'=>'ci-csrf'];
    if ($previewClientId !== null) $_SESSION['preview_client_id'] = $previewClientId;
    Auth::$user = null; Auth::$actor = null; Auth::$previewClientId = null; Auth::$origin = 'web'; Auth::$scopes = 'tools.read tools.write';
    return Auth::load();
}
function rtDecodeJwt(string $jwt, string $secret): array {
    $parts = explode('.', $jwt);
    rtCheck(count($parts) === 3, 'JWT must have three segments');
    $decode = static function(string $value): string {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $raw = base64_decode($value, true);
        if ($raw === false) throw new RuntimeException('Invalid base64url');
        return $raw;
    };
    $header = json_decode($decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
    $claims = json_decode($decode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
    $signature = $decode($parts[2]);
    rtCheck(($header['alg'] ?? null) === 'HS256' && ($header['typ'] ?? null) === 'JWT', 'JWT header must be HS256');
    rtCheck(hash_equals(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true), $signature), 'JWT signature mismatch');
    return $claims;
}

$configFile = tempnam(sys_get_temp_dir(), 'taskesc-realtime-auth-');
$secret = 'ci-hmac-'.bin2hex(random_bytes(16));
file_put_contents($configFile, json_encode([
    'REALTIME_ENABLED'=>true,
    'REDIS_HOST'=>'127.0.0.1','REDIS_PORT'=>6379,
    'CENTRIFUGO_API_URL'=>'http://127.0.0.1:8000/api',
    'CENTRIFUGO_WS_URL'=>'wss://task.escotilhaprodutora.com/realtime/connection/websocket',
    'CENTRIFUGO_HMAC_SECRET'=>$secret,'CENTRIFUGO_API_KEY'=>'ci-api',
], JSON_THROW_ON_ERROR));

$ids = [];
try {
    session_save_path(ROOT.'/storage/sessions');
    session_name('taskesc-ci');
    session_id(bin2hex(random_bytes(24)));
    session_start();

    $password = 'Taskesc-ci-pass!123';
    $admin = DB::insert('users',['name'=>'Admin RT','email'=>'admin-rt@ci.invalid','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'admin','active'=>1,'created_at'=>now()]);
    $staff = DB::insert('users',['name'=>'Staff RT','email'=>'staff-rt@ci.invalid','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'staff','active'=>1,'created_at'=>now()]);
    $client = DB::insert('users',['name'=>'Client RT','email'=>'client-rt@ci.invalid','password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'client','active'=>1,'created_at'=>now()]);
    $ids['users']=[$admin,$staff,$client];

    $c1 = DB::insert('clients',['name'=>'RT Client A','created_at'=>now()]);
    $c2 = DB::insert('clients',['name'=>'RT Client B','created_at'=>now()]);
    $ids['clients']=[$c1,$c2];
    DB::run('INSERT INTO user_clients (user_id,client_id,can_approve,can_finance) VALUES (?,?,1,0),(?,?,1,0)',[$staff,$c1,$client,$c1]);

    $stage = DB::insert('stages',['name'=>'RT Stage','kind'=>'production','position'=>900]);
    $category = DB::insert('categories',['name'=>'RT Category','fields_json'=>'[]']);
    $ids['stage']=$stage;$ids['category']=$category;
    $p1 = DB::insert('projects',['client_id'=>$c1,'name'=>'RT Project A','created_at'=>now()]);
    $p2 = DB::insert('projects',['client_id'=>$c2,'name'=>'RT Project B','created_at'=>now()]);
    $ids['projects']=[$p1,$p2];
    $d1 = DB::insert('demands',[
        'client_id'=>$c1,'project_id'=>$p1,'category_id'=>$category,'stage_id'=>$stage,'title'=>'RT Demand A',
        'brief'=>'x','details_json'=>'{}','due_at'=>gmdate('Y-m-d H:i:s',time()+86400),'created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()
    ]);
    $d2 = DB::insert('demands',[
        'client_id'=>$c2,'project_id'=>$p2,'category_id'=>$category,'stage_id'=>$stage,'title'=>'RT Demand B',
        'brief'=>'x','details_json'=>'{}','due_at'=>gmdate('Y-m-d H:i:s',time()+86400),'created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()
    ]);
    $ids['demands']=[$d1,$d2];

    // Real login path -> authenticated session identity.
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_SESSION=[]; Auth::$user=null; Auth::$actor=null; Auth::$previewClientId=null; Auth::$origin='web';
    $login=Auth::login(['email'=>'staff-rt@ci.invalid','password'=>$password]);
    rtCheck((int)$login['user']['id']===$staff,'Auth::login must authenticate staff fixture');

    $service = new RealtimeAuth(new Realtime($configFile));
    $connection = $service->connection([]);
    rtCheck($connection['channels']===['user:'.$staff,'staff/global','agenda/global','client:'.$c1],'Staff bootstrap channels must include staff, agenda and authorized client collections');
    $claims = rtDecodeJwt($connection['token'],$secret);
    rtCheck(($claims['sub']??null)===(string)$staff,'Connection JWT sub must be authenticated user string');
    rtCheck((int)$claims['exp']-(int)$claims['iat']===Realtime::TOKEN_TTL_SECONDS,'Connection JWT TTL mismatch');

    $subscription = $service->subscription(['channel'=>'demand:'.$d1]);
    $subClaims = rtDecodeJwt($subscription['token'],$secret);
    rtCheck(($subClaims['sub']??null)===(string)$staff && ($subClaims['channel']??null)==='demand:'.$d1,'Subscription JWT must bind sub and exact channel');
    $service->subscription(['channel'=>'project:'.$p1]);
    $service->subscription(['channel'=>'client:'.$c1]);
    $service->subscription(['channel'=>'user:'.$staff]);
    $service->subscription(['channel'=>'staff/global']);
    $service->subscription(['channel'=>'agenda/global']);
    rtStatus(fn()=> $service->subscription(['channel'=>'wpp/global']),403);
    rtStatus(fn()=> $service->subscription(['channel'=>'demand:'.$d2]),403);
    rtStatus(fn()=> $service->subscription(['channel'=>'project:'.$p2]),403);
    rtStatus(fn()=> $service->subscription(['channel'=>'client:'.$c2]),403);
    rtStatus(fn()=> $service->subscription(['channel'=>'user:'.$admin]),403);
    rtStatus(fn()=> $service->subscription(['channel'=>'kanban/global']),403);
    rtStatus(fn()=> $service->connection(['user_id'=>$admin]),400);
    rtStatus(fn()=> $service->subscription(['channel'=>'demand:'.$d1,'client_id'=>$c2]),400);

    $adminUser=rtReset($admin);
    $adminService=new RealtimeAuth(new Realtime($configFile));
    rtCheck($adminService->connection([])['channels']===['user:'.$admin,'staff/global','agenda/global','kanban/global','wpp/global'],'Admin bootstrap must include personal, staff, agenda, kanban and WPP globals');
    $adminService->subscription(['channel'=>'kanban/global']);
    $adminService->subscription(['channel'=>'staff/global']);
    $adminService->subscription(['channel'=>'agenda/global']);
    $adminService->subscription(['channel'=>'wpp/global']);
    $adminService->subscription(['channel'=>'demand:'.$d2]);
    $adminService->subscription(['channel'=>'project:'.$p2]);

    $clientUser=rtReset($client);
    $clientService=new RealtimeAuth(new Realtime($configFile));
    rtCheck($clientService->connection([])['channels']===['user:'.$client,'client:'.$c1],'Client bootstrap must include linked client collection');
    $clientService->subscription(['channel'=>'client:'.$c1]);
    $clientService->subscription(['channel'=>'demand:'.$d1]);
    $clientService->subscription(['channel'=>'project:'.$p1]);
    rtStatus(fn()=> $clientService->subscription(['channel'=>'client:'.$c2]),403);
    rtStatus(fn()=> $clientService->subscription(['channel'=>'demand:'.$d2]),403);
    rtStatus(fn()=> $clientService->subscription(['channel'=>'kanban/global']),403);
    rtStatus(fn()=> $clientService->subscription(['channel'=>'staff/global']),403);
    rtStatus(fn()=> $clientService->subscription(['channel'=>'agenda/global']),403);
    rtStatus(fn()=> $clientService->subscription(['channel'=>'wpp/global']),403);

    $previewUser=rtReset($admin,$c1);
    $previewService=new RealtimeAuth(new Realtime($configFile));
    rtCheck($previewUser['role']==='client' && $previewService->connection([])['channels']===['client:'.$c1],'Preview must receive only its selected client collection');
    $previewService->subscription(['channel'=>'client:'.$c1]);
    $previewService->subscription(['channel'=>'demand:'.$d1]);
    $previewService->subscription(['channel'=>'project:'.$p1]);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'client:'.$c2]),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'demand:'.$d2]),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'project:'.$p2]),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'user:'.$admin]),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'kanban/global']),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'staff/global']),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'agenda/global']),403);
    rtStatus(fn()=> $previewService->subscription(['channel'=>'wpp/global']),403);

    Auth::$origin='api';Auth::$user=$adminUser;Auth::$actor=$adminUser;Auth::$previewClientId=null;
    rtStatus(fn()=> (new RealtimeAuth(new Realtime($configFile)))->connection([]),403);

    echo "Realtime auth: login, HS256 JWT, session identity, admin/staff/client/preview channel permissions and identity-hint rejection OK\n";
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    try { if(!empty($ids['demands'])) DB::run('DELETE FROM demands WHERE id IN ('.implode(',',array_fill(0,count($ids['demands']),'?')).')',$ids['demands']); } catch(Throwable){}
    try { if(!empty($ids['projects'])) DB::run('DELETE FROM projects WHERE id IN ('.implode(',',array_fill(0,count($ids['projects']),'?')).')',$ids['projects']); } catch(Throwable){}
    try { if(!empty($ids['clients'])) DB::run('DELETE FROM user_clients WHERE client_id IN ('.implode(',',array_fill(0,count($ids['clients']),'?')).')',$ids['clients']); } catch(Throwable){}
    try { if(!empty($ids['projects'])){} } catch(Throwable){}
    try { if(!empty($ids['clients'])) DB::run('DELETE FROM clients WHERE id IN ('.implode(',',array_fill(0,count($ids['clients']),'?')).')',$ids['clients']); } catch(Throwable){}
    try { if(!empty($ids['category'])) DB::run('DELETE FROM categories WHERE id=?',[$ids['category']]); } catch(Throwable){}
    try { if(!empty($ids['stage'])) DB::run('DELETE FROM stages WHERE id=?',[$ids['stage']]); } catch(Throwable){}
    try { if(!empty($ids['users'])) { DB::run('DELETE FROM audit_log WHERE user_id IN ('.implode(',',array_fill(0,count($ids['users']),'?')).')',$ids['users']); DB::run('DELETE FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids['users']),'?')).')',$ids['users']); } } catch(Throwable){}
    try { DB::run('DELETE FROM rate_limits WHERE bucket=?',[hash('sha256','login|127.0.0.1')]); } catch(Throwable){}
    @unlink($configFile);
    @unlink($configPath);
    @unlink(ROOT.'/storage/installed.lock');
}
