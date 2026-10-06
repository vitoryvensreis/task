<?php
declare(strict_types=1);

/** Issues Centrifugo JWTs only after TaskEsc web-session authorization. */
final class RealtimeAuth
{
    public function __construct(private readonly Realtime $realtime = new Realtime()) {}

    public function connection(array $input = []): array
    {
        $this->assertOnly($input, []);
        $user = $this->webUser();
        $this->ready();
        return [
            'enabled' => true,
            'token' => $this->realtime->connectionToken((int)$user['id']),
            'websocket' => $this->realtime->websocketUrl(),
            'channels' => $this->bootstrapChannels($user),
            'expires_in' => Realtime::TOKEN_TTL_SECONDS,
            'centrifugo' => Realtime::CENTRIFUGO_VERSION,
        ];
    }

    public function subscription(array $input): array
    {
        $this->assertOnly($input, ['channel']);
        $user = $this->webUser();
        $this->ready();
        $channel = trim((string)($input['channel'] ?? ''));
        if ($channel === '' || strlen($channel) > 190) fail('Canal realtime inválido.', 400);
        $this->authorizeChannel($channel, $user);
        return [
            'token' => $this->realtime->subscriptionToken((int)$user['id'], $channel),
            'channel' => $channel,
            'expires_in' => Realtime::TOKEN_TTL_SECONDS,
        ];
    }

    public function bootstrapChannels(array $user): array
    {
        // Preview impersonates client visibility but must not inherit admin personal/global realtime.
        if (Auth::isPreview()) return [];
        $channels = ['user:'.(int)$user['id']];
        if (($user['role'] ?? '') === 'admin') $channels[] = 'kanban/global';
        return $channels;
    }

    public function authorizeChannel(string $channel, array $user): void
    {
        if ($channel === 'kanban/global') {
            if (($user['role'] ?? '') !== 'admin' || Auth::isPreview()) fail('Sem permissão para este canal realtime.', 403);
            return;
        }

        if (preg_match('/^user:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            if (Auth::isPreview() || (int)$m[1] !== (int)$user['id']) fail('Sem permissão para este canal realtime.', 403);
            return;
        }

        if (preg_match('/^demand:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            Auth::requireAbility('tasks.read');
            Auth::demand((int)$m[1]);
            return;
        }

        if (preg_match('/^project:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            $project = DB::one('SELECT id,client_id FROM projects WHERE id=? LIMIT 1', [(int)$m[1]]);
            if (!$project) fail('Projeto não encontrado.', 404);
            Auth::client((int)$project['client_id'], 'read');
            return;
        }

        fail('Canal realtime não permitido.', 403);
    }

    private function webUser(): array
    {
        $user = Auth::load();
        if (Auth::$origin !== 'web') fail('Realtime disponível apenas para sessão web autenticada.', 403);
        return $user;
    }

    private function ready(): void
    {
        if (!$this->realtime->enabled() || $this->realtime->websocketUrl() === '' || !$this->realtime->hmacKey()) {
            fail('Realtime temporariamente indisponível.', 503);
        }
    }

    private function assertOnly(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                fail('O servidor define exclusivamente a identidade e o escopo realtime.', 400);
            }
        }
    }
}
