<?php
declare(strict_types=1);

if (in_array('--fake-server', $argv, true)) {
    $capture = $argv[array_search('--fake-server', $argv, true) + 1] ?? '';
    $server = @stream_socket_server('tcp://127.0.0.1:18080', $errno, $error);
    if (!$server) { fwrite(STDERR, "fake_server_start_failed\n"); exit(1); }
    echo "ready\n"; flush();
    $conn = @stream_socket_accept($server, 10);
    if (!$conn) { fwrite(STDERR, "fake_server_accept_failed\n"); exit(1); }
    stream_set_timeout($conn, 5);
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n") && !feof($conn)) $raw .= (string)fgets($conn);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    preg_match('/^Content-Length:\s*(\d+)$/mi', $head, $lengthMatch);
    $length = (int)($lengthMatch[1] ?? 0);
    while (strlen($body) < $length && !feof($conn)) $body .= (string)fread($conn, $length - strlen($body));
    $ok = str_starts_with($head, 'POST /api/publish HTTP/1.')
        && preg_match('/^X-API-Key:\s*ci-api-key$/mi', $head)
        && is_array(json_decode($body, true));
    @file_put_contents($capture, json_encode(['ok'=>(bool)$ok,'body'=>json_decode($body,true)], JSON_UNESCAPED_SLASHES));
    $response = $ok ? '{"result":{}}' : '{"error":{}}';
    fwrite($conn, "HTTP/1.1 ".($ok?'200 OK':'400 Bad Request')."\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($response)."\r\n\r\n".$response);
    fclose($conn); fclose($server); exit($ok ? 0 : 1);
}

define('ROOT', dirname(__DIR__));
function config(?string $key=null,mixed $default=null): mixed {
    if(!getenv('OUTBOX_TEST_PORT'))throw new RuntimeException('Run only against the explicit disposable CI database.');
    $c=['db'=>['host'=>'127.0.0.1','port'=>(int)getenv('OUTBOX_TEST_PORT'),'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only']];
    return $key===null?$c:($c[$key]??$default);
}
require ROOT.'/app/DB.php';
require ROOT.'/app/EventOutbox.php';
require ROOT.'/app/Realtime.php';
require ROOT.'/app/CentrifugoPublisher.php';
require ROOT.'/app/RealtimeOutboxWorker.php';
function checkWorker(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function makeEvent(string $key,string $channel='diagnostic'): int {
    return EventOutbox::transaction(fn()=>EventOutbox::enqueue($key,'realtime.test','system',1,$channel,['value'=>'ci']));
}

checkWorker((bool)DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='event_outbox'"),'Run tests/outbox.php first');
DB::run('UPDATE event_outbox SET published_at=UTC_TIMESTAMP(6) WHERE published_at IS NULL');

$capture=tempnam(sys_get_temp_dir(),'taskesc-publish-');
$configPath=tempnam(sys_get_temp_dir(),'taskesc-realtime-');
file_put_contents($configPath,json_encode([
    'REALTIME_ENABLED'=>false,'REDIS_HOST'=>'127.0.0.1','REDIS_PORT'=>6379,
    'CENTRIFUGO_API_URL'=>'http://127.0.0.1:18080/api',
    'CENTRIFUGO_WS_URL'=>'wss://task.escotilhaprodutora.com/realtime/connection/websocket',
    'CENTRIFUGO_HMAC_SECRET'=>'ci-hmac-key','CENTRIFUGO_API_KEY'=>'ci-api-key',
],JSON_THROW_ON_ERROR));
$proc=proc_open([PHP_BINARY,__FILE__,'--fake-server',$capture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
checkWorker(is_resource($proc),'Fake Centrifugo did not start');fclose($pipes[0]);
$read=[$pipes[1]];$write=$except=null;
checkWorker(stream_select($read,$write,$except,5)===1&&trim((string)fgets($pipes[1]))==='ready','Fake Centrifugo readiness failed');

$id=makeEvent('worker-http-publish');
$publisher=new CentrifugoPublisher(new Realtime($configPath));
$worker=new RealtimeOutboxWorker([$publisher,'publish']);
$result=$worker->processBatch(10);
checkWorker($result['published']===1&&$result['retried']===0,'Worker must publish through Centrifugo HTTP API');
$row=DB::one('SELECT * FROM event_outbox WHERE id=?',[$id]);
checkWorker($row['published_at']!==null&&$row['last_error']===null,'Successful publish must set published_at');
$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
checkWorker(proc_close($proc)===0&&$errors==='','Fake Centrifugo request failed');
$caught=json_decode((string)file_get_contents($capture),true);
checkWorker(($caught['ok']??false)===true,'Publisher must use POST /api/publish and X-API-Key');
checkWorker(($caught['body']['channel']??null)==='diagnostic','Publisher must preserve event channel');
checkWorker(($caught['body']['idempotency_key']??null)===$row['event_key'],'Publisher must send event_key as idempotency_key');
checkWorker(($caught['body']['data']['event_type']??null)==='realtime.test','Published envelope must contain event metadata');

$retryId=makeEvent('worker-retry');
$failing=new RealtimeOutboxWorker(static function(array $event): void {throw new RuntimeException('centrifugo_transport');});
$retryResult=$failing->processBatch(10);
$retry=DB::one('SELECT * FROM event_outbox WHERE id=?',[$retryId]);
checkWorker($retryResult['retried']===1&&(int)$retry['attempts']===1&&$retry['last_error']==='centrifugo_transport','Failure must increment attempts and record a safe code');
checkWorker(strtotime((string)$retry['available_at'])>time(),'Failure must be rescheduled');
checkWorker(RealtimeOutboxWorker::backoffSeconds(0)===5&&RealtimeOutboxWorker::backoffSeconds(1)===10&&RealtimeOutboxWorker::backoffSeconds(20)===300,'Backoff must be exponential and capped');

$lockId=makeEvent('worker-lock');
$dbConfig=config('db');
$locker=new PDO("mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4",$dbConfig['user'],$dbConfig['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$lock=$locker->prepare('SELECT GET_LOCK(?,0)');$lock->execute(['taskesc:outbox:'.$lockId]);checkWorker((int)$lock->fetchColumn()===1,'Test lock acquisition failed');
$success=new RealtimeOutboxWorker(static function(array $event): void {});
$locked=$success->processBatch(10);
checkWorker($locked['locked']===1&&DB::one('SELECT published_at FROM event_outbox WHERE id=?',[$lockId])['published_at']===null,'Second worker must skip a locked event');
$release=$locker->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['taskesc:outbox:'.$lockId]);$locker=null;
$after=$success->processBatch(10);
checkWorker($after['published']===1&&DB::one('SELECT published_at FROM event_outbox WHERE id=?',[$lockId])['published_at']!==null,'Unlocked event must publish normally');

$exhaustedId=makeEvent('worker-exhausted');
DB::run('UPDATE event_outbox SET attempts=8,last_error=? WHERE id=?',['centrifugo_transport',$exhaustedId]);
$stats=EventOutbox::stats(8);
checkWorker((int)$stats['exhausted']>=1,'Retry limit must be visible in health stats');
checkWorker(!in_array($exhaustedId,array_column(EventOutbox::due(100,8),'id'),true),'Exhausted event must not be consumed again');

@unlink($capture);@unlink($configPath);
echo "Realtime worker: HTTP publish, idempotency key, CAS success/retry, exponential backoff, named lock and retry limit OK\n";
