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
function smokeHttp(string $url, string $sessionId, string $csrf, string $origin, array $payload): array {
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
                'X-CSRF-Token: '.$csrf,
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
    for ($frames = 0; $frames < 12; $frames++) {
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
function smokeReplyById($stream, int $id): array {
    for ($reads = 0; $reads < 8; $reads++) {
        $payload = smokeReadText($stream);
        foreach (preg_split('/\r?\n/', trim($payload)) ?: [] as $line) {
            if ($line === '') continue;
            $value = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($value) && (int)($value['id'] ?? 0) === $id) return $value;
        }
    }
    throw new RuntimeException('websocket_reply_missing');
}
function smokeWebSocket(string $url, string $origin, string $connectionToken, string $ownChannel, string $subscriptionToken): array {
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

        smokeWriteFrame($stream, json_encode(['id' => 1, 'connect' => [
            'token' => $connectionToken, 'name' => 'taskesc-smoke', 'version' => 'step4'
        ]], JSON_THROW_ON_ERROR));
        $reply = smokeReplyById($stream, 1);
        smokeNeed(!isset($reply['error']) && isset($reply['connect']), 'centrifugo_connect_rejected');

        // Private channels must not be subscribable without an authorization mechanism.
        smokeWriteFrame($stream, json_encode(['id' => 2, 'subscribe' => ['channel' => $ownChannel]], JSON_THROW_ON_ERROR));
        $reply = smokeReplyById($stream, 2);
        $tokenlessDenied = isset($reply['error']);

        smokeWriteFrame($stream, json_encode(['id' => 3, 'subscribe' => [
            'channel' => $ownChannel, 'token' => $subscriptionToken
        ]], JSON_THROW_ON_ERROR));
        $reply = smokeReplyById($stream, 3);
        smokeNeed(!isset($reply['error']) && isset($reply['subscribe']), 'centrifugo_subscribe_rejected');

        $wrongChannel = 'user:2147483647';
        smokeWriteFrame($stream, json_encode(['id' => 4, 'subscribe' => [
            'channel' => $wrongChannel, 'token' => $subscriptionToken
        ]], JSON_THROW_ON_ERROR));
        $connectionClosedOnMismatch = false;
        try {
            $reply = smokeReplyById($stream, 4);
            $mismatchDenied = isset($reply['error']);
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'websocket_closed') throw $e;
            // Centrifugo may reject a subscription-token/channel mismatch
            // by closing the client connection instead of returning an error reply.
            $mismatchDenied = true;
            $connectionClosedOnMismatch = true;
        }

        if (!$connectionClosedOnMismatch) {
            smokeWriteFrame($stream, '', 8);
        }
        return [$tokenlessDenied, $mismatchDenied];
    } finally {
        fclose($stream);
    }
}

