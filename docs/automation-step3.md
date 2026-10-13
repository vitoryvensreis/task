# Automações — Passo 3: núcleo, WhatsApp e worker

Este passo cria a fundação executável para o editor visual de automações do TaskEsc. O editor em árvore entra no passo seguinte; neste passo o motor, persistência, gatilhos e entrega WhatsApp já ficam prontos e testáveis.

## Schema 24

O schema 24 adiciona:

- `users.whatsapp_phone`;
- `clients.whatsapp_phone`;
- `demands.whatsapp_recipient_mode`;
- `automations`;
- `automation_runs`;
- `automation_run_steps`;
- `automation_worker_state`;
- `whatsapp_messages`.

A migração é isolada em `database/migrations/024_automation_core.sql` e executada por `bin/migrate-automation.php` somente depois do backup obrigatório validado pelo deploy.

O schema 20 removido continua proibido e não é recriado.

## Números WhatsApp

Novos usuários e clientes exigem um número em formato DDI + DDD + número. O backend remove a formatação e grava apenas dígitos.

Exemplo:

```text
+55 (85) 99999-9999
↓
5585999999999
```

Na migração, clientes antigos recebem automaticamente `whatsapp_phone` quando o campo `phone` existente puder ser normalizado com segurança. Usuários antigos permanecem sem número até serem editados, porque o TaskEsc não deve inventar ou inferir um telefone.

## Destinatário por Task

Cada Task possui:

```text
none           → não enviar WhatsApp relacionado à task
assigned_user  → usar WhatsApp do responsável
client         → usar WhatsApp do cliente
```

A decisão fica na própria Task. Assim, uma automação usa "destinatário da Task" e não precisa armazenar números em sua definição.

Rascunhos nunca disparam a ação WhatsApp.

## Gatilhos iniciais

O motor reconhece eventos de negócio que já passam pelo Outbox:

- Task criada;
- Task editada;
- Task excluída;
- mudança de coluna;
- reordenação;
- comentário;
- checklist;
- horas;
- alteração de escopo;
- arquivo enviado;
- arquivo excluído;
- aprovação/reprovação de versão.

Não existe um segundo sistema paralelo de eventos.

## Modelo do fluxo

As automações são salvas em `definition_json`:

```json
{
  "version": 1,
  "nodes": [
    {"id":"trigger","type":"trigger","config":{"event":"demand.created"}},
    {"id":"condition","type":"condition","config":{"field":"client_id","operator":"eq","value":"7"}},
    {"id":"send","type":"action.whatsapp","config":{"recipient":"task","message":"Nova task: {{task.title}}"}},
    {"id":"end","type":"end","config":{}}
  ],
  "edges": [
    {"from":"trigger","to":"condition","branch":"next"},
    {"from":"condition","to":"send","branch":"yes"},
    {"from":"send","to":"end","branch":"next"}
  ]
}
```

O backend valida IDs, tipos, condições, conexões e rejeita ciclos. Essa estrutura será a fonte de verdade do editor visual do próximo passo.

## Condições

Campos iniciais:

- cliente;
- projeto;
- categoria;
- etapa;
- prioridade;
- responsável;
- destinatário WhatsApp;
- rascunho;
- situação comercial;
- tipo de alteração do evento.

Operadores:

- igual;
- diferente;
- em lista;
- fora da lista;
- vazio;
- não vazio.

## Ação WhatsApp

A ação usa o WPPConnect interno já configurado. Variáveis iniciais:

- `{{task.id}}`;
- `{{task.title}}`;
- `{{task.due_at}}`;
- `{{task.priority}}`;
- `{{task.url}}`;
- `{{client.name}}`;
- `{{assigned.name}}`;
- `{{stage.name}}`.

A automação nunca recebe SECRET_KEY ou token do provider.

## Worker

O serviço `taskesc-automation-worker.service` roda separado do PHP web e do worker realtime.

Fluxo:

```text
event_outbox
   ↓
cursor automation_worker_state
   ↓
automações ativas para o event_type
   ↓
automation_runs
   ↓
nós/condições
   ↓
WPPConnect quando aplicável
```

Na primeira instalação, o cursor é colocado no maior ID atual do Outbox. Portanto eventos históricos não são reexecutados.

O worker usa `operation_id` para deduplicar o fanout do mesmo evento em vários canais. Uma mutação de Task gera no máximo uma execução por automação.

## Retry e idempotência

- cada automação + `operation_id` é única;
- cada etapa de uma execução é única;
- até 4 tentativas para falhas transitórias;
- backoff exponencial;
- runs abandonadas em `running` são recuperadas;
- etapas já concluídas não são repetidas;
- uma entrega WhatsApp confirmada não é enviada novamente;
- uma interrupção ambígua durante o envio é marcada para revisão em vez de disparar uma possível duplicata cegamente.

## APIs administrativas

- `automation.catalog`;
- `automation.list`;
- `automation.get`;
- `automation.save`;
- `automation.toggle`;
- `automation.runs`.

Somente Administradores têm acesso.

## Próximo passo

O Passo 4 adicionará a área **Automações** e o editor visual em árvore/canvas. Ele editará exatamente o `definition_json` já validado e executado por este motor, incluindo ramificações SIM/NÃO e configuração visual da ação WhatsApp.
