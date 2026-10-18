# TaskEsc — Bloco 10: homologação e ativação gradual

## Estado inicial (18/10/2026)

Os Blocos 1 a 9 estão integrados. O PR #127 (Bloco 9) foi publicado com sucesso no deploy #614, com backup pré-deploy, CI e health check. O Master usa ativação por convite (não existe senha padrão) e só acessa a empresa durante sessão de suporte auditada e temporária.

**A liberação de um segundo workspace permanece proibida.** A fundação do schema operacional 30 foi preparada para **um único workspace**, Escotilha Produtora. O método `WorkspaceContext::forUser()` e o suporte Master ainda verificam essa premissa; há defaults de `workspace_id` associados ao tenant original e integrações/filas globais que exigem isolamento adicional. O SaaS anteriormente removido não deve ser restaurado.

## Primeira entrega do B10: preflight não destrutivo

Com o código publicado na VPS:

```bash
cd /caminho/real/do/taskesc
php bin/workspace-homologation.php
```

O relatório JSON contém a integridade estrutural da fundação, contagem de workspaces, estado de ativação do Master, sessões de suporte ativas, defaults de workspace ainda presentes e bloqueios para ativação multiempresa. Nenhum cliente, tarefa, calendário, token, senha ou dado financeiro é mostrado. Nenhuma alteração é feita no banco.

Para verificar também a atribuição das linhas históricas (mais consultas de leitura):

```bash
php bin/workspace-homologation.php --deep
```

Para usar como trava de homologação, `--require-multi-ready` termina com código de saída **3** enquanto a ativação multiempresa estiver bloqueada. O comando comum termina com 0 quando a operação **atual, com uma empresa**, tem baseline íntegro; isso **não significa** aprovação para ativar mais uma empresa.

## Critérios para concluir B10 e autorizar um segundo workspace

1. Confirmar o convite e a ativação do Master somente pelo titular do endereço definido, sem autenticação compartilhada. O proprietário atual permanece inalterado.
2. Provar que todo acesso a clientes, equipes, tasks, financeiro, uploads/downloads, relatórios, pesquisa, notificações, API/MCP e logs impõe workspace por autorização no servidor, incluindo JOINs, IDs e links diretos. Confirmar com dois workspaces **somente em ambiente de homologação** e testar acessos A→B/B→A.
3. Isolar mensagens, assinaturas Centrifugo, Outbox, WebSocket, WPPConnect, automações, cron/filas, Google Drive e outros workers. Processamentos globais não podem capturar dados de outra empresa.
4. Remover defaults legados de `workspace_id`, adequar índices/constraints e garantir preenchimento transacional explícito, por **migração aditiva e backup validado**, sem alterar ou recriar dados históricos.
5. Fazer snapshot restaurável separado da produção, validar smoke de autenticação, isolamento A/B e integridade relacional em staging; revisar rollback e impacto de deploy.
6. Depois de cumpridos os itens, obter autorização expressa para o primeiro tenant piloto. Implementar liberação gradual por flag exclusiva com padrão desligado, telemetria e reversão. Nunca criar empresa externa na produção como mero teste.

### Escopo intencional deste PR

Apenas relatório e documentação de homologação. **Sem** migração, schema novo, alteração de controles, segundo workspace, alteração de frontend ou tentativa de publicar ações em nome do Master. O sistema operacional atual e os dados da Escotilha seguem intactos.

Próxima etapa de implementação: isolamento dos pontos globais identificados no item 3 e revisão dos defaults do item 4, em partes pequenas. O relatório da primeira entrega NÃO deve ser interpretado como homologação completa.