$tempUserId = 0;
$sessionIds = [];
$smokeStage = 'bootstrap';
$rateBucket = hash('sha256', 'login|127.0.0.1');
try {
    $smokeStage = 'prerequisites';
    smokeNeed(function_exists('curl_init'), 'curl_missing');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $suffix = bin2hex(random_bytes(8));
    $email = 'realtime-smoke-'.$suffix.'@taskesc.invalid';
    $password = 'Smoke!'.bin2hex(random_bytes(12));
    $smokeStage = 'fixture_user';
    $tempUserId = DB::tx(static function() use ($email,$password): int {
        $workspace=DB::one('SELECT id FROM workspaces WHERE slug=? AND status=?',
            [WorkspaceFoundation::SLUG,WorkspaceFoundation::STATUS]);
        smokeNeed($workspace!==null,'workspace_smoke_prerequisite_missing');
        $id=DB::insert('users', [
            'name' => 'Realtime Smoke',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'staff',
            'active' => 1,
            'created_at' => now(),
        ]);
        WorkspaceContext::syncUser($id,(int)$workspace['id'],'staff',true);
        return $id;
    });
    smokeNeed($tempUserId > 0, 'smoke_user_create_failed');

    $smokeStage = 'session_start';
    $sessionDir = ROOT.'/storage/sessions';
    if (!is_dir($sessionDir)) mkdir($sessionDir, 0700, true);
    session_save_path($sessionDir);
    session_name('taskesc');
    $initialSession = bin2hex(random_bytes(24));
    session_id($initialSession);
    $sessionIds[] = $initialSession;
    session_start();

    $smokeStage = 'login';
    $login = Auth::login(['email' => $email, 'password' => $password]);
    smokeNeed((int)($login['user']['id'] ?? 0) === $tempUserId, 'login_failed');
    $csrf = (string)($login['csrf'] ?? '');
    $sessionId = session_id();
    $sessionIds[] = $sessionId;
    smokeNeed($csrf !== '' && $sessionId !== '', 'login_session_invalid');
    session_write_close();

    $smokeStage = 'base_url';
    $base = rtrim((string)config('base_url', ''), '/');
    $baseParts = parse_url($base);
    smokeNeed(is_array($baseParts) && isset($baseParts['scheme'], $baseParts['host']), 'base_url_invalid');
    $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

    $smokeStage = 'connection_token';
    [$status, $connection] = smokeHttp($base.'/realtime.auth', $sessionId, $csrf, $origin, []);
    smokeNeed($status === 200 && is_string($connection['token'] ?? null) && is_string($connection['websocket'] ?? null), 'realtime_connection_token_failed');

    $ownChannel = 'user:'.$tempUserId;
    $smokeStage = 'subscription_token';
    [$status, $subscription] = smokeHttp($base.'/realtime.auth', $sessionId, $csrf, $origin, ['channel' => $ownChannel]);
    smokeNeed($status === 200 && ($subscription['channel'] ?? null) === $ownChannel && is_string($subscription['token'] ?? null), 'realtime_subscription_token_failed');

    $smokeStage = 'permission_guard';
    [$deniedStatus, $deniedBody] = smokeHttp($base.'/realtime.auth', $sessionId, $csrf, $origin, ['channel' => 'user:2147483647']);
    smokeNeed($deniedStatus === 403 && isset($deniedBody['error']), 'realtime_permission_guard_failed');

    $smokeStage = 'websocket';
    [$tokenlessDenied, $mismatchDenied] = smokeWebSocket(
        (string)$connection['websocket'], $origin, (string)$connection['token'],
        $ownChannel, (string)$subscription['token']
    );
    $smokeStage = 'websocket_assertions';
    smokeNeed($tokenlessDenied, 'tokenless_subscribe_allowed');
    smokeNeed($mismatchDenied, 'channel_mismatch_allowed');

    $smokeStage = 'complete';
    echo json_encode([
        'status' => 'ok',
        'login' => true,
        'connection_jwt' => true,
        'subscription_jwt' => true,
        'unauthorized_channel_denied' => true,
        'tokenless_subscribe_denied' => true,
        'channel_mismatch_denied' => true,
        'websocket_authenticated' => true,
        'channel' => $ownChannel,
        'centrifugo_expected' => Realtime::CENTRIFUGO_VERSION,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    if ($e instanceof RuntimeException && preg_match('/^[a-z0-9_]+$/', $e->getMessage())) {
        $code = $e->getMessage();
    } elseif ($e instanceof AppError) {
        $code = 'stage_'.$smokeStage.'_app_'.$e->status;
    } else {
        $kind = strtolower((new ReflectionClass($e))->getShortName());
        $kind = preg_replace('/[^a-z0-9_]+/', '_', $kind) ?: 'throwable';
        $code = 'stage_'.$smokeStage.'_'.$kind;
    }
    fwrite(STDERR, json_encode(['status' => 'error', 'code' => $code], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    foreach (array_unique($sessionIds) as $sid) @unlink(ROOT.'/storage/sessions/sess_'.$sid);
    try { DB::run('DELETE FROM rate_limits WHERE bucket=?', [$rateBucket]); } catch (Throwable) {}
    if ($tempUserId > 0) {
        try { DB::run('DELETE FROM audit_log WHERE user_id=?', [$tempUserId]); } catch (Throwable) {}
        try { DB::run('DELETE FROM notifications WHERE user_id=?', [$tempUserId]); } catch (Throwable) {}
        try { DB::run('DELETE FROM users WHERE id=?', [$tempUserId]); } catch (Throwable) {}
    }
}
