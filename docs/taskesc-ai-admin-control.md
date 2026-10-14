# TaskEsc AI — controle administrativo assistido (revisão de segurança)

## Situação

Esta entrega é uma **implementação local para revisão**, não prova deploy na VPS nem testes end-to-end com banco MySQL/MariaDB. O código usa o schema **29** existente; não executa nenhuma migração. A ativação administrativa é opt-in, desligada por padrão.

## Capacidades implementadas

Ações já existentes do agente: consulta/criação/edição/movimentação de tasks e leitura financeira de resumo, cobranças pendentes e despesas.

Novas consultas privadas de administrador: listagem de **clientes**, **projetos**, **usuários (nome/perfil/ativo, sem credenciais)** e **eventos futuros**.

Novas ações que passam por confirmação **fora do modelo**:

| Função | Efeito após confirmação |
|---|---|
| `create_invoice` | Cria cobrança avulsa via `Finance::invoiceCreate` |
| `record_payment` | Registra pagamento informado via `Finance::payment` (não transfere dinheiro) |
| `create_expense` | Cria despesa **pendente** via `Finance::expenseSave` |
| `mark_expense_paid` | Marca despesa existente como paga, via `Finance::expensePayment` |
| `cancel_invoice` | Cancela cobrança sem pagamentos, via `Finance::cancel` |
| `create_project` | Cria projeto via `Catalog::projectSave` |

## Como funciona a confirmação

1. Administrador habilitado solicita uma ação em **mensagem privada**.
2. Gemini/Groq apenas propõe uma das funções permitidas. O PHP valida os parâmetros, mostra **resumo, valor e impacto**, e emite um código aleatório de 12 caracteres hexadecimais.
3. **Nada é executado** nessa primeira mensagem.
4. O administrador tem **10 minutos** para enviar `CONFIRMAR ABCDEF123456` ou `CANCELAR ABCDEF123456` (substituindo pelo código recebido).
5. A segunda mensagem é interpretada **localmente em PHP**, sem consumir o modelo. O código está vinculado a usuário, chat privado e operação; só o hash HMAC fica persistido.
6. O lançamento e a marcação de confirmação concluída acontecem na **mesma transação MySQL**, com linha travada. O código não pode ser reutilizado.
7. A identidade e a autorização são revalidadas imediatamente antes de executar e novamente antes de responder. A trilha de auditoria registra proposta, execução e cancelamento.

A tabela `idempotency` existente armazena a confirmação; nenhum token de API fica exposto ao Gemini, ao Groq ou ao repositório. Após perda de conexão no envio do WhatsApp, verifique a ação no TaskEsc; não reenvie o mesmo comando sem consultar o estado.

## Segurança

- Somente ADM, conversa privada, identidade validada. **Grupos nunca recebem ferramentas administrativas nem confirmações.**
- Flags privadas: `ai_whatsapp.enabled=true` e `ai_whatsapp.admin_control_enabled=true`. A segunda fica **false** no `config/config.example.php` e só deve ser ativada depois de CI verde + teste numa base de homologação.
- Não há SQL livre, execução de PHP via IA, acesso a credenciais, manipulação de integrações secretas ou repasse automático de permissões. Os nomes das ferramentas são uma lista fechada.
- Valores financeiros sempre em **centavos inteiros**. O servidor recusa campos arbitrários, cobranças canceladas, valores fora do limite e mudanças de saldo entre proposta e confirmação.
- Criar/cancelar cobranças são registros internos; não ocorre transferência bancária nem disparo de Pix.
- `record_payment` presume que o pagamento **já aconteceu**. A IA não verifica o extrato nem a liquidação bancária.
- `create_expense` registra como pendente; `mark_expense_paid` também só altera o registro, não efetua pagamento.
- Não permita usar este fluxo para excluir clientes, alterar usuários/roles, tokens, configurações SMTP, conectar contas Google ou criar automações irrestritas. Essas operações requerem integrações e confirmação específicas.

## Testes necessários antes de publicar

- `php tests/ai-admin-approval.php`: testes puros (autorização por catálogo, sintaxe de código, recusa de comandos ambíguos, formatação). Executado localmente.
- `php tests/ai-whatsapp-tools.php`: testes com MySQL/MariaDB para proposta não executar, confirmação única, replay, cancelamento, recebimento e autorização. **Pendente de CI**; não executável na máquina local sem servidor de banco.
- Manual: simular falha de envio WhatsApp após registro financeiro, garantir ausência de repetição e verificar auditoria.
- Validar perfil ADM/staff/client e grupo, inclusive mensagens `@lid`.
- Habilitar `admin_control_enabled` somente após essa sequência.

## Expansões que ainda faltam para “controle total”

Administração de usuários/roles, cadastros completos de clientes, calendários (criar/editar/excluir com sincronização Google), contratos, categorias, Drive/uploads, configurações, relatórios exportáveis e automações. Cada um exige endpoints específicos, autorização, confirmação e testes de efeito colateral. **Este módulo não deve ser apresentado como controle total da plataforma.**