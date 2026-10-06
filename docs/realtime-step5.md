# Realtime — Passo 5: eventos reais das tasks

Este passo conecta as mutações reais de `Demands` à infraestrutura Outbox → worker → Centrifugo já validada nos Passos 1–4. O frontend ainda não altera cards a partir das publicações; isso permanece para a etapa seguinte.

## Garantia transacional

Os eventos são gravados por `DemandRealtime::emit()`, que usa `EventOutbox::enqueue()`. Como o Outbox exige uma transação MySQL já aberta, a alteração de negócio e os eventos correspondentes compartilham o mesmo commit:

- se a task é salva e o evento é gravado, ambos fazem commit;
- se qualquer parte falha, ambos fazem rollback;
- o worker continua responsável por retry e publicação idempotente no Centrifugo.

As operações `stage`, `delete` e `quote` passam a executar em `DB::tx()`. A ação em lote mantém o comportamento incremental existente, mas cada task individual é atualizada e enfileirada atomicamente.

## Eventos

| Mutação | Evento |
| --- | --- |
| criar task | `demand.created` |
| editar task / prioridade / responsável / orçamento | `demand.updated` |
| mudar etapa | `demand.stage_changed` |
| reordenar/mover no Kanban | `demand.reordered` |
| excluir task | `demand.deleted` |

O payload é propositalmente pequeno. Ele contém somente IDs e estado necessário para uma futura invalidação/recarga: `demand_id`, `client_id`, `project_id`, `stage_id`, `version`, `draft`, `updated_at` e metadados escalares da mudança. Briefing, notas internas, valores financeiros e outros campos sensíveis/pesados não são publicados.

## Canais do Passo 5

Uma task publicada gera fanout para:

- `kanban/global`;
- `demand:{id}`;
- `project:{id}`, quando existir projeto.

Se a task trocar de projeto, o projeto anterior também recebe o evento para poder invalidar sua visão.

Rascunhos ficam somente em `kanban/global`, que já é autorizado apenas para administrador real. Isso evita expor a existência de um rascunho por canais de demanda/projeto antes da publicação.

## Sem alteração visual

O `realtime-client.js` continua apenas emitindo `taskesc:realtime-publication`. Nenhum card é criado, removido ou movido automaticamente neste passo.

A próxima etapa deve:
1. definir/assinar os canais de coleção necessários para Equipe/Cliente;
2. consumir `taskesc:realtime-publication`;
3. aplicar invalidação/refresh com debounce e proteção por versão;
4. manter o fluxo HTTP como fallback.
