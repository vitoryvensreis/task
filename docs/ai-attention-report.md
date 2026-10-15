# TaskEsc AI — análise real de demandas por projeto (15/10/2026)

## Contexto
Depois de corrigir as requisições HTTP 400, o assistente voltou a responder pelo WhatsApp, porém ao pedido “Task, analise minhas demandas e me diga quais projetos precisam da minha atenção, considerando prazos, prioridades e andamento. Não altere nada.”, devolveu apenas a lista de 30 tarefas. A causa é que `list_tasks` sempre formatava uma listagem simples, sem indicadores de atenção.

## Nova ferramenta de leitura
`attention_report` lê dados **atuais** de tarefas pela API interna `TaskEscTaskApi::list`, com o usuário autenticado e suas permissões normais. Não pode criar, mover, editar ou apagar tarefas. O uso é permitido a usuários com `tasks.read`, e os grupos continuam sujeitos às restrições de ADM existentes no worker.

- Examina até 2.000 tarefas visíveis com paginação de 100; avisa se a análise é parcial.
- Exclui tarefas em etapas concluídas, canceladas e arquivadas das prioridades de atenção.
- Considera atrasos, prazo nos próximos 7 dias e prioridade alta/urgente. Agrupa pelo projeto real quando vinculado e pelo cliente quando não há projeto, sem inventar dados.
- Exibe até 5 grupos com mais alertas e 2 tarefas representativas em cada; IDs, títulos, status, prazo e prioridade baseados em dados efetivos.
- Não usa saldo financeiro, notas internas, dados de outros usuários ou IA para decidir urgência.
- As frases exatas de solicitação de atenção, com aviso de não alterar dados, passam pelo atalho local sem gastar nenhuma chamada Gemini/Groq; o modelo também tem a ferramenta disponível para outras formulações compatíveis.
- `AiWhatsAppMemory` pode guardar só a interpretação para futuras perguntas; **nunca** a análise ou um resultado antigo.

## Validação
- Testes determinísticos com tarefas atrasadas, próximas, concluídas e sem projeto.
- Teste completo em MySQL/MariaDB usando o catálogo real autorizado e envio de WhatsApp com `planner` que falha se for invocado, garantindo zero créditos na frase exata.
- Nada no schema, sem alterações de dados de produção, configurações ou flags.
- Após deploy, confirmar a resposta em linguagem natural e as métricas corretas no WhatsApp. A ordenação e os grupos mostrados dependem dos dados disponíveis no momento.

Atenção: o relatório é uma triagem objetiva por indicadores da base, não uma interpretação profunda do briefing nem prova de bloqueios não registrados.
