# Realtime — Passo 4: autenticação do navegador

Este passo adiciona **somente** a autenticação cliente → Centrifugo e a conexão WebSocket. O publisher da Outbox e a atualização de cards do Kanban não fazem parte desta entrega.

## Contrato JWT usado

A implementação segue o contrato oficial do Centrifugo **6.9.7**:

- JWT de conexão: `HS256`, `sub` com o ID do usuário TaskEsc convertido para **string**, `iat` e `exp`.
- JWT de assinatura: os mesmos dados de identidade, mais `channel` exatamente igual ao canal solicitado.
- TTL adotado: **300 segundos**. O `centrifuge-js` renova os tokens pelos callbacks `getToken`.
- O segredo HMAC fica somente em `/etc/taskesc/realtime.json` e nunca é enviado ao navegador.
- O claim `channels` não é usado como ACL. Cada assinatura cliente recebe autorização própria no backend.

Referências oficiais consultadas antes da implementação:

- https://centrifugal.dev/docs/server/authentication
- https://centrifugal.dev/docs/server/channel_token_auth
- https://centrifugal.dev/docs/server/channel_permissions
- https://centrifugal.dev/docs/transports/client_api

## Endpoint

`POST /realtime.auth`

O endpoint aceita somente sessão web autenticada do TaskEsc, JSON e origem same-origin. Ele não aceita identidade fornecida pelo navegador.

Payload para token de conexão:

```json
{}
```

Payload para token de assinatura:

```json
{"channel":"demand:123"}
```

Campos como `user_id`, `client_id`, `actor_id`, `role`, `demand_id` e `project_id` são rejeitados. O usuário é sempre obtido da sessão TaskEsc.

## Canais e autorização

| Canal | Regra |
| --- | --- |
| `user:{id}` | somente o próprio usuário autenticado; bloqueado durante preview de cliente |
| `demand:{id}` | passa por `Auth::requireAbility('tasks.read')` e `Auth::demand()` |
| `project:{id}` | o projeto resolve o `client_id` no servidor e passa por `Auth::client(..., 'read')` |
| `kanban/global` | somente admin real, fora do preview de cliente |

Assim, admin, staff e client continuam obedecendo às regras atuais de `user_clients`, abilities e preview. Não existe confiança em IDs de cliente/usuário enviados pelo frontend.

## Frontend

O SDK oficial `centrifuge-js` **5.7.4** é vendorado localmente em `public/assets/vendor/`; nenhuma CDN externa é usada. O CI baixa exatamente `centrifuge@5.7.4`, valida `package.json`, guarda a licença, calcula SHA-256 e fixa os bytes no commit efetivamente implantado.

`public/assets/realtime-client.js`:

- obtém o connection JWT em `/realtime.auth`;
- abre `wss://task.escotilhaprodutora.com/realtime/connection/websocket`;
- usa backoff/reconnect do SDK (500 ms a 20 s) e backoff adicional apenas para falha inicial de autenticação/infra;
- renova connection e subscription JWTs com `getToken`;
- assina somente canais autorizados pelo endpoint;
- em falha realtime, não interfere nos fluxos HTTP existentes;
- registra apenas mensagens técnicas discretas em `console.debug`;
- não altera cards do Kanban nesta etapa.

A API futura `window.TaskEscRealtime.subscribe(channel)` já permite que o próximo passo peça `demand:{id}` ou `project:{id}` sem colocar lógica de permissão no navegador.

## Testes

O CI cobre:

1. formato e assinatura HS256 dos JWTs;
2. `sub` como string e `channel` exato;
3. login real de fixture via `Auth::login` → emissão de JWT;
4. matriz de permissões admin/staff/client e preview;
5. rejeição de identidade enviada pelo navegador;
6. MySQL 8 e MariaDB 10.11;
7. SDK local e ausência de CDN externa.

No deploy, após backup e atualização, `bin/realtime-smoke.php` cria uma sessão TaskEsc temporária para um usuário ativo, obtém connection/subscription JWTs no endpoint público, confirma negação de canal de outro usuário e faz handshake **WSS real** com Centrifugo: `connect` autenticado seguido de `subscribe` autenticado. Nenhum token ou segredo é impresso nos logs.

O health interno também exige que o nó Centrifugo reporte exatamente **6.9.7**; divergência de versão interrompe o deploy.
