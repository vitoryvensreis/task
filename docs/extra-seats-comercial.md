# Vagas extras e planos TaskEsc

## Regras comerciais
- O identificador interno do plano é criado automaticamente no servidor; alterações no nome não trocam o identificador.
- Valor base e valor por vaga extra são armazenados em centavos. Cada plano define preço por usuário adicional **na mesma periodicidade do plano**.
- Clientes cadastrados não consomem vagas de equipe.
- Proprietário ADM não consome vaga (regra já existente). Outros ADMs e colaboradores usam o limite base + vagas contratadas.
- Com equipe ilimitada não há cobrança por vaga extra.
- Planos existentes e os dois workspaces atuais começam com **zero vagas extras** por padrão; nenhuma assinatura existente é alterada automaticamente.

## Fórmula
`valor recorrente = valor base + (preço da vaga × quantidade de vagas extras)`.
Ex.: Solo R$ 19,90, vaga adicional R$ 9,90 e duas vagas adicionais = R$ 39,70 / mês.

## Aquisição e cobrança
- O ADM Master pode configurar as vagas no painel da empresa antes da assinatura; elas entram no valor do primeiro ciclo.
- Um workspace com assinatura de produção pode adquirir vagas no painel de configurações, exclusivamente pelo proprietário ADM.
- O ADM Master também pode ajustar o total contratado.
- Alterações existentes exigem confirmação `ATUALIZAR`, verificam valor local vs Asaas, e enviam `PUT /v3/subscriptions/{id}` com `value` atualizado e `updatePendingPayments=false`.
- O Asaas muda **futuras** cobranças. Cobranças já emitidas permanecem iguais por padrão; não há cobrança imediata de taxa proporcional.
- Redução só é aceita se houver capacidade para a equipe ativa. Erros ou diferenças de valor bloqueiam a alteração e pedem conciliação manual.
- Pedidos na página pública são apenas leads com cotação. Não geram assinatura nem cartão cobrado automaticamente.

## Migração
`database/migrations/042_platform_extra_seats.sql` documenta os campos. O script `bin/migrate-platform-extra-seats.php` aplica as colunas aditivas e idempotentes após backup verificado no deploy. O `database/schema.sql` permanece no formato bootstrap legado e não inclui tabelas da plataforma por decisão arquitetural.
