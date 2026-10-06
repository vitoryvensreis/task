<?php
declare(strict_types=1);

final class CentrifugoPublisher
{
    public function __construct(private readonly Realtime $realtime) {}

    /** @throws RuntimeException with safe, non-secret error codes only. */
    public function publish(array $event): void
    {
        if (!function_exists('curl_init')) throw new RuntimeException('curl_unavailable');
        $apiKey = $this->realtime->apiKey();
        if ($apiKey === null || $apiKey === '') throw new RuntimeException('realtime_config_unavailable');

        $id = filter_var($event['id'] ?? null, FILTER_VALIDATE_INT);
        $entityId = filter_var($event['entity_id'] ?? null, FILTER_VALIDATE_INT);
        $channel = (string)($event['channel'] ?? '');
        $eventKey = (string)($event['event_key'] ?? '');
        $eventType = (string)($event['event_type'] ?? '');
        $entityType = (string)($event['entity_type'] ?? '');
        if ($id === false || $id < 1 || $entityId === false || $entityId < 1
            || $channel === '' || $eventKey === '' || $eventType === '' || $entityType === '') {
            throw new RuntimeException('outbox_event_invalid');
        }

        try {
            $payload = json_decode((string)($event['payload_json'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('outbox_payload_invalid');
        }

        $request = [
            'channel' => $channel,
            'data' => [
                'id' => (int)$id,
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => (int)$entityId,
                'payload' => $payload,
                'created_by' => isset($event['created_by']) ? (int)$event['created_by'] : null,
                'created_at' => (string)($event['created_at'] ?? ''),
            ],
            // Centrifugo 6.9.7 supports per-channel idempotency for publish retries.
            'idempotency_key' => $eventKey,
        ];

        try {
            $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            throw new RuntimeException('publish_encode_failed');
        }

        $ch = curl_init($this->realtime->apiUrl().'/publish');
        if ($ch === false) throw new RuntimeException('curl_init_failed');
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: '.$apiKey],
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 2500,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            ]);
            $responseBody = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($responseBody === false) throw new RuntimeException('centrifugo_transport');
            if ($http !== 200) throw new RuntimeException('centrifugo_http');
            try {
                $response = json_decode((string)$responseBody, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new RuntimeException('centrifugo_response_invalid');
            }
            if (!is_array($response) || isset($response['error']) || !array_key_exists('result', $response)) {
                throw new RuntimeException('centrifugo_publish_rejected');
            }
        } finally {
            curl_close($ch);
        }
    }
}
