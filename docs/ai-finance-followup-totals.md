# TaskEsc AI — Correção de contexto financeiro (15/10/2026)

## Problemas observados no WhatsApp
- O ADM pediu o total de **despesas/custos pendentes**; ao responder somente “esse mês”, o agente perdeu a pergunta anterior e voltou a pedir esclarecimento.
- A ferramenta `finance_expenses` retornava no máximo 10 despesas misturando pagas/pendentes, sem calcular o total solicitado.

## Correções
1. **Agregação financeira no MySQL**, usando as fontes existentes `expenses` e `costs`, com soma e contagem de `pending`, `paid` e `all` em todos os registros do período. O `limit` só restringe os detalhes exibidos; nunca o total. O WhatsApp mostra o **total primeiro**, depois quantidade e detalhes, indicando se houver mais registros.
2. **Filtro de pagamento** no catálogo da ferramenta (`payment_status`: `all|pending|paid`). Gemini/Groq recebem instruções para selecionar `pending` em pedidos de despesas pendentes.
3. **Contexto mínimo e seguro**: para o mesmo ADM autenticado e conversa privada, a frase “esse mês” completa somente a pergunta imediatamente anterior sobre o total de despesas pendentes, desde que o Task tenha pedido explicitamente o período, respondido com sucesso e tudo tenha ocorrido nos últimos 10 minutos. Usa `ai_whatsapp_inbox` existente; não cria memória persistente adicional, não encaminha o histórico a modelos e não reutiliza saldos anteriores.
4. Um pedido completo “Task, me manda o total das despesas/custos pendentes esse mês” e os dois turnos separados funcionam sem consumir Gemini/Groq.
5. **Somente leitura**. Todas as consultas continuam com `finance.read` + ADM; a resolução contextual não é usada para grupos, clientes, outros usuários ou outras conversas. Comandos extras ou ambíguos não entram no atalho.

### Exemplo esperado
> Total das despesas/custos pendentes (01/10/2026 a 31/10/2026): R$ ... | N lançamentos
> Itens (até 10; total inclui todos). Consulta somente leitura.

As datas no relatório são `spent_on`, não necessariamente vencimentos. Portanto, despesas `pending` não são automaticamente classificadas como `overdue`.

## Testes
- MySQL 8 e MariaDB 10.11 com lançamentos pendentes e pagos, inclusive valor total maior que o limite de 2 itens exibidos.
- Fluxo completo pelo inbox: pergunta → esclarecimento de período → “esse mês” → resultado real sem LLM.
- Isolamento por usuário/conversa e bloqueio do atalho quando misturado a comando de escrita.
- Sem migrações e sem alterações de dados em produção. Schema 29 preservado.

## Melhorias posteriores (se necessário)
- Contexto estruturado geral para outros tópicos (projetos, agenda, tarefas e finanças) com TTL, isolamento por conversa, confirmações preservadas e suporte a múltiplos filtros.
- Cobertura adicional de perguntas como “só os vencidos” dependendo de datas explícitas de vencimento e disponibilidade desses campos no schema.
