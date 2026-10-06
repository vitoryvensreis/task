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
    $token = $unavailable->connectionToken(42, 1700000000);
    $parts = explode('.', $token);
    check(count($parts) === 3, 'Connection JWT must have three segments');
    $decode = static function(string $value): array {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        return json_decode((string)base64_decode($value, true), true, 8, JSON_THROW_ON_ERROR);
    };
    $header = $decode($parts[0]); $claims = $decode($parts[1]);
    check(($header['alg'] ?? null) === 'HS256' && ($claims['sub'] ?? null) === '42', 'Connection JWT must use HS256 and string sub');
    check(($claims['iat'] ?? null) === 1700000000 && ($claims['exp'] ?? null) === 1700000300, 'Connection JWT TTL must be 300 seconds');
    $sub = $unavailable->subscriptionToken(42, 'user:42', 1700000000);
    $subParts = explode('.', $sub); $subClaims = $decode($subParts[1]);
    check(($subClaims['sub'] ?? null) === '42' && ($subClaims['channel'] ?? null) === 'user:42', 'Subscription JWT must bind exact channel');
    check(hash_equals(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true), (function(string $v): string {$v=strtr($v,'-_','+/');$v.=str_repeat('=',(4-strlen($v)%4)%4);return (string)base64_decode($v,true);})($parts[2])), 'Connection JWT signature must use private HMAC secret');
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
