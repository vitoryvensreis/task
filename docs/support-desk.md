# Atendimento privado entre workspace e ADM Master

O chat administrativo entre usuários foi desabilitado em produção, inclusive no backend. O widget flutuante **Suporte TaskEsc** está disponível para administradores de uma empresa e para a identidade Master verificada, sem necessidade de entrar na sessão de suporte da empresa.

- Cada administrador possui conversa **individual** com o ADM Master; não é possível enviar para outro administrador nem listar ou visualizar as mensagens alheias.
- O Master vê os administradores cadastrados, escolhe a conversa e responde mantendo sua identidade real.
- Aceita texto e uma imagem por mensagem (JPG, PNG, WEBP ou GIF), até 5 MiB, com MIME comprovado e gravação em pasta privada `storage/support` fora de `public`.
- As imagens somente são disponibilizadas em `support-image.php` para o Master verificado e o administrador da conversa; não são arquivos públicos. Ações protegidas por login, verificação de vínculo e CSRF; envio com nonce idempotente.
- Conversa atualiza a cada 10 segundos **somente enquanto o painel estiver aberto**. Não foram incluídos workers ou serviços novos.
- O banco recebe a tabela **workspace_support_messages**, por `database/migrations/032_support_desk.sql`. A rotina idempotente `bin/migrate-support-desk.php` só roda após backup validado (SHA256) na etapa de deploy, preservando o schema operacional 30 e sem criar outro workspace.

O chat é um canal de atendimento. A entrada do Master no workspace para editar configurações ou tarefas permanece separada e exige a sessão temporária auditada de até 2h.

Os testes SQL são os mesmos de MySQL 8 e MariaDB 10.11 já presentes no repositório, ampliados somente com a verificação objetiva da separação das conversas no banco descartável.
