# Automações — gatilhos de cliente, prazo e destinatários WhatsApp

Esta evolução amplia o editor visual sem alterar o schema 24.

## Novos gatilhos

Os gatilhos agora aparecem agrupados no editor.

### Tasks

Além dos eventos já existentes:

- **Task próxima da data de entrega** — configurável de 1 a 720 horas antes;
- **Task atrasada** — dispara quando a data de entrega passou e a task continua aberta.

Tasks concluídas, canceladas, arquivadas, excluídas ou em rascunho não entram nesses gatilhos de prazo.

### Clientes

- **Cliente criado**;
- **Conta da equipe criada para cliente**.

A criação de cliente e a criação da conta de Equipe são gravadas no mesmo Outbox transacional da operação de negócio. Para a conta de Equipe, é gerado um evento por cliente vinculado à nova conta.

### Financeiro

- **Cobrança próxima do vencimento** — configurável de 1 a 60 dias antes;
- **Cobrança atrasada**.

Cobranças canceladas, excluídas ou já totalmente liquidadas não entram nos gatilhos de vencimento.

## Idempotência dos gatilhos de data

O worker verifica gatilhos temporais periodicamente. Cada execução usa um `operation_id` determinístico com:

- automação;
- tipo do gatilho;
- entidade;
- data de entrega/vencimento.

Assim, a mesma automação não dispara repetidamente para a mesma data. Se a data da task ou cobrança mudar, a nova data pode gerar uma nova ocorrência válida.

## Destinatário por ação WhatsApp

O bloco **Enviar WhatsApp** agora possui um seletor próprio.

Em gatilhos de Task, as opções são:

- **Usar seleção da própria task**;
- **Responsável pela task**;
- **Quem criou a task**;
- **Cliente**;
- **Número específico**.

Em gatilhos de Cliente ou Financeiro, as opções disponíveis são:

- **Cliente**;
- **Número específico**.

Ao escolher **Número específico**, o administrador informa DDI + DDD + número. O backend normaliza e valida o telefone antes de salvar.

A opção **Usar seleção da própria task** continua respeitando o campo existente na Task:

- não enviar;
- quem criou a task;
- cliente.

Portanto, o responsável pela execução só recebe quando a automação escolher explicitamente **Responsável pela task**.

## Variáveis de mensagem

Além das variáveis de Task, o editor oferece contexto de cliente, cobrança e conta de Equipe.

### Cliente

- `{{client.id}}`
- `{{client.name}}`
- `{{client.email}}`
- `{{client.whatsapp}}`

### Cobrança

- `{{invoice.id}}`
- `{{invoice.description}}`
- `{{invoice.due_on}}`
- `{{invoice.amount}}`
- `{{invoice.status}}`

### Conta de Equipe

- `{{team.name}}`
- `{{team.email}}`

As variáveis de Task continuam disponíveis, incluindo `{{assigned.name}}` e `{{creator.name}}`.

### Link do Google Drive

- `{{link do drive}}` — usa o link compartilhável da pasta **Entrega final** da task, o mesmo do botão **Copiar link do Drive** nos detalhes da task.

O link é resolvido somente quando a mensagem precisa dessa variável, reutilizando a integração Google Drive existente. Se ainda não existir uma pasta de entrega final compartilhável, o valor fica vazio; por isso, prefira esse token em notificações após o envio de arquivos finais. O sistema não expõe automaticamente a pasta de trabalho privada da task.


## Exemplos

Lembrete financeiro:

```text
Gatilho: Cobrança próxima do vencimento
Antecedência: 3 dias
Destinatário: Cliente

Olá {{client.name}}!
Sua cobrança de {{invoice.amount}} vence em {{invoice.due_on}}.
```

Aviso de nova conta de Equipe:

```text
Gatilho: Conta da equipe criada para cliente
Destinatário: Cliente

Olá {{client.name}}!
A conta de {{team.name}} foi criada e vinculada ao seu atendimento.
```

Notificação interna de Task:

```text
Gatilho: Task criada
Destinatário: Responsável pela task

Nova task: {{task.title}}
{{task.url}}
```
