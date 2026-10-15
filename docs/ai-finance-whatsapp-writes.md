# TaskEsc AI — Despesas e entradas financeiras pelo WhatsApp

## Sintoma e causa
O assistente afirma que não tem ferramenta para criar despesas/entradas. A ferramenta `create_expense` já existia, mas estava condicionada ao parâmetro geral `admin_control_enabled=false`. Havia apenas `record_payment` para uma cobrança preexistente; não existia um comando único para uma entrada recebida com cliente e descrição.

## Alterações
- **Controle financeiro separado:** `financial_write_enabled` (padrão **true**, pode ser desativado explicitamente na configuração privada da VPS). Exclusivo para perfil `admin`, `finance.manage` e conversa privada; todas as operações exigem **código de confirmação de outra mensagem**. `admin_control_enabled=false` continua desativando controle administrativo amplo como criação de projetos.
- **Despesa simples:** mensagem como `Task, adiciona uma despesa para mim de 22,60 Uber` é interpretada em PHP com valor exato em centavos. A prévia diz que será criada **pendente**, na data local atual, na categoria existente `Outros`. Isso não implica que o Uber foi pago; para marcar pago, há outra operação e confirmação. Dados informados explicitamente pelo usuário têm prioridade.
- **Entrada recebida:** nova ferramenta `register_receipt` exige **cliente real (ID), descrição, valor, data e meio**. A confirmação cria `invoices` + `payments` usando `Finance::invoiceCreate` e `Finance::payment` **na mesma transação**; integra aos relatórios e às contas a receber sem um livro-caixa paralelo. Não representa transferência bancária.
- Um pedido genérico de entrada sem cliente e meio de pagamento devolve uma pergunta útil pedindo dados faltantes em vez de dizer que não existe ferramenta. Caso queira lançar entrada sem cliente (aporte pessoal, juros bancários), será necessário modelo de dados próprio; este fluxo não inventa cliente.
- A aprovação utiliza `CONFIRMAR CODIGO`, com validade de 10 minutos e execução idempotente, e nunca pode ser acionada por grupos, outras pessoas, ou sem permissão. Após preparar, **nenhum registro financeiro é escrito até a confirmação**.

## Testes
- Validação isolada com controle geral desativado e apenas seis operações financeiras autorizadas.
- Teste MySQL/MariaDB de criação de despesa via frase natural sem LLM e sem duplicação; despesa permanece pendente.
- Teste MySQL/MariaDB de entrada recebida: nenhum efeito na proposta, inserção de uma cobrança e um pagamento na confirmação, valores e cliente corretos.
- Testes de catálogo negado para staff, cliente e grupos; CSRF e endpoint HTTP não foram alterados.
- Sem migrações, mudanças no schema, SaaS ou alterações em chaves/segredos.

## Limitação
A funcionalidade suporta **recebimento real de um cliente**. "Entrada" abstrata sem vínculo de cliente, aportes de sócio e transferências internas não são lançados como receitas de clientes para evitar distorção contábil.
