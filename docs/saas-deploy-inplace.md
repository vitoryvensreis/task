# TaskEsc SaaS — Atualização gradual na VPS, site e banco JÁ EXISTENTES

**Decisão do projeto (17/10/2026):** utilizar a mesma VPS, `task.escotilhaprodutora.com`, a instalação atual do TaskEsc e o banco MySQL/MariaDB existente. NÃO exigir subdomínio ou banco de homologação separados. **Isso não autoriza habilitar assinantes externos ou modificar os workers globais antes da auditoria.**

**Estado atual:** documentação e ferramentas de pré-auditoria implementadas SOMENTE na branch `feat/saas-b1-foundation`, PR #113 em rascunho. **Nenhum deploy na VPS, migração ou alteração na base produtiva foi executado nesta etapa**. Ainda não existe autorização técnica para ativar o multiempresa.

## Etapa 0 — Inspeção somente de leitura

O script `bin/saas-inplace-preflight.php` usa `SaasInPlacePreflight`. Faz apenas SELECTs; informa schema operacional, marcadores de migração de 1 a 8, integridade estrutural básica e bloqueadores (contas externas, flags ativas, legado SaaS schema 20 etc.). Não imprime dados de clientes, credenciais, telefones ou segredos.

Após a atualização do código autorizada na VPS, executar no diretório real da aplicação:

```bash
php bin/saas-inplace-preflight.php --check
```

**O resultado `ready_for_manual_review=true` NÃO significa que é seguro migrar automaticamente.** Antes disso, confirmar volumetria real de dados, espaço livre, locks/tempo estimado dos `ALTER TABLE`, PHP/DB versões, sessões/filas/cron ativos e compatibilidade com integrações existentes. Se houver qualquer bloqueador, PARAR.

## Etapa 1 — Backup e ponto de retorno

Antes de qualquer atualização do código ou alteração do banco existente:

1. Backup íntegro de todos os dados, rotinas e eventos SQL (mysqldump/mariadb-dump, `--single-transaction --quick --routines --triggers --events`, e checksum SHA256).
2. Snapshot do código real, incluindo diferenças locais, e cópia segura da configuração; sem expor arquivos sensíveis em artefatos públicos.
3. Copiar o backup para armazenamento FORA da VPS e verificar restauração em ambiente offline antes de realizar DDL.
4. Registrar commit ativo `main`, versão de schema (30 esperada), data, inventário de workers e procedimento de reversão.
5. Rodar `php bin/saas-inplace-preflight.php --release-check --backup-manifest=/CAMINHO/ABSOLUTO/storage/backups/taskesc-predeploy-<timestamp>.txt`, que valida checksum e exige backup recente de até 2h. **Esse comando não aplica migrations nem faz deploy**.

O pipeline existente já tem etapa obrigatória de backup de banco+snapshot do código e health pós-deploy; entretanto **não contém ainda as novas migrações SaaS como etapas autorizadas** e não substitui teste real de recuperação de banco. Não disparar o pipeline a partir de um branch WIP.

## Etapa 2 — Primeiro rollout aditivo (AINDA BLOQUEADO)

Após a aprovação de regressões, capacidade da VPS, plano de rollback e janela de manutenção, atualizar o código **com os recursos SaaS desabilitados**. As classes e APIs novas dependem de marcadores de migração; não podem liberar empresas novas. Não efetuar merge integral do PR WIP apenas para obter deploy.

Aplicar migrações SaaS **individualmente** e manualmente no banco atual, usando os comandos CLI com `--dry-run` e `--apply`, com o mesmo manifesto de backup verificado:
```bash
php bin/migrate-saas-foundation.php --dry-run --backup-manifest=/BACKUP/manifest.txt
php bin/migrate-saas-foundation.php --apply --backup-manifest=/BACKUP/manifest.txt
# Somente depois de verificar o B1.1 e preparar janela para a próxima:
php bin/migrate-saas-domain.php --dry-run --backup-manifest=/BACKUP/manifest.txt
php bin/migrate-saas-domain.php --apply --backup-manifest=/BACKUP/manifest.txt
php bin/migrate-saas-children.php --dry-run --backup-manifest=/BACKUP/manifest.txt
php bin/migrate-saas-children.php --apply --backup-manifest=/BACKUP/manifest.txt
# Outbox, receipts, delivery, canais e inbox: só após validar cada subetapa.
```

**Cuidado:** migrações de domínio/filhos executam `ALTER TABLE` e backfill em 31 tabelas existentes. DDL MySQL não é reversível por simples ROLLBACK. Não assumir que a implantação será instantânea ou sem indisponibilidade. Não criar contas SaaS externas antes de isolar 100% dos controladores/filas/arquivos/relatórios e testar duas empresas. Não habilitar publicadores para WhatsApp, push, IA, calendário ou Centrifugo. O schema operacional deve permanecer em **30**; schema 20 SaaS antigo permanece excluído.

## Etapa 3 — Verificação de saúde e saída segura

Validar login, usuários, clientes, projetos, tasks e Kanban atuais, anexos/Drive, relatórios, calendário interno, filas operacionais, automações existentes e health, incluindo registros de erro. Só manter o código novo ativo após passar em smoke/regressão. Novas rotas não devem ser habilitadas para clientes externos.

Se houver falha, retornar ao commit/snapshot de código anterior; se tiver ocorrido DDL/backfill e for necessário voltar o banco, executar **restauração do dump íntegro com parada coordenada das escritas**. Em produção, isso exige janela operacional e pode perder alterações realizadas após o backup — por isso não rodar migrations de alto impacto sem manutenção e plano de restauração validado. Nunca fazer rollback do esquema alterando `schema_version` manualmente.

## Limitação operacional da conexão atual

O conector GitHub permite consultar/editar código e inspecionar CI/PR, mas **não oferece uma sessão SSH, console da VPS ou disparo de workflow por aqui**. Logo, a implantação no servidor não pode ser afirmada como concluída. O código segue no PR #113 até existir mecanismo autorizado para executar backup, deploy e smoke na VPS existente. Não é necessário criar outra instalação; o bloqueio é de acesso de execução e maturidade do isolamento multiempresa.

A orientação anterior de criar banco/subdomínio de homologação separados foi substituída pela escolha explícita de atualização gradual **na instalação atual**, com todo cuidado para que a operação ativa continue funcionando.
