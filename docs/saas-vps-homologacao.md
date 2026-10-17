# TaskEsc SaaS — Homologação segura na VPS (sem tocar na produção)

> **Plano anterior, não adotado:** a decisão posterior é usar a instalação e banco existentes. Consulte [atualização gradual na infraestrutura atual](saas-deploy-inplace.md). Este documento permanece apenas como referência de uma alternativa isolada; **não criar subdomínio nem banco novo para atender ao pedido atual**.


**Estado:** procedimento preparado na branch `feat/saas-b1-foundation`; **a execução na VPS ainda não ocorreu**. O PR #113 continua WIP e não está liberado para merge/deploy de produção.

## Por que o workflow existente NÃO pode ser utilizado

O `.github/workflows/deploy.yml` atualiza a aplicação real no `DEPLOY_PATH`, executa migrações operacionais e instala/reinicia workers. Antes desta etapa, a opção `workflow_dispatch` também satisfazia `github.event_name != 'pull_request'`. Foi adicionada à branch uma condição explícita `github.ref == 'refs/heads/main'` para evitar disparos de produção originados de branches de funcionalidade; **isso não autoriza merge da branch inacabada**.

Não usar `git checkout feat/saas-b1-foundation` na pasta de produção, não executar os scripts SaaS no banco produtivo e não reutilizar segredos/realtime da operação existente.

## Preparação do ambiente segregado

A implantação inicial permitida é **somente dos arquivos de código**. Exige acesso SSH autorizado e um diretório *novo*, fora da árvore de produção. Não copiar `config/config.php`, `.env`, `installed.lock` nem o banco do TaskEsc ativo.

1. No servidor, identificar os caminhos reais de produção e homologação. Não presumir nomes; confirmar com o painel do servidor.
2. Criar um diretório exclusivo de homologação, por exemplo `/CAMINHO/ISOLADO/taskesc-saas-staging`, com proprietário/permissões corretos. Criar manualmente o arquivo vazio `.taskesc-saas-staging-allow` nesse diretório, somente após confirmar que **não aponta para produção**.
3. Em OUTRO diretório, criar um clone do repositório e selecionar a branch WIP:

```bash
git clone --branch feat/saas-b1-foundation --single-branch \
  https://github.com/vitoryvensreis/task.git taskesc-saas-source
cd taskesc-saas-source
git status --short
```

4. Executar **apenas** o script de preparação de código com dois caminhos confirmados:

```bash
bash ops/saas-stage-code-only.sh \
  /CAMINHO/REAL/DA/PRODUCAO \
  /CAMINHO/ISOLADO/taskesc-saas-staging
```

O script valida branch, checkout limpo, presença do marcador de homologação, existência da pasta de produção, impedimento de destinos iguais/filhos e PHP; cria uma versão imutável em `releases/<commit>` e aponta **somente** o `current` da homologação para ela.

**Este script não altera** banco de dados, vhost, domínio, certificados, PHP-FPM, cron, Systemd, URLs, webhooks, credenciais nem workers; portanto **não torna o SaaS acessível pelo navegador automaticamente**. Também não liga empresas externas.

## O que ainda falta para disponibilizar uma URL de homologação

É necessário confirmar no servidor um vhost/subdomínio de teste próprio, uma base SQL **separada**, credenciais próprias sem acesso de escrita ao banco produtivo, armazenamento/cache/sessões separados e desativação explícita de cron, e-mail, notificações, IA, Google Calendar integrado, WebSocket, WhatsApp e provedores externos. Depois, aplicar migrações 031–036 **somente nesse banco de homologação**, com backup SHA256 e teste de rollback. Não usar backup do banco real em ambiente de teste sem sanitização de dados.

Quando estiver pronto, realizar os testes A/B, autorizações reais de leitura/escrita, health/smoke e somente então discutir rollout na operação principal.

## Continuidade

B1.2c-2b.2b.2b.2b.1: caixa de entrada interna por tenant, sem provedores externos. Próximas etapas: mecanismo de envio interno acionado de modo controlado, autenticação/tokens multiempresa, inbox no frontend, automações e workers de canais externos, arquivos/financeiro/chat e homologação B1.3.

**Se estiver usando o workflow de deploy existente, não clique em “Run workflow” na branch WIP.** Execute a preparação de código em homologação pela sessão SSH autorizada e confira o SHA. Nenhuma execução SSH foi realizada pelo ChatGPT nesta conversa.
