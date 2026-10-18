# Workspace da Gabriella — pré-cadastro (Etapa 3)

**Identidade confirmada:** `gabriella-diasl@hotmail.com`.

O atual TaskEsc só permite um workspace operacional. Ainda existem 44 `workspace_id` com `DEFAULT` apontando para Escotilha Produtora, e serviços não estão totalmente isolados (Google/Drive, cron, WPP e parte das queries). Criar imediatamente uma segunda empresa na tabela `workspaces` faria os próprios checks de login/migração falharem e arriscaria vincular novos registros ao Vitor.

## O que foi criado nesta entrega

- `platform_tenant_provisioning` (migração 036), guardando exclusivamente o **pré-cadastro** de um futuro workspace.
- Identidade do proprietário existente e confirmado: Gabriella, com nome `Workspace Gabriella` e slug reservado `gabriella`.
- Sem criar nenhuma linha extra em `workspaces`, sem transferir `workspace_members` e sem afetar os 44 grupos de dados existentes.
- Configuração inicial proposta: módulos de tarefas, automações e contatos; Kanban com A fazer → Em andamento → Em revisão → Concluído; **sem tarefas, clientes, contas Google, arquivos ou finanças**.
- Cadastro idempotente executado no deploy após as migrações atuais, com verificação da conta e do workspace original.
- O ADM Master exibe esse pré-cadastro ao lado da empresa ativa do Vitor, identificado claramente como pendente e sem acesso de suporte ou gestão de plano.
- A conta da Gabriella continua no workspace existente, até o corte final.

## Próximo passo para ativação

Concluir a Etapa 2 (escopo dos endpoints restantes, Drive/Google, cron/workers, webhooks, suporte Master e canais), retirar os defaults antigos e adaptar os guards legados de workspace único. No corte final, inserir `workspaces`, definir Gabriella como proprietária, vinculá-la unicamente ao novo workspace, configurar o plano e criar as colunas iniciais vazias de Kanban.

A criação e ativação do **workspace operacional** não fazem parte desta PR. A lista no ADM Master distingue propositalmente **1 empresa ativa** e **1 pré-cadastro**.
