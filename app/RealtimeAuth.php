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
        // Preview receives only the selected client's collection channel. It never
        // inherits the administrator personal/global channels.
        if (Auth::isPreview()) {
            return Auth::$previewClientId ? ['client:'.(int)Auth::$previewClientId] : [];
        }

        $multi=WorkspaceTenantMode::enabled();
        $ws=$multi?Auth::workspaceId():0;
        $channels = ['user:'.(int)$user['id']];
        if (in_array(($user['role'] ?? ''), ['admin','staff'], true)) {
            $channels[] = $multi?'staff/ws-'.$ws:'staff/global';
        }
        if (($user['role'] ?? '') === 'admin') {
            $channels[] = $multi?'kanban/ws-'.$ws:'kanban/global';
            if(!$multi||MasterSupport::isSupport())$channels[] = 'wpp/global';
            return array_values(array_unique($channels));
        }

        foreach (DB::all('SELECT client_id FROM user_clients WHERE user_id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?').' ORDER BY client_id',WorkspaceContext::legacyCiFixture()?[(int)$user['id']]:[(int)$user['id'],Auth::workspaceId()]) as $link) {
            $clientId = (int)($link['client_id'] ?? 0);
            if ($clientId > 0) $channels[] = 'client:'.$clientId;
        }
        return array_values(array_unique($channels));
    }

    public function authorizeChannel(string $channel, array $user): void
    {
        $multi=WorkspaceTenantMode::enabled();
        if ($multi && preg_match('/^(kanban|staff)\/ws-([1-9][0-9]*)$/D',$channel,$m)) {
            if ((int)$m[2]!==Auth::workspaceId()||Auth::isPreview()||
                !in_array((string)$user['role'],$m[1]==='kanban'?['admin']:['admin','staff'],true))
                fail('Canal de outro workspace não permitido.',403);
            return;
        }
        if ($channel === 'kanban/global' || $channel === 'wpp/global') {
            if ($multi && ($channel==='kanban/global'||!MasterSupport::isSupport()))
                fail('Canal global bloqueado no modo multiworkspace.',403);
            if (($user['role'] ?? '') !== 'admin' || Auth::isPreview()) fail('Sem permissão para este canal realtime.', 403);
            return;
        }

        if ($channel === 'staff/global') {
            if ($multi||!in_array(($user['role'] ?? ''), ['admin','staff'], true) || Auth::isPreview()) fail('Sem permissão para este canal realtime.', 403);
            return;
        }

        if (preg_match('/^user:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            if (Auth::isPreview() || (int)$m[1] !== (int)$user['id']) fail('Sem permissão para este canal realtime.', 403);
            return;
        }

        if (preg_match('/^client:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            Auth::client((int)$m[1], 'read');
            return;
        }

        if (preg_match('/^demand:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            Auth::requireAbility('tasks.read');
            Auth::demand((int)$m[1]);
            return;
        }

        if (preg_match('/^project:([1-9][0-9]{0,9})$/D', $channel, $m)) {
            $project = DB::one('SELECT id,client_id FROM projects WHERE id=?'.(WorkspaceContext::legacyCiFixture()?'':' AND workspace_id=?').' LIMIT 1',WorkspaceContext::legacyCiFixture()?[(int)$m[1]]:[(int)$m[1],Auth::workspaceId()]);
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
