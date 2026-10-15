# TaskEsc AI — Inteligência Contextual, Passo 3/3 (15/10/2026)

## Resultado esperado
Depois da memória contextual privada (Passo 1) e do relatório integrado entre módulos (Passo 2), este passo torna a experiência mais útil: esclarece pedidos realmente ambíguos, mostra resultados de leitura com clareza, evita chamadas de IA desnecessárias e mede a evolução de forma anônima.

## Implementação
- Nova classe `AiWhatsAppConversationUX` como camada de apresentação/clarificação. Em perguntas estritas e ambíguas ("mostra os pendentes", "e amanhã?" sem contexto, "esse mês" sem pergunta anterior) responde somente com a **única desambiguação necessária**. Não inicia ferramentas ou modifica dados. A resposta sobre capacidades é baseada no catálogo realmente permitido para o usuário, não em promessas amplas.
- Continuidade contextual do passo 1 continua com precedência sobre esclarecimentos genéricos: quando "e amanhã?" tem um contexto de agenda válido, o sistema executa a leitura de agenda, não pede explicação. Na ausência de contexto, pode pedir o escopo.
- `list_tasks` agora recebe resposta legível com quantidade total, título, etapa e prazo, e aviso de truncamento caso haja mais itens. Os dados vêm da ferramenta/TaskEsc atual, não de cache de resultados; nenhuma conclusão sobre vencimento é inventada a partir de `due_at`.
- Instruções de planejamento Gemini e Groq ajustadas: uma pergunta por campo essencial faltante, não repetir pergunta quando já for suficiente; consultar ferramentas autorizadas em leituras claras, sem inventar contextos, números ou autorizações.
- Métricas `type=conversation` com categorias fechadas (`guided_help`, `local_clarification`, `context_followup`), somente contadores, nunca mensagem, usuário, JID, nome, IDs de tarefas, cliente ou resultado. Painel de monitoramento mostra essas categorias no filtro "Todos", sem atribuir uma conversa local a um provedor. Contadores só cobrem eventos após o deploy e não representam economia em reais.

## Segurança
- Nenhuma alteração de schema 29, tabelas SaaS, tokens, configuração dos provedores ou permissões da plataforma.
- Confirmação de escritas, regras de ADM, bloqueio de finanças em grupos, ACL e isolamento por conversa continuam no worker.
- Roteamento local restrito a frases exatas: comandos de escrita misturados com ambiguidades não entram no atalho e seguem a autorização normal.
- Logs anonimizados em `storage/logs/ai-usage-YYYY-MM-DD.jsonl`; falha de telemetria não interrompe operações.
- A camada não simula conversa humana com dados antigos: respostas usam estado atual do TaskEsc.

## Testes e deploy
- Testes de FastPath/UX para menus limitados a permissões, ambiguidades, restrições em grupos, instruções inseguras, listagem com prazo e resposta parcial.
- Testes de integração do WhatsApp garantindo retorno de texto sem chamada LLM e sem alteração de tarefas.
- Testes de métricas com fixtures históricas: contadores corretos, categorias desconhecidas ignoradas, nenhuma atribuição a Gemini/Groq e painel preparado para períodos sem dados.
- GitHub Actions: testes Node e PHP, MySQL 8, MariaDB 10.11; merge somente com CI verde e publicação com backup e health checks.

**Limite deliberado:** isto não torna qualquer expressão natural determinística; solicitações fora dos padrões locais ainda passam pelo Gemini/Groq, sob os mesmos limites de quota. Não há memória geral de textos de conversa, nem execução múltipla irrestrita.
