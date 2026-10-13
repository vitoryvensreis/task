<?php
declare(strict_types=1);

final class RealtimeWorkerHealth
{
    public const FILE = ROOT.'/storage/realtime-worker-health.json';

    public static function write(array $state): void
    {
        $safe = [
            'pid' => getmypid(),
            'started_at' => (string)($state['started_at'] ?? ''),
            'heartbeat_at' => gmdate('c'),
            'last_cycle_at' => (string)($state['last_cycle_at'] ?? ''),
            'last_published_at' => (string)($state['last_published_at'] ?? ''),
            'last_error_code' => (string)($state['last_error_code'] ?? ''),
        ];
        $dir = dirname(self::FILE);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $tmp = self::FILE.'.'.getmypid().'.tmp';
        $json = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
        @chmod($tmp, 0600);
        @rename($tmp, self::FILE);
    }

    public static function evaluate(array $heartbeat, array $realtime, array $queue): array
    {
        $serviceOk = ($heartbeat['status'] ?? null) === 'ok' && ($realtime['status'] ?? null) === 'ok';
        $exhausted = max(0, (int)($queue['exhausted'] ?? 0));
        $due = max(0, (int)($queue['due'] ?? 0));
        return [
            'status' => $serviceOk ? 'ok' : 'degraded',
            'queue_status' => $exhausted > 0 ? 'warning' : 'ok',
            'queue_warning' => $exhausted > 0 ? 'exhausted_events' : null,
            'exhausted' => $exhausted,
            'due' => $due,
        ];
    }

    public static function read(int $freshSeconds = 90): array
    {
        if ($freshSeconds < 10 || $freshSeconds > 3600) $freshSeconds = 90;
        if (!is_file(self::FILE) || !is_readable(self::FILE)) return ['status' => 'missing'];
        try {
            $raw = file_get_contents(self::FILE, false, null, 0, 4097);
            if (!is_string($raw) || strlen($raw) > 4096) return ['status' => 'invalid'];
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) return ['status' => 'invalid'];
            $heartbeat = strtotime((string)($data['heartbeat_at'] ?? ''));
            if ($heartbeat === false) return ['status' => 'invalid'];
            return [
                'status' => time() - $heartbeat <= $freshSeconds ? 'ok' : 'stale',
                'pid' => (int)($data['pid'] ?? 0),
                'started_at' => (string)($data['started_at'] ?? ''),
                'heartbeat_at' => (string)($data['heartbeat_at'] ?? ''),
                'last_cycle_at' => (string)($data['last_cycle_at'] ?? ''),
                'last_published_at' => (string)($data['last_published_at'] ?? ''),
                'last_error_code' => (string)($data['last_error_code'] ?? ''),
            ];
        } catch (Throwable) {
            return ['status' => 'invalid'];
        }
    }
}
