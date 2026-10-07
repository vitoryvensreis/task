# Realtime completo — Tasks, Chat e Agenda

O TaskEsc usa o WebSocket como **gatilho de invalidação**, mantendo MySQL + endpoints HTTP autorizados como fonte de verdade. Assim, uma alteração é percebida imediatamente pelos outros navegadores sem transportar briefing, comentários, arquivos ou dados de agenda pelo Centrifugo.

## Tasks / Kanban

As operações abaixo produzem eventos Outbox e chegam aos navegadores por Centrifugo:

- criação e edição de task;
- movimentação de etapa e reordenação no Kanban;
- exclusão;
- alteração em lote de etapa, prioridade ou responsável;
- decisão comercial/orçamento;
- comentário público;
- comentário interno;
- checklist;
- lançamento de tempo;
- solicitação/resposta de alteração de escopo;
- arquivo/link adicionado;
- upload resumível concluído no Google Drive;
- arquivo removido;
- aprovação ou pedido de ajustes em uma versão.

O progresso de cada bloco de upload **não** é publicado no WebSocket. Somente a conclusão do arquivo gera evento. Isso evita milhares de eventos durante uploads grandes.

Comentários internos, horas e arquivos privados usam `staff/global` e nunca são enviados aos canais de Cliente. Eventos públicos continuam usando os canais `client:{id}`, `demand:{id}` e `project:{id}`, além do canal administrativo.

Os payloads do WebSocket carregam somente IDs, versão e metadados de invalidação. Corpo de comentário, briefing, URL de arquivo, nome privado, valores e outros conteúdos continuam restritos ao HTTP autorizado.

## Chat administrativo

O chat continua exclusivo entre Administradores.

- entrega de nova mensagem: `chat.message`;
- confirmação de leitura: `chat.read`;
- canais pessoais: `user:{id}`;
- corpo da mensagem fica no MySQL e é buscado por HTTP autenticado.

O botão flutuante, popup e comportamento mobile permanecem iguais.

## Agenda / Calendário

Administradores e Equipe recebem o canal `agenda/global`.

O realtime cobre:

- criação e edição de compromisso;
- cancelamento;
- resolução de conflito Google/TaskEsc;
- seleção e configuração de agenda;
- confirmação de sincronização com Google iniciada pelo TaskEsc;
- alterações detectadas no Google Calendar pelo webhook, cron ou sincronização manual;
- eventos novos, alterados ou removidos durante o pull do Google;
- atualização de disponibilidade `freeBusy`.

Quando uma publicação `agenda.*` chega, a tela Agenda é recarregada com debounce e preservação de posição. Se um detalhe de compromisso estiver aberto, o TaskEsc busca novamente esse compromisso; se ele tiver sido cancelado/removido, o modal é fechado com aviso.

O payload da Agenda não contém título, descrição, participantes, local, links de Meet nem anexos. O WebSocket apenas informa que a agenda mudou e o navegador chama `agenda.list`/`agenda.detail` pelas permissões normais.

## Canais

| Canal | Público autorizado |
| --- | --- |
| `kanban/global` | Administrador |
| `staff/global` | Administrador e Equipe |
| `agenda/global` | Administrador e Equipe |
| `client:{id}` | usuários com acesso ao cliente |
| `demand:{id}` | usuários com acesso à task |
| `project:{id}` | usuários com acesso ao projeto |
| `user:{id}` | somente o próprio usuário |

Preview de Cliente não recebe canais de Equipe/Agenda/Administrador.

## Garantia transacional

Sempre que a mudança é puramente MySQL, alteração de negócio + evento Outbox compartilham a mesma transação.

Operações que dependem de sistemas externos são divididas corretamente:

1. o efeito externo (ex.: upload confirmado no Google Drive) é concluído;
2. o registro local + evento de conclusão são gravados juntos;
3. sincronizações Google Calendar detectam mudanças remotas e registram atualização local + invalidação realtime em transação.

O worker do Outbox continua responsável por retry e publicação idempotente.

## Frontend e fallback

Publicações são agrupadas com debounce para evitar tempestade de refresh. O TaskEsc:

- adia redraw durante digitação/drag;
- preserva filtros/scroll do Kanban;
- atualiza modal de task em detalhe;
- preserva formulário de edição e usa controle de versão;
- atualiza a Agenda e detalhes de evento;
- acumula invalidações em aba oculta e executa ao voltar.

O polling HTTP de 30 segundos permanece como fallback de segurança caso o WebSocket esteja indisponível.
