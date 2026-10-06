# Realtime — Passo 6: Kanban e telas atualizadas por WebSocket

Este passo liga o frontend do TaskEsc às publicações produzidas no Passo 5. A aplicação HTTP continua sendo a fonte de verdade e o polling de 30 segundos permanece como fallback.

## Canais de coleção

Foi adicionado o canal `client:{id}`.

- Administrador continua recebendo `kanban/global`.
- Equipe recebe `client:{id}` para cada cliente vinculado.
- Cliente recebe `client:{id}` para cada cliente ao qual possui acesso.
- Modo de visualização recebe somente o canal do cliente selecionado e não herda `user:{admin}` nem `kanban/global`.

A autorização do canal reutiliza `Auth::client(..., 'read')`, portanto o WebSocket não cria uma regra de permissão paralela.

Cada mutação publicada de uma task não-rascunho agora faz fanout também para `client:{client_id}`. Rascunhos continuam restritos a `kanban/global`.

## Deduplicação de fanout

Uma única mutação pode ser publicada em mais de um canal. O backend adiciona `operation_id` ao payload e todos os eventos do mesmo fanout compartilham esse identificador.

O frontend mantém uma janela curta de deduplicação por `operation_id` para evitar redesenhar a interface duas vezes quando o mesmo navegador estiver inscrito em canais sobrepostos.

## Atualização do frontend

`app.js` passa a consumir `taskesc:realtime-publication`.

Eventos de `demand.*` são agrupados por task e processados com debounce. Quando necessário, o TaskEsc invalida e recarrega via API HTTP as telas afetadas:

- Dashboard;
- Kanban;
- Tasks;
- Financeiro;
- Relatórios.

A publicação WebSocket é apenas o gatilho de invalidação. Dados completos continuam vindo dos endpoints HTTP já autorizados, evitando duplicar regra de permissão ou enviar briefing/notas/financeiro pelo Centrifugo.

## Proteções de UX

- redraw é adiado enquanto o usuário está digitando/editando ou arrastando um card;
- filtros atuais do Kanban são restaurados depois do refresh;
- scroll é preservado pelo mecanismo `stableRender` já existente;
- uma task aberta em modo detalhe é recarregada se receber atualização;
- uma task excluída remotamente fecha o modal aberto;
- uma task em edição não é sobrescrita: o usuário recebe aviso e o controle de `version` já existente continua protegendo o save;
- abas ocultas acumulam a invalidação e aplicam ao voltar;
- o polling de 30 segundos permanece ativo como fallback quando realtime estiver indisponível.

## Sem mudança de schema

Este passo não adiciona migration nem altera `schema.sql`.
