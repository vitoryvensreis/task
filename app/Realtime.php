<?php
declare(strict_types=1);

/** Optional realtime backend infrastructure backed by private VPS configuration. */
final class Realtime implements JsonSerializable
{
    public const PRIVATE_CONFIG = '/etc/taskesc/realtime.json';
    public const CENTRIFUGO_VERSION = '6.9.7';
    public const TOKEN_TTL_SECONDS = 300;
    private array $values = [];
    private string $configStatus = 'unavailable';

    public function __construct(string $privateConfig = self::PRIVATE_CONFIG)
    {
        try {
            if (!is_readable($privateConfig)) return;
            $raw = @file_get_contents($privateConfig, false, null, 0, 16385);
            if (!is_string($raw) || strlen($raw) > 16384) return;
            $values = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($values) || !self::valid($values)) {
                $this->configStatus = 'invalid';
                return;
            }
            $this->values = $values;
            $this->configStatus = 'ok';
        } catch (Throwable) {
            $this->configStatus = 'invalid';
        }
    }

    private static function valid(array $v): bool
    {
        if (!is_bool($v['REALTIME_ENABLED'] ?? null)) return false;
        // Credentials must never be sent to a remote host or through redirects.
        if (!in_array($v['REDIS_HOST'] ?? null, ['127.0.0.1', '::1'], true)) return false;
        if (!is_int($v['REDIS_PORT'] ?? null) || $v['REDIS_PORT'] < 1 || $v['REDIS_PORT'] > 65535) return false;
        $api = parse_url((string)($v['CENTRIFUGO_API_URL'] ?? ''));
        if (!is_array($api) || ($api['scheme'] ?? '') !== 'http'
            || !in_array($api['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || ($api['path'] ?? '') !== '/api'
            || isset($api['user']) || isset($api['query']) || isset($api['fragment'])) return false;
        $ws = parse_url((string)($v['CENTRIFUGO_WS_URL'] ?? ''));
        if (!is_array($ws) || ($ws['scheme'] ?? '') !== 'wss' || empty($ws['host'])
            || ($ws['path'] ?? '') !== '/realtime/connection/websocket'
            || isset($ws['user']) || isset($ws['query']) || isset($ws['fragment'])) return false;
        foreach (['CENTRIFUGO_HMAC_SECRET', 'CENTRIFUGO_API_KEY'] as $key) {
            if (!is_string($v[$key] ?? null) || trim($v[$key]) === ''
                || preg_match('/[\x00-\x1f\x7f]/', $v[$key])) return false;
        }
        return true;
    }

    public function enabled(): bool { return $this->configStatus === 'ok' && $this->values['REALTIME_ENABLED']; }
    public function redisHost(): string { return $this->values['REDIS_HOST'] ?? '127.0.0.1'; }
    public function redisPort(): int { return $this->values['REDIS_PORT'] ?? 6379; }
    public function apiUrl(): string { return $this->values['CENTRIFUGO_API_URL'] ?? 'http://127.0.0.1:8000/api'; }
    public function websocketUrl(): string { return $this->values['CENTRIFUGO_WS_URL'] ?? ''; }
    public function hmacKey(): ?string { return $this->values['CENTRIFUGO_HMAC_SECRET'] ?? null; }
    public function apiKey(): ?string { return $this->values['CENTRIFUGO_API_KEY'] ?? null; }

    /** Centrifugo 6.9.7 connection JWT: sub is the authenticated TaskEsc user ID as a string. */
    public function connectionToken(int $userId, ?int $issuedAt = null): string
    {
        if (!$this->enabled() || $userId < 1) throw new RuntimeException('realtime_unavailable');
        $now = $issuedAt ?? time();
        return $this->jwt([
            'sub' => (string)$userId,
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
        ]);
    }

    /** Centrifugo 6.9.7 subscription JWT: sub and exact channel are both signed server-side. */
    public function subscriptionToken(int $userId, string $channel, ?int $issuedAt = null): string
    {
        if (!$this->enabled() || $userId < 1 || $channel === '' || strlen($channel) > 190) {
            throw new RuntimeException('realtime_unavailable');
        }
        $now = $issuedAt ?? time();
        return $this->jwt([
            'sub' => (string)$userId,
            'channel' => $channel,
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
        ]);
    }

    private function jwt(array $claims): string
    {
        $secret = $this->hmacKey();
        if (!is_string($secret) || $secret === '') throw new RuntimeException('realtime_unavailable');
        $header = self::base64Url(json_encode(['alg'=>'HS256','typ'=>'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $input = $header.'.'.$payload;
        return $input.'.'.self::base64Url(hash_hmac('sha256', $input, $secret, true));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Returns null on any failure; no persistent connection or session change. */
    public function redis(): ?Redis
    {
        return $this->enabled() ? $this->connectRedis() : null;
    }

    private function connectRedis(): ?Redis
    {
        if ($this->configStatus !== 'ok' || !class_exists('Redis')) return null;
        $redis = null;
        try {
            $redis = new Redis();
            if (@$redis->connect($this->redisHost(), $this->redisPort(), 0.5, null, 0, 0.5)) return $redis;
        } catch (Throwable) {}
        if ($redis !== null) { try { @$redis->close(); } catch (Throwable) {} }
        return null;
    }

    /** Explicit internal probe, also usable while REALTIME_ENABLED=false. */
    public function health(): array
    {
        $redisStatus = 'unavailable';
        $redis = $this->connectRedis();
        if ($redis !== null) {
            try {
                $pong = @$redis->ping();
                $redisStatus = in_array($pong, [true, 'PONG', '+PONG'], true) ? 'ok' : 'unavailable';
            } catch (Throwable) {} finally { try { @$redis->close(); } catch (Throwable) {} }
        }
        $centrifugo = $this->centrifugoHealth();
        return [
            'status' => $this->configStatus === 'ok' && $this->enabled() && $redisStatus === 'ok' && $centrifugo['status'] === 'ok' ? 'ok' : 'degraded',
            'enabled' => $this->enabled(),
            'config' => $this->configStatus,
            'redis' => $redisStatus,
            'centrifugo' => $centrifugo['status'],
            'centrifugo_version' => $centrifugo['version'],
            'centrifugo_expected' => self::CENTRIFUGO_VERSION,
            'checked_at' => gmdate('c'),
        ];
    }

    private function centrifugoHealth(): array
    {
        $unavailable = ['status'=>'unavailable','version'=>null];
        if ($this->configStatus !== 'ok' || !function_exists('curl_init')) return $unavailable;
        $ch = null;
        try {
            $ch = curl_init($this->apiUrl().'/info');
            if ($ch === false) return $unavailable;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: '.$this->values['CENTRIFUGO_API_KEY']],
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 1500,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            ]);
            $body = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 || !is_string($body)) return $unavailable;
            $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $nodes = $response['result']['nodes'] ?? null;
            if (!is_array($response) || isset($response['error']) || !is_array($nodes) || count($nodes) < 1) return $unavailable;
            $versions = [];
            foreach ($nodes as $node) {
                if (is_array($node) && is_string($node['version'] ?? null) && $node['version'] !== '') {
                    $versions[] = ltrim($node['version'], 'v');
                }
            }
            $versions = array_values(array_unique($versions));
            return ['status'=>'ok','version'=>count($versions) === 1 ? $versions[0] : null];
        } catch (Throwable) {
            return $unavailable;
        } finally {
            if ($ch !== null && $ch !== false) curl_close($ch);
        }
    }

    // Accidental dumps/JSON are redacted and never trigger network probes.
    public function __debugInfo(): array { return ['enabled' => $this->enabled(), 'config' => $this->configStatus]; }
    public function jsonSerialize(): array { return $this->__debugInfo(); }
}
