# TaskEsc — Planos de acesso (etapa de preparação)

Esta entrega cria **planos de permissão**, não cobrança automática. O schema operacional permanece em **30** e o SaaS removido **não é restaurado**. O sistema continua com **um único workspace**, preservando o proprietário e todos os dados existentes.

## Como usar

Entrar como ADM Master, abrir **Planos de acesso**. Criar/editar planos com:
- Gerenciamento de tarefas;
- Automações;
- Agenda de contatos;
- Limite de usuários adicionais à conta proprietária, ou equipe ilimitada;
- Valor em reais, mensalidade/anuidade, status e visibilidade pública.

Atribuir o plano ao workspace existente. A atualização atua no backend: requisições aos módulos não incluídos são negadas mesmo que o usuário tente URLs diretas. O cadastro e a reativação de usuários adicionais respeitam o limite do plano dentro da mesma transação do banco. Não existe checkout, assinatura paga ou criação autônoma de workspace.

O catálogo público aparece em `/planos.php`, exibindo **somente** planos ativos e marcados como públicos. Planos privados só aparecem no ADM Master. A visibilidade pública não autoriza contratação automática.

## Plano legado

No primeiro deploy, o plano **Legado ilimitado** é criado em caráter privado, com os três módulos disponíveis e equipe ilimitada, e atribuído ao workspace Escotilha Produtora. Essa configuração preserva o funcionamento anterior e pode ser alterada pelo Master para outros planos, após checar as consequências nos usuários cadastrados.

## Banco e deploy

Arquivo `database/migrations/031_platform_access_plans.sql` com:
- `platform_access_plans`;
- `workspace_access_plans`;
- `platform_access_log`.

`bin/migrate-platform-access.php --apply --backup-manifest=...` exige o snapshot de banco pré-deploy com checksum SHA-256 validado; é executado no workflow após o backfill do workspace único. A migração é aditiva e idempotente. Nenhum dado anterior é removido.

## Limitações intencionais

- Não ativar outro workspace. Ainda existem queries/integrações globais a isolar.
- Não existe cobrança, trial, alteração automática de planos ou autoatendimento de contratação.
- O painel de permissões personalizadas de cargos e o chat de suporte com anexos são entregas separadas; não habilitar UI fictícia antes do backend correspondente.
