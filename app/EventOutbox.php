<?php
declare(strict_types=1);

/** MySQL-only infrastructure. No publishers, frontend hooks or background jobs. */
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
        $q = $db->prepare('INSERT INTO event_outbox
            (event_key,event_hash,event_type,entity_type,entity_id,channel,payload_json,created_by,created_at,available_at)
            VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $q->execute([$key, $hash, $eventType, $entityType, $entityId, $channel, $json, $createdBy, $now, $available]);
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
