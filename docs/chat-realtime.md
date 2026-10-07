# Chat entre usuários — WebSocket

O TaskEsc possui chat direto entre usuários autenticados, usando a infraestrutura realtime já existente (Outbox → worker → Centrifugo → WebSocket).

## Escopo

- mensagens de texto em conversas 1:1;
- histórico persistido no MySQL;
- contador de não lidas;
- confirmação de leitura;
- atualização em tempo real entre abas/computadores;
- fallback pelo refresh HTTP periódico já existente;
- sem anexos nesta primeira versão.

## Privacidade e acesso

O chat existe somente para sessão web autenticada. Tokens de API/MCP não podem ler nem enviar mensagens.

Regras de contato:

- Administrador: pode conversar com qualquer usuário ativo;
- Equipe: pode conversar com Administradores, outros membros da Equipe e Clientes que compartilhem ao menos um cliente vinculado;
- Cliente: pode conversar com Administradores e usuários que compartilhem ao menos um cliente vinculado;
- Visualização de cliente (preview): chat desabilitado.

O corpo da mensagem nunca é publicado no Centrifugo. O evento WebSocket contém apenas IDs necessários para o navegador buscar a mensagem pelos endpoints HTTP autorizados.

## Banco

Schema 22 adiciona:

- `chat_threads`: par único de usuários, última mensagem e timestamps;
- `chat_messages`: remetente, destinatário, corpo, nonce idempotente, criação e leitura.

A migration `022_user_chat.sql` é aplicada por `bin/migrate-chat.php` somente depois de validar o backup completo pré-deploy. O schema 20 do SaaS removido continua bloqueado.

## Realtime

Ao enviar uma mensagem, a mesma transação MySQL grava:

1. a mensagem;
2. a atualização da conversa;
3. dois eventos no Outbox, um para `user:{sender_id}` e outro para `user:{recipient_id}`.

O worker publica `chat.message` no Centrifugo. O frontend recebe o evento, atualiza o badge e busca o conteúdo real via `chat.messages`.

Ao ler mensagens, `chat.read` é enviado aos dois usuários para atualizar o estado de leitura.

## Endpoints web

- `chat.contacts`
- `chat.messages?peer_id={id}`
- `chat.send` (POST)
- `chat.read` (POST)

POSTs continuam protegidos por CSRF.

## Idempotência

Cada envio usa `client_nonce` de 32 caracteres hexadecimais. O índice único `(sender_id, client_nonce)` impede mensagem duplicada quando uma requisição é repetida.

## Interface

A rota `/chat` exibe:

- lista de contatos;
- busca;
- última mensagem;
- badge de não lidas;
- conversa atual;
- indicador de enviada/lida;
- composer com Enter para enviar e Shift+Enter para quebrar linha.

Em telas menores, a interface se reorganiza em uma coluna.
