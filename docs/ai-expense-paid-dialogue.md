# TaskEsc AI — continuidade na baixa de despesas via WhatsApp (15/10/2026)

## Defeito demonstrado
Após o TaskEsc confirmar a criação da despesa #48 (Uber, R$ 22,60), o usuário pediu “pode colocar ela como pago”; o agente perdeu o ID recém-confirmado, pediu ID e data, depois esqueceu a ação quando recebeu apenas “48” e só voltou a pedir a data após outra pergunta completa.

## Comportamento corrigido (privado ADM, finance.manage)
- Depois de **uma confirmação de criação de despesa efetivamente enviada**, “pode colocar ela como pago” usa o ID da despesa imediatamente anterior, e **pergunta somente a data** do pagamento.
- Se o pedido indicar explicitamente “despesa #48”, não pede ID de novo; pede só a data.
- Se não houver referência validada, pede **o ID**. Em seguida, ao receber apenas “48”, guarda o ID no texto da próxima pergunta, de modo que a resposta “hoje”, “ontem”, “YYYY-MM-DD” ou “DD/MM/AAAA” possa completar a informação.
- Com ID e data válidos, prepara a operação **mark_expense_paid** usando o mecanismo existente de consentimento duplo. A despesa **não** é paga antes do próximo `CONFIRMAR <código>`, vinculado ao ADM e ao mesmo privado.
- O backend `AiWhatsAppAdminActions::prepare` verifica a despesa no banco (existência, pendência e valor); confirmação revalida tudo em transação. Não infere automaticamente a data do pagamento nem reusa registros financeiros desatualizados.
- Esse fluxo é resolvido por PHP, sem consumo Gemini/Groq. Não altera comportamento do modelo para outros pedidos.

## Segurança e contexto
A identificação vem somente da **última mensagem recebida e enviada** na mesma sessão, chat e remetente, com usuário autenticado igual e recebimento nos últimos 10 minutos. Só aceita a confirmação de criação padrão efetivamente processada pelo TaskEsc, ou pergunta de esclarecimento gerada deterministicamente. Não atravessa grupos, outros usuários ou mensagens interpostas.

A própria pergunta ao usuário carrega o ID do contexto, por isso **não cria tabela nem salva memória secreta**, e usa o inbox já existente. Se o assunto muda, a referência anterior não pode ser reaproveitada.

## Testes
Teste de ponta a ponta com despesa sintética (R$ 22,60): simula criação já confirmada, pronome, repetição de ID, data e nova confirmação; garante que status continua pendente antes do `CONFIRMAR` e passa a pago depois; testa isolamento por chat/usuário/grupo, ausência de chamadas LLM, ação ambígua rejeitada e idempotência. CI em PHP, MySQL 8 e MariaDB 10.11 obrigatória antes do deploy.

**Sem migração, sem alterar outras transações ou dados financeiros em produção.**
