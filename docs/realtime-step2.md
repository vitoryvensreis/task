# Realtime — Passo 2: Outbox transacional

Schema atual anterior: **19**. Novo schema: **21**. O número **20** identifica a
fundação SaaS removida no histórico (`93e7942`); permanece reservado e nunca é
reexecutado. Esta mudança cria somente `event_outbox`. Não há workspace, tenant,
novos hooks nas tasks, consumidores, publicação no Centrifugo ou alterações visuais.
O Passo 1 continua com `REALTIME_ENABLED=false`.

## Migração e segurança

`database/migrations/021_event_outbox.sql` contém somente o DDL idempotente.
`Migrations::outbox()` aceita exclusivamente schema 19/21, obtém um advisory lock,
limita a espera por metadata locks e valida engine, campos, JSON e índices antes
de avançar o marcador para 21. Uma tabela existente incompatível bloqueia o avanço
sem ser recriada ou alterada. A versão 20 é recusada. DDL nunca roda dentro de uma
transação de negócio: MySQL/MariaDB fazem commit implícito de DDL.

O workflow cria um dump completo (dados, estrutura, triggers, routines e events),
valida gzip/SHA256 e guarda uma cópia fora da VPS antes do deploy/migração.
`bin/migrate-outbox.php --backup-manifest=/caminho/do/manifesto.txt` exige e verifica
esse backup. Executa somente esta migração, recusa restos de SaaS e compara o DDL
das tabelas anteriores antes/depois. O schema de instalação nova também recebe 21.
Em instalações existentes, faça sempre backup antes de enviar código atualizado:
`Migrations::run()` mantém o mecanismo existente de atualização automática.

## Estrutura

InnoDB, charset `utf8mb4` e timestamps UTC `DATETIME(6)`.

| Campo | Uso |
|---|---|
| `id BIGINT UNSIGNED` | Identidade monotônica do evento; chave primária |
| `event_key CHAR(64)` | SHA256 da chave estável de operação, única |
| `event_hash CHAR(64)` | Detecta reutilização da chave com outro conteúdo |
| `event_type`, `entity_type`, `entity_id` | Identificação do evento e entidade |
| `channel` | Canal previsto para o futuro publisher |
| `payload_json JSON` | Payload validado; objeto com chaves ordenadas para dedupe |
| `created_by INT NULL` | ID do ator; NULL para operações internas |
| `created_at`, `available_at` | Criação e próximo instante elegível |
| `published_at NULL` | Reservado para confirmação pelo futuro publisher |
| `attempts INT UNSIGNED` | Contador de falhas/reagendamentos |
| `last_error VARCHAR(80)` | Apenas código técnico estável, sem erros brutos/secrets |

IDs não têm FKs: a fila preserva o evento mesmo após exclusão da entidade/ator.
Strings de roteamento e hashes usam `ascii_bin` para comparações exatas.

| Índice | Colunas | Finalidade |
|---|---|---|
| `PRIMARY` | `id` | Identidade e acesso direto |
| `uq_event_outbox_event_key` | `event_key` | Deduplicação concorrente |
| `idx_event_outbox_ready` | `published_at, available_at, id` | Busca ordenada e limitada de eventos elegíveis |
| `idx_event_outbox_entity` | `entity_type, entity_id, id` | Histórico de uma entidade |
| `idx_event_outbox_published` | `published_at, id` | Futuro arquivamento dos publicados |

## API backend

`EventOutbox` usa **a mesma conexão PDO de `DB::conn()`**. `enqueue()` e `retry()`
recusam gravação sem transação aberta. O helper possui commit/rollback próprios e
savepoints quando chamado dentro de outra transação. Não altera `DB::tx()`.

```php
EventOutbox::transaction(function () use ($requestId, $taskId, $actorId) {
    // Exemplo para integração futura; nenhum fluxo atual foi modificado.
    DB::update('demands', $taskId, ['updated_at' => now()]);
    EventOutbox::enqueue(
        $requestId.':task:'.$taskId.':updated', // Reusar a MESMA chave em retries.
        'task.updated', 'task', $taskId, 'task:'.$taskId,
        ['id' => $taskId], $actorId
    );
});
```

O callback deve executar apenas DML transacional nessa conexão, sem DDL, commit
manual ou chamadas externas. Exceções devem alcançar o helper para desfazer toda a
operação. `DB::tx()` existente também pode envolver `enqueue()` diretamente.

O índice único retorna o mesmo ID para evento repetido com mesmo conteúdo, inclusive
sob concorrência. A chave identifica **um evento**, incluindo tipo/entidade/canal
no identificador quando uma operação gerar vários eventos. Outro conteúdo para a
mesma chave produz conflito e rollback. Ordem de chaves de objetos não gera conflito;
ordem de listas é significativa. Timestamps de criação/agendamento e estado de retry
originais são preservados. Isto deduplica o evento; o chamador continua responsável
pela idempotência da alteração de negócio.

`pending(1..500)` faz somente leitura limitada, ordenada por `available_at,id`.
`retry(id, expectedAttempts, errorCode, delaySeconds)` usa compare-and-swap, incrementa
uma única vez e adia a disponibilidade (1 a 86400 segundos). Payloads têm limite de
1 MiB; contadores e IDs são unsigned. Erros brutos, senhas e tokens não devem integrar
os eventos. Não se grava nem publica nenhum evento pelos fluxos atuais.

O publisher, claim/leases, backoff máximo, confirmação de envio, limpeza e dedupe
no consumidor são etapas futuras. `pending()` não reserva linhas. O ID persistente
permite ao futuro consumidor deduplicar entregas; não há promessa de publicação
exactly-once ou ordenação global por horário de commit.

## Verificação

CI em MySQL 8 e MariaDB 10.11 testa instalação nova/upgrade, reexecução, schema 20
recusado, tabela incompatível, commit/rollback conjunto, savepoints, duplicação em
processos concorrentes, conflitos de payload, retry CAS e consulta indexada em lote.

`php bin/system-audit.php --strict --offline --expect-schema=21` verifica integridade
e a estrutura da Outbox sem executar limpeza ou renovar tokens Google. O relatório
completo fica privado na VPS; o workflow imprime somente contagens e códigos.
Health público e health Redis/Centrifugo pelo PHP-FPM permanecem obrigatórios.
