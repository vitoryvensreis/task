# Operação comercial TaskEsc — 28/10/2026

## Modelo inicial
A comercialização é **assistida**. Os workspaces atuais, inclusive seus proprietários ADM, são preservados. O formulário público em `/planos.php` envia uma solicitação à fila de e-mail dos ADM Masters verificados. Não cria acesso, usuário nem assinatura.

## Contratação
1. Publicar um plano de acesso no ADM Master (preço e ciclo).
2. O interessado seleciona o plano e preenche nome, e-mail e telefone em `/planos.php`.
3. O ADM Master confirma a identidade, o proprietário ADM e o workspace correspondente. A criação de um terceiro workspace continua fora deste fluxo e **não foi liberada por este pacote**.
4. Atribuir o plano ao workspace e, em Integrações, configurar credenciais e token de webhook do Asaas de produção.
5. No detalhe da empresa no ADM Master, selecionar **Gerar assinatura** e confirmar `COBRAR`. O primeiro vencimento ocorre em 2 dias. Nenhuma cobrança acontece pelo simples envio do formulário.
6. O evento de pagamento é recebido em `/asaas-webhook.php?environment=production`. O worker de cron reconcilia uma amostra rotativa a cada 4 horas para corrigir eventos eventualmente perdidos.

## Estados de acesso
- Apenas **assinaturas de produção** associadas ao plano pago atribuído ao workspace participam da restrição. Os workspaces existentes sem assinatura de produção e o plano `legacy-unlimited` continuam intactos.
- Pagamentos em dia: uso normal.
- Aguardando pagamento: uso normal até vencer a carência de 7 dias.
- Inadimplência após carência, disputa de pagamento ou recorrência pausada: login, leitura e exportação seguem disponíveis; escrita em painel, API v1 e upload por chunks é bloqueada; automações do workspace não são executadas.
- O administrador vê a situação e, quando fornecido pelo Asaas, link HTTPS da cobrança em Configurações → Meu Google Drive.
- Pausar/retomar recorrência no ADM Master exige confirmação literal. Retomar define próximo vencimento para 2 dias. **Não há exclusão remota irreversível pela interface.**
- Cancelamento remoto informado por webhook desativa edição; não apaga dados.

## Operação necessária
- Confirmar SMTP de saída e monitorar `platform_mail_queue` (novos leads e recuperação de senha).
- Conferir o token secreto de webhook em ambos os ambientes e a conta de produção Asaas aprovada.
- Antes de cobrar, validar com o próprio Asaas o valor e vencimento. Alterações de preço ou ciclo em planos com assinatura vigente são bloqueadas para evitar divergência.
- A área Master gerencia assinaturas; o cliente não recebe API key nem credenciais Asaas.
- Solicitações de recuperar senha são genéricas para evitar identificação de usuários; links duram 30 minutos, expiram após uso, revogam tokens API e sessões anteriores; 2FA permanece obrigatório.
- Disponibilizar revisão jurídica/contábil da oferta, tributos, regras de cancelamento e atendimento ao cliente antes da comercialização pública.

## Não incluído
Checkout self-service, criação automática de terceiros workspaces, exclusão definitiva da recorrência, reembolso e migração de arquivos entre Google Drives. Não anunciar essas funções como disponíveis.
