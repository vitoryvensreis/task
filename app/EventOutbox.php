<?php
declare(strict_types=1);

/** MySQL outbox infrastructure shared by business transactions and the realtime worker. */
final class EventOutbox
{
    private static int $savepoint = 0;

    /** Uses the exact PDO used by DB; nested calls isolate failures with a savepoint. */
    public static function transaction(callable $operation): mixed
    {
        $db = DB::conn();
        $owns = !$db->inTransaction();
        $savepoint = 'outbox_'.(++self::$savepoint);
        if ($owns) $db->beginTransaction();
        else $db->exec('SAVEPOINT '.$savepoint);
        try {
            $result = $operation();
            if (!$db->inTransaction()) throw new LogicException('Outbox transaction was closed by the operation.');
            if ($owns) $db->commit();
            else $db->exec('RELEASE SAVEPOINT '.$savepoint);
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                if ($owns) $db->rollBack();
                else {
                    $db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                    $db->exec('RELEASE SAVEPOINT '.$savepoint);
                }
            }
            throw $e;
        }
    }

    /** Stable operation key identifies ONE event, including across retries. */
    public static function enqueue(
        string $operationKey,
        string $eventType,
        string $entityType,
        int $entityId,
        string $channel,
        array $payload,
        ?int $createdBy = null,
        ?DateTimeImmutable $availableAt = null
    ): int {
        $db = self::transactionConnection();
        if ($operationKey === '' || strlen($operationKey) > 512) throw new InvalidArgumentException('Invalid outbox operation key.');
        self::identifier($eventType, 100);
        self::identifier($entityType, 64);
        self::identifier($channel, 190);
        if ($entityId < 1 || ($createdBy !== null && $createdBy < 1)) throw new InvalidArgumentException('Invalid outbox entity/actor ID.');
        $json = json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 1048576) throw new InvalidArgumentException('Outbox payload exceeds 1 MiB.');
        $key = hash('sha256', $operationKey);
        $hash = hash('sha256', json_encode([$eventType, $entityType, $entityId, $channel, $json, $createdBy], JSON_THROW_ON_ERROR));
        $now = self::utc();
        $available = $availableAt === null ? $now : $availableAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $tenant=WorkspaceTenantMode::enabled();
        $scope=0;
        if($tenant){
            $job=WorkspaceJobScope::currentId();
            $web=(int)(Auth::$workspace['id']??0);
            if($job>0&&$web>0&&$job!==$web)
                throw new LogicException('Outbox company context conflict.');
            $scope=$job>0?$job:$web;
            if($scope<1)throw new LogicException('Outbox event requires explicit workspace.');
        }
        $q=$db->prepare('INSERT INTO event_outbox
            (event_key,event_hash,event_type,entity_type,entity_id,channel,payload_json,created_by,created_at,available_at'.
            ($tenant?',workspace_id':'').')
            VALUES (?,?,?,?,?,?,?,?,?,?'.($tenant?',?':'').') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $params=[$key, $hash, $eventType, $entityType, $entityId, $channel, $json, $createdBy, $now, $available];
        if($tenant)$params[]=$scope;
        $q->execute($params);
        $id = (int)$db->lastInsertId();
        // Current read handles a duplicate committed after this transaction's snapshot.
        $q = $db->prepare('SELECT event_hash FROM event_outbox WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        if ($id < 1 || !hash_equals($hash, (string)$q->fetchColumn())) throw new LogicException('Outbox idempotency conflict.');
        return $id;
    }

    /** Read-only, bounded preview of due rows. Does NOT claim or publish events. */
    public static function pending(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Outbox batch must contain 1 to 500 rows.');
        $q = DB::conn()->prepare('SELECT * FROM event_outbox WHERE published_at IS NULL AND available_at<=? ORDER BY available_at,id LIMIT '.$limit);
        $q->execute([self::utc()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Due worker rows, excluding events which reached the retry limit. */
    public static function due(int $limit = 20, int $maxAttempts = 8): array
    {
        if ($limit < 1 || $limit > 100 || $maxAttempts < 1 || $maxAttempts > 100) throw new InvalidArgumentException('Invalid worker batch parameters.');
        $q = DB::conn()->prepare('SELECT * FROM event_outbox WHERE published_at IS NULL AND attempts<? AND available_at<=? ORDER BY available_at,id LIMIT '.$limit);
        $q->execute([$maxAttempts, self::utc()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Re-read after acquiring the named lock so stale selections cannot be published. */
    public static function processable(int $id, int $maxAttempts = 8): ?array
    {
        if ($id < 1 || $maxAttempts < 1 || $maxAttempts > 100) throw new InvalidArgumentException('Invalid worker event parameters.');
        $q = DB::conn()->prepare('SELECT * FROM event_outbox WHERE id=? AND published_at IS NULL AND attempts<? AND available_at<=? LIMIT 1');
        $q->execute([$id, $maxAttempts, self::utc()]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Session-scoped MySQL lock; released automatically if the worker connection dies. */
    public static function withLock(int $id, callable $operation): mixed
    {
        if ($id < 1) throw new InvalidArgumentException('Invalid outbox lock ID.');
        $db = DB::conn();
        $name = 'taskesc:outbox:'.$id;
        $q = $db->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$name]);
        if ((int)$q->fetchColumn() !== 1) return null;
        try {
            return $operation();
        } finally {
            try {
                $q = $db->prepare('SELECT RELEASE_LOCK(?)');
                $q->execute([$name]);
            } catch (Throwable) {}
        }
    }

    /** Compare-and-swap success marker. */
    public static function markPublished(int $id, int $expectedAttempts): bool
    {
        $db = self::transactionConnection();
        if ($id < 1 || $expectedAttempts < 0 || $expectedAttempts >= 4294967295) throw new InvalidArgumentException('Invalid outbox publish parameters.');
        $q = $db->prepare('UPDATE event_outbox SET published_at=?,last_error=NULL WHERE id=? AND attempts=? AND published_at IS NULL');
        $q->execute([self::utc(), $id, $expectedAttempts]);
        return $q->rowCount() === 1;
    }

    /** Compare-and-swap: the same failure cannot increment attempts twice. */
    public static function retry(int $id, int $expectedAttempts, string $errorCode, int $delaySeconds = 30): bool
    {
        $db = self::transactionConnection();
        if ($id < 1 || $expectedAttempts < 0 || $expectedAttempts >= 4294967295
            || $delaySeconds < 1 || $delaySeconds > 86400
            || !preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $errorCode)) throw new InvalidArgumentException('Invalid outbox retry parameters.');
        $available = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+'.$delaySeconds.' seconds')->format('Y-m-d H:i:s.u');
        $q = $db->prepare('UPDATE event_outbox SET attempts=attempts+1,last_error=?,available_at=? WHERE id=? AND attempts=? AND published_at IS NULL');
        $q->execute([$errorCode, $available, $id, $expectedAttempts]);
        return $q->rowCount() === 1;
    }

    public static function stats(int $maxAttempts = 8): array
    {
        if ($maxAttempts < 1 || $maxAttempts > 100) throw new InvalidArgumentException('Invalid retry limit.');
        $now = self::utc();
        $row = DB::one('SELECT
            COUNT(*) AS total,
            SUM(published_at IS NULL) AS unpublished,
            SUM(published_at IS NOT NULL) AS published,
            SUM(published_at IS NULL AND attempts<? AND available_at<=?) AS due,
            SUM(published_at IS NULL AND attempts>=?) AS exhausted,
            MIN(CASE WHEN published_at IS NULL THEN created_at ELSE NULL END) AS oldest_unpublished_at
            FROM event_outbox', [$maxAttempts, $now, $maxAttempts]) ?? [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'unpublished' => (int)($row['unpublished'] ?? 0),
            'published' => (int)($row['published'] ?? 0),
            'due' => (int)($row['due'] ?? 0),
            'exhausted' => (int)($row['exhausted'] ?? 0),
            'oldest_unpublished_at' => $row['oldest_unpublished_at'] ?? null,
        ];
    }

    private static function transactionConnection(): PDO
    {
        $db = DB::conn();
        if (!$db->inTransaction()) throw new LogicException('Outbox writes require an existing MySQL transaction.');
        return $db;
    }

    private static function identifier(string $value, int $max): void
    {
        if (strlen($value) > $max || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:#\/-]*\z/D', $value)) throw new InvalidArgumentException('Invalid outbox identifier.');
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$item) $item = self::canonical($item);
            return $value;
        }
        if ($value === null || is_scalar($value)) return $value;
        throw new InvalidArgumentException('Outbox payload must contain JSON scalars and arrays only.');
    }

    private static function utc(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
}
