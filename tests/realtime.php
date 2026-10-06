<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Realtime.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$path = tempnam(sys_get_temp_dir(), 'taskesc-realtime-');
$log = tempnam(sys_get_temp_dir(), 'taskesc-realtime-log-');
ini_set('log_errors', '1');
ini_set('error_log', $log);
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) return true;
    throw new RuntimeException('Unexpected PHP warning');
});
try {
    $missing = new Realtime($path.'-missing');
    check(!$missing->enabled() && $missing->redis() === null, 'Missing config must disable realtime');
    check($missing->health()['status'] === 'degraded', 'Missing config must not throw');
    file_put_contents($path, '{invalid JSON');
    check((new Realtime($path))->health()['config'] === 'invalid', 'Invalid JSON must fail closed');
    $secret = 'test-secret-'.bin2hex(random_bytes(16));
    $values = [
        'REALTIME_ENABLED' => false, 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => 1,
        'CENTRIFUGO_API_URL' => 'http://127.0.0.1:1/api',
        'CENTRIFUGO_WS_URL' => 'wss://task.escotilhaprodutora.com/realtime/connection/websocket',
        'CENTRIFUGO_HMAC_SECRET' => $secret, 'CENTRIFUGO_API_KEY' => $secret,
    ];
    file_put_contents($path, json_encode($values));
    $disabled = new Realtime($path);
    check(!$disabled->enabled() && $disabled->redis() === null, 'Disabled flag must prevent Redis use');
    check($disabled->redisHost() === '127.0.0.1' && $disabled->redisPort() === 1, 'Private connection settings must load');
    ob_start(); var_dump($disabled); $dump = ob_get_clean();
    check(!str_contains($dump.json_encode($disabled), $secret), 'Debug/JSON must redact credentials');
    $values['REALTIME_ENABLED'] = true;
    file_put_contents($path, json_encode($values));
    $unavailable = new Realtime($path);
    $start = microtime(true);
    check($unavailable->redis() === null, 'Unavailable Redis must fail open for the application');
    $health = $unavailable->health();
    check($health['status'] === 'degraded' && $health['centrifugo'] === 'unavailable', 'Unavailable infrastructure must not throw');
    check(microtime(true) - $start < 4, 'Failure probes must have bounded timeouts');
    check(!str_contains(json_encode($health), $secret), 'Health must not expose credentials');
    $values['CENTRIFUGO_API_URL'] = 'https://example.com/api';
    file_put_contents($path, json_encode($values));
    check(!(new Realtime($path))->enabled(), 'Credentials must never go to a remote API');
    $values['CENTRIFUGO_API_URL'] = 'http://127.0.0.1:8000/api';
    $values['REALTIME_ENABLED'] = 'false';
    file_put_contents($path, json_encode($values));
    check(!(new Realtime($path))->enabled(), 'String booleans must not enable realtime');
    $values['REALTIME_ENABLED'] = true;
    $values['CENTRIFUGO_API_KEY'] = $secret."\r\nInjected: header";
    file_put_contents($path, json_encode($values));
    check(!(new Realtime($path))->enabled(), 'Header injection must be rejected');
    check(!str_contains(file_get_contents($log), $secret), 'Failures must not log credentials');
    check(session_status() === PHP_SESSION_NONE, 'Realtime must not start PHP sessions');
    echo "Realtime fallback, feature flag, timeout and secret redaction: OK\n";
} finally {
    restore_error_handler();
    @unlink($path);
    @unlink($log);
}
