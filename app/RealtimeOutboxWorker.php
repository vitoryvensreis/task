<?php
declare(strict_types=1);

final class RealtimeOutboxWorker
{
    public const DEFAULT_BATCH = 20;
    public const DEFAULT_MAX_ATTEMPTS = 8;

    private Closure $publisher;

    public function __construct(callable $publisher, private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS)
    {
        if ($maxAttempts < 1 || $maxAttempts > 100) throw new InvalidArgumentException('Invalid maximum attempts.');
        $this->publisher = Closure::fromCallable($publisher);
    }

    public static function backoffSeconds(int $attemptsBeforeFailure): int
    {
        if ($attemptsBeforeFailure < 0) $attemptsBeforeFailure = 0;
        return min(300, 5 * (2 ** min(6, $attemptsBeforeFailure)));
    }

    public function processBatch(int $limit = self::DEFAULT_BATCH): array
    {
        if ($limit < 1 || $limit > 100) throw new InvalidArgumentException('Invalid worker batch size.');
        SaasLegacyBoundary::assertSingleOperation();
        $result = ['selected' => 0, 'published' => 0, 'retried' => 0, 'locked' => 0, 'skipped' => 0];
        $events = EventOutbox::due($limit, $this->maxAttempts);
        $result['selected'] = count($events);
        foreach ($events as $event) {
            $id = (int)$event['id'];
            $processed = EventOutbox::withLock($id, function () use ($id, &$result): string {
                $current = EventOutbox::processable($id, $this->maxAttempts);
                if ($current === null) {
                    $result['skipped']++;
                    return 'skipped';
                }
                $attempts = (int)$current['attempts'];
                try {
                    ($this->publisher)($current);
                    $ok = EventOutbox::transaction(fn() => EventOutbox::markPublished($id, $attempts));
                    if ($ok) $result['published']++;
                    else $result['skipped']++;
                    return $ok ? 'published' : 'skipped';
                } catch (Throwable $e) {
                    $code = self::safeErrorCode($e);
                    $delay = self::backoffSeconds($attempts);
                    $ok = EventOutbox::transaction(fn() => EventOutbox::retry($id, $attempts, $code, $delay));
                    if ($ok) $result['retried']++;
                    else $result['skipped']++;
                    return $ok ? 'retried' : 'skipped';
                }
            });
            if ($processed === null) $result['locked']++;
        }
        return $result;
    }

    private static function safeErrorCode(Throwable $e): string
    {
        $message = $e->getMessage();
        if ($e instanceof RuntimeException && preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $message)) return $message;
        return 'publish_failed';
    }
}
