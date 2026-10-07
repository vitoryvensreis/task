# Chat administrativo — WebSocket

O TaskEsc possui um chat interno 1:1 **exclusivo entre usuários com perfil Administrador**, usando a infraestrutura realtime já existente (Outbox → worker → Centrifugo → WebSocket).

## Interface

O chat não ocupa uma página nem aparece no menu lateral.

Para administradores, ele aparece como um **botão flutuante no canto inferior direito**. Ao abrir:

- surge um popup com lista de administradores;
- existe pesquisa de contatos pelo nome;
- a conversa fica ao lado da lista em desktop;
- em celulares, a lista ocupa a tela e a conversa abre em uma visão própria com botão de voltar;
- há contador de mensagens não lidas no botão flutuante;
- o layout respeita viewport e safe areas de celulares.

Usuários de Equipe e Cliente não veem o botão e não possuem permissão no backend.

## Escopo

- mensagens de texto em conversas 1:1;
- histórico persistido no MySQL;
- contador de não lidas;
- confirmação de leitura;
- atualização em tempo real entre abas/computadores;
- fallback pelo refresh HTTP periódico já existente;
- sem anexos nesta versão.

## Privacidade e acesso

O chat existe somente para sessão web autenticada de um Administrador. Tokens de API/MCP, Equipe, Cliente e modo de visualização de cliente não podem ler nem enviar mensagens.

A lista de contatos retorna somente outros usuários ativos com `role='admin'`.

O corpo da mensagem nunca é publicado no Centrifugo. O evento WebSocket contém apenas IDs necessários para o navegador buscar a mensagem pelos endpoints HTTP autorizados.

## Banco

O schema 22 mantém:

- `chat_threads`: par único de usuários, última mensagem e timestamps;
- `chat_messages`: remetente, destinatário, corpo, nonce idempotente, criação e leitura.

Esta alteração de interface/permissão não exige novo schema.

## Realtime

Ao enviar uma mensagem, a mesma transação MySQL grava a mensagem, atualiza a conversa e registra os eventos no Outbox para os canais pessoais dos dois administradores.

O worker publica `chat.message` no Centrifugo. O frontend recebe o evento, atualiza o badge do botão flutuante e busca o conteúdo real pela API autenticada.

Ao ler mensagens, `chat.read` é enviado aos dois administradores para atualizar o estado de leitura.

## Endpoints web

- `chat.contacts`
- `chat.messages?peer_id={id}`
- `chat.send` (POST)
- `chat.read` (POST)

Todos exigem sessão web de Administrador. POSTs continuam protegidos por CSRF.

## Idempotência

Cada envio usa `client_nonce` de 32 caracteres hexadecimais. O índice único `(sender_id, client_nonce)` impede mensagem duplicada quando uma requisição é repetida.
