<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('TASKESC_REALTIME_SMOKE') !== '1') {
    fwrite(STDERR, "realtime_smoke_disabled\n");
    exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';

function smokeNeed(bool $condition, string $code): void {
    if (!$condition) throw new RuntimeException($code);
}
function smokeHttp(string $url, string $sessionId, string $origin, array $payload): array {
    $ch = curl_init($url);
    smokeNeed($ch !== false, 'curl_init_failed');
    try {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Origin: '.$origin,
                'Sec-Fetch-Site: same-origin',
            ],
            CURLOPT_COOKIE => 'taskesc='.$sessionId,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        smokeNeed(is_string($body), 'http_request_failed');
        $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        smokeNeed(is_array($json), 'http_json_invalid');
        return [$status, $json];
    } finally {
        curl_close($ch);
    }
}
function smokeReadExact($stream, int $length): string {
    $buffer = '';
    while (strlen($buffer) < $length) {
        $part = fread($stream, $length - strlen($buffer));
        smokeNeed(is_string($part) && $part !== '', 'websocket_read_failed');
        $buffer .= $part;
    }
    return $buffer;
}
function smokeWriteFrame($stream, string $payload, int $opcode = 1): void {
    $length = strlen($payload);
    $head = chr(0x80 | ($opcode & 0x0f));
    if ($length < 126) $head .= chr(0x80 | $length);
    elseif ($length <= 65535) $head .= chr(0x80 | 126).pack('n', $length);
    else $head .= chr(0x80 | 127).pack('NN', 0, $length);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $length; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
    smokeNeed(fwrite($stream, $head.$mask.$masked) !== false, 'websocket_write_failed');
}
function smokeReadText($stream): string {
    for ($frames = 0; $frames < 8; $frames++) {
        $first = ord(smokeReadExact($stream, 1));
        $second = ord(smokeReadExact($stream, 1));
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7f;
        if ($length === 126) $length = unpack('n', smokeReadExact($stream, 2))[1];
        elseif ($length === 127) {
            $parts = unpack('Nhigh/Nlow', smokeReadExact($stream, 8));
            smokeNeed((int)$parts['high'] === 0, 'websocket_frame_too_large');
            $length = (int)$parts['low'];
        }
        smokeNeed($length <= 65536, 'websocket_frame_too_large');
        $mask = $masked ? smokeReadExact($stream, 4) : '';
        $payload = $length ? smokeReadExact($stream, $length) : '';
        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) $decoded .= $payload[$i] ^ $mask[$i % 4];
            $payload = $decoded;
        }
        if ($opcode === 9) { smokeWriteFrame($stream, $payload, 10); continue; }
        if ($opcode === 8) throw new RuntimeException('websocket_closed');
        if ($opcode === 1) return $payload;
    }
    throw new RuntimeException('websocket_no_text_reply');
}
function smokeReplyById(string $payload, int $id): ?array {
    foreach (preg_split('/\r?\n/', trim($payload)) ?: [] as $line) {
        if ($line === '') continue;
        $value = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($value) && (int)($value['id'] ?? 0) === $id) return $value;
    }
    return null;
}
function smokeWebSocket(string $url, string $origin, string $connectionToken, string $channel, string $subscriptionToken): void {
    $parts = parse_url($url);
    smokeNeed(is_array($parts) && ($parts['scheme'] ?? '') === 'wss' && !empty($parts['host']), 'websocket_url_invalid');
    $host = (string)$parts['host'];
    $port = (int)($parts['port'] ?? 443);
    $path = (string)($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
    ]]);
    $errno = 0; $errstr = '';
    $stream = @stream_socket_client('ssl://'.$host.':'.$port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
    smokeNeed(is_resource($stream), 'websocket_connect_failed');
    stream_set_timeout($stream, 8);
    try {
        $key = base64_encode(random_bytes(16));
        $request = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$origin}\r\n\r\n";
        smokeNeed(fwrite($stream, $request) !== false, 'websocket_handshake_write_failed');
        $headers = '';
        while (!str_contains($headers, "\r\n\r\n") && strlen($headers) < 16384) {
            $line = fgets($stream);
            smokeNeed(is_string($line) && $line !== '', 'websocket_handshake_read_failed');
            $headers .= $line;
        }
        smokeNeed((bool)preg_match('/^HTTP\/1\.[01] 101\b/', $headers), 'websocket_upgrade_failed');
        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        smokeNeed((bool)preg_match('/\r\nSec-WebSocket-Accept:\s*'.preg_quote($expected, '/').'\s*\r\n/i', $headers), 'websocket_accept_invalid');

        smokeWriteFrame($stream, json_encode(['id' => 1, 'connect' => ['token' => $connectionToken, 'name' => 'taskesc-smoke', 'version' => 'step4']], JSON_THROW_ON_ERROR));
        $reply = smokeReplyById(smokeReadText($stream), 1);
        smokeNeed(is_array($reply) && !isset($reply['error']) && isset($reply['connect']), 'centrifugo_connect_rejected');

        smokeWriteFrame($stream, json_encode(['id' => 2, 'subscribe' => ['channel' => $channel, 'token' => $subscriptionToken]], JSON_THROW_ON_ERROR));
        $reply = smokeReplyById(smokeReadText($stream), 2);
        smokeNeed(is_array($reply) && !isset($reply['error']) && isset($reply['subscribe']), 'centrifugo_subscribe_rejected');
        smokeWriteFrame($stream, '', 8);
    } finally {
        fclose($stream);
    }
}

