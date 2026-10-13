<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

$once = in_array('--once', $argv, true);
$diagnostic = in_array('--diagnostic', $argv, true);
$healthOnly = in_array('--health', $argv, true);
$batch = RealtimeOutboxWorker::DEFAULT_BATCH;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch=')) $batch = max(1, min(100, (int)substr($arg, 8)));
}

try {
    if (!config() || !is_file(ROOT.'/storage/installed.lock')) throw new RuntimeException('taskesc_not_installed');
    $schema = (string)(DB::one('SELECT value FROM settings WHERE `key`=? LIMIT 1', ['schema_version'])['value'] ?? '');
    $schemaVersion = filter_var($schema, FILTER_VALIDATE_INT);
    if ($schemaVersion === false || (int)$schemaVersion < Migrations::OUTBOX_VERSION || (int)$schemaVersion > Migrations::CURRENT_VERSION) {
        throw new RuntimeException('realtime_schema_unsupported');
    }

    if ($healthOnly) {
        $queue = EventOutbox::stats(RealtimeOutboxWorker::DEFAULT_MAX_ATTEMPTS);
        $heartbeat = RealtimeWorkerHealth::read();
        $realtime = (new Realtime())->health();
        $health = RealtimeWorkerHealth::evaluate($heartbeat, $realtime, $queue);
        echo json(['status' => $health['status'], 'queue_status' => $health['queue_status'],
            'queue_warning' => $health['queue_warning'], 'worker' => $heartbeat, 'queue' => $queue,
            'realtime' => ['config' => $realtime['config'], 'redis' => $realtime['redis'], 'centrifugo' => $realtime['centrifugo']]]).PHP_EOL;
        exit($health['status'] === 'ok' ? 0 : 1);
    }

    $publisher = new CentrifugoPublisher(new Realtime());
    $worker = new RealtimeOutboxWorker([$publisher, 'publish']);

    if ($diagnostic) {
        $operation = 'diagnostic:'.bin2hex(random_bytes(12));
        $id = EventOutbox::transaction(fn() => EventOutbox::enqueue(
            $operation, 'realtime.diagnostic', 'system', 1, 'diagnostic',
            ['kind' => 'worker_publish_test', 'created_at' => gmdate('c')], null
        ));
        $deadline = microtime(true) + 10.0;
        do {
            $worker->processBatch($batch);
            $row = DB::one('SELECT published_at,attempts,last_error FROM event_outbox WHERE id=?', [$id]);
            if ($row && $row['published_at'] !== null) {
                echo json(['diagnostic' => 'published', 'event_id' => $id, 'attempts' => (int)$row['attempts']]).PHP_EOL;
                exit(0);
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        echo json(['diagnostic' => 'failed', 'event_id' => $id,
            'attempts' => (int)($row['attempts'] ?? 0), 'last_error' => (string)($row['last_error'] ?? 'unknown')]).PHP_EOL;
        exit(1);
    }

    $running = true;
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
        pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
    }
    $started = gmdate('c');
    $state = ['started_at' => $started, 'last_cycle_at' => '', 'last_published_at' => '', 'last_error_code' => ''];
    $lastHeartbeat = 0.0;
    echo json(['worker' => 'started', 'batch' => $batch, 'max_attempts' => RealtimeOutboxWorker::DEFAULT_MAX_ATTEMPTS]).PHP_EOL;

    do {
        try {
            $result = $worker->processBatch($batch);
            $state['last_cycle_at'] = gmdate('c');
            $state['last_error_code'] = '';
            if ($result['published'] > 0) $state['last_published_at'] = gmdate('c');
            if ($result['published'] > 0 || $result['retried'] > 0) {
                echo json(['worker' => 'cycle', 'published' => $result['published'], 'retried' => $result['retried'],
                    'locked' => $result['locked'], 'selected' => $result['selected']]).PHP_EOL;
            }
        } catch (Throwable $e) {
            $code = $e instanceof RuntimeException && preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $e->getMessage()) ? $e->getMessage() : 'worker_cycle_failed';
            $state['last_error_code'] = $code;
            echo json(['worker' => 'cycle_error', 'code' => $code]).PHP_EOL;
            usleep(2000000);
        }
        $now = microtime(true);
        if ($now - $lastHeartbeat >= 15.0) {
            RealtimeWorkerHealth::write($state);
            $lastHeartbeat = $now;
        }
        if ($once) break;
        if ($running) usleep(1000000);
    } while ($running);

    RealtimeWorkerHealth::write($state);
    echo json(['worker' => 'stopped']).PHP_EOL;
} catch (Throwable $e) {
    $code = $e instanceof RuntimeException && preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $e->getMessage()) ? $e->getMessage() : 'worker_boot_failed';
    fwrite(STDERR, json(['status' => 'error', 'code' => $code]).PHP_EOL);
    exit(1);
}
