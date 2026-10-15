# Inteligência Contextual TaskEsc AI — Etapa 2/3

## Resumos combinados entre módulos
Nova ferramenta read-only `cross_module_report` para administrador em conversa **privada**, capaz de compor em uma única resposta (seleção explícita de pelo menos dois módulos):
- Tarefas (prazos e situação) obtidas via `TaskEscTaskApi::list` com ACL do usuário atual
- Projetos **ligados às tarefas visíveis no período** (não representa catálogo completo de projetos)
- Compromissos nas agendas selecionadas obtidos por `AiWhatsAppAgendaTools::run('agenda_list')`
- Financeiro corporativo, opcional e explicitamente solicitado: resumo, despesas pendentes ou contas a receber, usando `TaskEscFinanceReadApi`

O relatório **não atribui custos globais a projetos** e não inventa vínculos. Tarefas usam o prazo para filtrar período; agendas usam início/fim; financeiro usa datas dos lançamentos. A consulta de tarefas é limitada a 100 resultados com sinalização de amostra parcial; as listas no WhatsApp são limitadas e seus totais seguem os retornos reais dos gateways.

## Frases suportadas
- "Task, faz um resumo de tarefas, projetos e agenda de hoje"
- "Task, faz um resumo de tarefas, agenda e financeiro deste mês"
- Após um relatório combinado no privado: "e amanhã?", "e o financeiro?", "e as despesas pendentes?", "e a agenda?", "e os projetos?", "e as do Saulo?"

Frases completas que correspondem à gramática conservadora são interpretadas localmente em PHP, sem solicitar Gemini/Groq. Pedidos não reconhecidos continuam disponíveis para planejamento com ferramentas, com permissões verificadas pelo backend.

## Segurança
- Somente ADM autenticado em privado; acesso ao financeiro exige `finance.read`, pedido financeiro explícito na mensagem ou continuação anterior validada pelo servidor (mesmo usuário/chat).
- Última intenção de leitura é reaproveitada com TTL de 10 minutos; não se guardam valores, mensagens ou resultados, e o dado sempre é lido novamente na operação.
- Sem operações de criação/edição/exclusão, sem chamadas HTTP externas novas, sem alterações de schema, assinatura ou variáveis de ambiente.
- As ferramentas continuam revalidadas dentro da transação do worker; confirmações administrativas seguem fluxo próprio.
- O agrupamento de projetos contém apenas IDs encontrados em tarefas com permissão de leitura. Relatórios mistos são bloqueados em grupos.

## Testes
- PHP/MySQL 8/MariaDB 10.11: roteamento local sem financeiro implícito, dados combinados por gateway atual, período local, isolamento por usuário/chat/grupo, negação financeira, parâmetros inválidos e reconsulta de continuação confiável.
- Backup obrigatório, saúde do worker, migrações idempotentes e smoke tests continuam no deploy.

## Progresso
2/3. Próxima etapa: respostas conversacionais com esclarecimento de filtros e síntese mais natural, mantendo economia de tokens e restrições.
