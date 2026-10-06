# Realtime — Passo 4: autenticação do navegador

Este passo adiciona somente autenticação do navegador, autorização de canais privados e conexão WebSocket ao Centrifugo. O consumo do Outbox continua sendo responsabilidade do worker do Passo 3. Nenhum card do Kanban é alterado por eventos nesta etapa.

## Contrato Centrifugo 6.9.7

A implementação segue a documentação oficial do Centrifugo 6.9.7:

- JWT de conexão: HS256, `sub` com o ID do usuário TaskEsc convertido para string, `iat` e `exp`.
- JWT de assinatura: os mesmos dados de identidade, com o claim obrigatório `channel` exatamente igual ao canal autorizado.
- TTL dos tokens: 300 segundos.
- O segredo HMAC fica exclusivamente em `/etc/taskesc/realtime.json`; nunca é enviado ao navegador.
- Não usamos o claim `channels` do connection JWT como ACL. Cada client-side subscription é autorizada no TaskEsc e recebe um subscription JWT próprio.
- O Centrifugo deve continuar com client-side subscribe fechado por padrão. O smoke test confirma que uma assinatura sem token é negada.

Referências consultadas:
- https://centrifugal.dev/docs/server/authentication
- https://centrifugal.dev/docs/server/channel_token_auth
- https://centrifugal.dev/docs/server/channel_permissions
- https://centrifugal.dev/docs/transports/client_api
- https://centrifugal.dev/docs/server/server_api

## Endpoint

`POST /realtime.auth` é reescrito internamente para `public/realtime-auth.php`.

O endpoint exige:
- sessão web autenticada do TaskEsc;
- request same-origin;
- CSRF da sessão;
- JSON;
- realtime habilitado na configuração privada.

Connection token:
```json
{}
```

Subscription token:
```json
{"channel":"demand:123"}
```

Qualquer outro campo é rejeitado. Portanto `user_id`, `client_id`, `role`, IDs de entidade ou qualquer identidade enviada pelo browser jamais substituem a sessão do servidor.

## Canais privados

| Canal | Autorização |
| --- | --- |
| `user:{id}` | somente o próprio usuário autenticado, fora de preview |
| `demand:{id}` | `tasks.read` + `Auth::demand()`, herdando os vínculos atuais com cliente |
| `project:{id}` | projeto é resolvido no servidor e passa por `Auth::client(client_id, 'read')` |
| `kanban/global` | somente administrador real, fora de preview |

Admin, staff/equipe, client e preview continuam obedecendo a mesma política usada pelo TaskEsc HTTP.

## Frontend e fallback

O browser carrega localmente o SDK oficial `centrifuge-js 5.7.4` em `/assets/vendor/centrifuge-5.7.4.js`. O build/deploy baixa exatamente esse pacote oficial, valida nome/versão, preserva a licença e registra SHA-256. Não há CDN de Centrifugo em runtime.

`public/assets/realtime-client.js`:
- solicita connection JWT somente após o TaskEsc disponibilizar o CSRF da sessão;
- conecta no WebSocket configurado na VPS;
- renova connection/subscription tokens via `getToken`;
- usa reconexão do SDK com backoff entre 500 ms e 20 s;
- usa backoff adicional para indisponibilidade inicial sem bloquear a aplicação;
- expõe `TaskEscRealtime.subscribe(channel)`, mas toda autorização continua no backend;
- apenas emite `taskesc:realtime-publication`; não altera cards do Kanban;
- registra apenas status técnico discreto em `console.debug`.

Se realtime, token ou WebSocket falhar, os fluxos HTTP atuais continuam funcionando.

## Testes

CI em MySQL 8 e MariaDB 10.11 valida:
1. login real com `Auth::login`;
2. JWT HS256 e assinatura HMAC;
3. `sub` como string;
4. subscription JWT amarrado ao canal exato;
5. matriz admin/staff/client/preview;
6. rejeição de identidade fornecida pelo browser;
7. SDK local e ausência de CDN.

Após backup e deploy, `bin/realtime-smoke.php`:
1. cria usuário temporário de Equipe;
2. faz login pelo fluxo real do TaskEsc;
3. chama `/realtime.auth` usando sessão + CSRF;
4. confirma que canal de outro usuário é negado;
5. faz handshake WSS e `connect` autenticado;
6. confirma que subscribe sem token é negado;
7. confirma subscribe com token correto;
8. confirma que o mesmo token é rejeitado em outro canal;
9. remove a fixture e a sessão.

O health interno exige Redis OK, configuração ativa, Centrifugo OK e nó exatamente na versão 6.9.7.
