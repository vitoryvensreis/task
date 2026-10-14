# TaskEsc AI — controle financeiro: consultas seguras (schema 29)

## Auditoria de cobertura

A API REST v1 já disponibilizava `GET /finance/summary` e `GET /finance/invoices` para administradores. Ambas consomem métodos internos que sincronizam automaticamente faturas/despesas recorrentes ao consultar. A API REST **não** disponibilizava operações de escrita financeira; as rotinas de faturamento, pagamento, estorno, despesa, cancelamento e exclusão estavam reservadas ao painel e ao backend.

## Implementação

As ferramentas de IA `finance_summary`, `finance_receivables` e `finance_expenses` aparecem no catálogo somente para **administradores autenticados pelo WhatsApp em conversa PRIVADA**. Em grupos, não são expostas mesmo quando o ADM menciona o bot.

O gateway `TaskEscFinanceReadApi` é compartilhado com a API REST v1 e realiza **somente SELECTs**, sem gerar mensalidades nem executar `syncCommercialInvoices`, `syncExpenses` ou registrar pagamentos. Revalidação de identidade/autorização ocorre antes de cada chamada e antes de enviar resposta, conforme o worker existente. Não são concedidos privilégios adicionais ao modelo de linguagem.

Endpoints acrescentados:
- `GET /api/v1/finance/receivables?scope=month|all&from=YYYY-MM-DD&to=YYYY-MM-DD&limit=10`
- `GET /api/v1/finance/expenses?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=10`

Ferramentas de IA:
- `finance_summary`: faturamento do período por vencimento, recebido por data efetiva de pagamento, custos lançados no período, custos pagos no período, resultado de caixa, saldo a receber e vencido no total registrado (inclusive de outros meses).
- `finance_receivables`: saldo após pagamentos/descontos/estornos em cobranças ativas registradas, mês atual por padrão; `scope=all` consulta todos os meses. Até 10 linhas detalhadas; totais abrangem todas as cobranças que passaram nos filtros.
- `finance_expenses`: despesas operacionais e custos vinculados a tarefas já registrados no período, com status de pagamento.

Valores retornados pelo gateway usam **centavos**. Respostas WhatsApp convertem para pt-BR. Consulta reconhecida `me fala o que ainda tenho a receber esse mês` e variantes exatas é tratada localmente, sem consumir Gemini/Groq; frases ambíguas seguem pelo modelo.

**Importante:** valores refletem somente lançamentos já registrados no banco. Se ainda existirem mensalidades ou despesas recorrentes não sincronizadas, elas não aparecem até a sincronização normal da aplicação. Consultas não criam lançamentos.

## Escrita financeira

Ainda **não autorizada para a IA**. Criar/cancelar faturas, registrar pagamento/desconto/estorno, editar custos e excluir registros exigem um passo específico com controle de confirmação por operação, idempotência, limites de valor, auditoria, permissão ADM e bloqueio de ações em grupo. Não são adequadas a uma função `edit_task` genérica.

## Testes e implantação

- MySQL 8 e MariaDB 10.11: soma de saldos com pagamentos e descontos, exclusão de canceladas, custos pagos e pendentes, filtragem por datas, não gerar cobranças automáticas, recusar parâmetros inválidos e bloquear usuários sem privilégio.
- REST: mesmos valores dos métodos usados pela IA; métodos POST continuam bloqueados.
- WhatsApp: somente conversa privada do ADM, mensagem mensal sem provedor LLM, grupos sem ferramentas financeiras.
- CI verde, backup completo e health checks antes de concluir deploy. Schema 29 inalterado.
