<?php
declare(strict_types=1);

/**
 * Emits minimal, authorization-safe demand events through the transactional Outbox.
 *
 * This class never publishes directly to Centrifugo. EventOutbox::enqueue() requires
 * an existing MySQL transaction, so a business mutation and all of its realtime
 * events commit or roll back together.
 */
final class DemandRealtime
{
    private const TYPES = [
        'demand.created',
        'demand.updated',
        'demand.stage_changed',
        'demand.reordered',
        'demand.deleted',
    ];

    private const EXTRA_KEYS = [
        'change',
        'previous_stage_id',
        'previous_project_id',
        'sort_order',
        'priority',
        'assigned_to',
        'deleted_at',
    ];

    /**
     * @param int[] $alsoProjectIds Projects that must be invalidated in addition to the current project.
     * @return array<string,int> Outbox row IDs keyed by channel.
     */
    public static function emit(
        string $eventType,
        array $demand,
        array $extra = [],
        ?int $actorId = null,
        array $alsoProjectIds = []
    ): array {
        if (!in_array($eventType, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported demand realtime event.');
        }

        $id = (int)($demand['id'] ?? 0);
        $clientId = (int)($demand['client_id'] ?? 0);
        $projectId = (int)($demand['project_id'] ?? 0);
        $stageId = (int)($demand['stage_id'] ?? 0);
        $version = max(0, (int)($demand['version'] ?? 0));
        if ($id < 1 || $clientId < 1 || ($actorId !== null && $actorId < 1)) {
            throw new InvalidArgumentException('Invalid demand realtime identity.');
        }

        $payload = [
            'demand_id' => $id,
            'client_id' => $clientId,
            'project_id' => $projectId > 0 ? $projectId : null,
            'stage_id' => $stageId > 0 ? $stageId : null,
            'version' => $version,
            'draft' => !empty($demand['draft']),
            'updated_at' => (string)($demand['updated_at'] ?? now()),
        ];

        foreach (self::EXTRA_KEYS as $key) {
            if (!array_key_exists($key, $extra)) continue;
            $value = $extra[$key];
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('Demand realtime payload must remain scalar.');
            }
            $payload[$key] = $value;
        }

        // Drafts are intentionally restricted to the admin-only global channel.
        // Once published, demand/project channels inherit the existing HTTP ACL.
        $channels = ['kanban/global'];
        if (empty($demand['draft'])) {
            $channels[] = 'demand:'.$id;
            if ($projectId > 0) $channels[] = 'project:'.$projectId;
            foreach ($alsoProjectIds as $oldProjectId) {
                $oldProjectId = (int)$oldProjectId;
                if ($oldProjectId > 0) $channels[] = 'project:'.$oldProjectId;
            }
        }
        $channels = array_values(array_unique($channels));

        // One random operation ID identifies this committed business mutation.
        // Each channel gets its own stable event_key; worker retries reuse that row.
        $operation = 'demand:'.$id.':'.bin2hex(random_bytes(16));
        $ids = [];
        foreach ($channels as $channel) {
            $ids[$channel] = EventOutbox::enqueue(
                $operation.':'.$channel,
                $eventType,
                'demand',
                $id,
                $channel,
                $payload,
                $actorId
            );
        }
        return $ids;
    }
}