$sessionId = bin2hex(random_bytes(24));
$sessionFile = ROOT.'/storage/sessions/sess_'.$sessionId;
try {
    smokeNeed(function_exists('curl_init'), 'curl_missing');
    $user = DB::one("SELECT id,role FROM users WHERE active=1 ORDER BY CASE role WHEN 'admin' THEN 0 WHEN 'staff' THEN 1 ELSE 2 END,id LIMIT 1");
    smokeNeed(is_array($user) && (int)$user['id'] > 0, 'active_user_missing');
    $sessionDir = ROOT.'/storage/sessions';
    if (!is_dir($sessionDir)) mkdir($sessionDir, 0700, true);
    session_save_path($sessionDir);
    session_name('taskesc');
    session_id($sessionId);
    session_start();
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    session_write_close();

    $base = rtrim((string)config('base_url', ''), '/');
    $baseParts = parse_url($base);
    smokeNeed(is_array($baseParts) && isset($baseParts['scheme'], $baseParts['host']), 'base_url_invalid');
    $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
    [$status, $connection] = smokeHttp($base.'/realtime.auth', $sessionId, $origin, []);
    smokeNeed($status === 200 && is_string($connection['token'] ?? null) && is_string($connection['websocket'] ?? null), 'realtime_connection_token_failed');
    $channel = 'user:'.(int)$user['id'];
    [$status, $subscription] = smokeHttp($base.'/realtime.auth', $sessionId, $origin, ['channel' => $channel]);
    smokeNeed($status === 200 && ($subscription['channel'] ?? null) === $channel && is_string($subscription['token'] ?? null), 'realtime_subscription_token_failed');
    [$denied, $deniedBody] = smokeHttp($base.'/realtime.auth', $sessionId, $origin, ['channel' => 'user:2147483647']);
    smokeNeed($denied === 403 && isset($deniedBody['error']), 'realtime_permission_guard_failed');

    smokeWebSocket((string)$connection['websocket'], $origin, (string)$connection['token'], $channel, (string)$subscription['token']);
    echo json_encode([
        'status' => 'ok',
        'session_auth' => true,
        'connection_jwt' => true,
        'subscription_jwt' => true,
        'unauthorized_channel_denied' => true,
        'websocket_authenticated' => true,
        'channel' => $channel,
        'centrifugo_expected' => Realtime::CENTRIFUGO_VERSION,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    $code = ($e instanceof RuntimeException && preg_match('/^[a-z0-9_]+$/', $e->getMessage())) ? $e->getMessage() : 'realtime_smoke_failed';
    fwrite(STDERR, json_encode(['status' => 'error', 'code' => $code], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @unlink($sessionFile);
}
