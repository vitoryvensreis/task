# TaskEsc AI — seleção de responsável na criação via WhatsApp

Correção do teste real de 14/10/2026: uma mensagem com cliente, categoria, título e briefing, mas **sem responsável**, recebia `Selecione um responsável para a task` quando a categoria não tinha `default_assigned_to`.

## Nova regra

- Responsável especificado por ID: a rotina `Demands::responsible` continua validando usuário ativo e vínculo do staff com o cliente.
- `assign_to_me=true`: interpreta **eu mesmo / para mim / nome do remetente**; seleciona **somente o usuário autenticado do WhatsApp**, se o papel for `admin` ou `staff`; não aceita `assigned_to` simultaneamente.
- Sem opção explícita: respeita o responsável padrão da categoria quando existe. Caso não exista, usa o remetente, **apenas se for admin/staff**. Para `client`, não há atribuição automática a cliente, pois responsáveis devem ser da equipe.
- Toda criação continua via `TaskEscTaskApi::create` / `Demands::save` e suas validações normais; valor financeiro permanece zero; não muda schema.
- O contexto enviado ao modelo inclui somente `current_user` (ID, nome e papel) quando se trata de alguém da equipe. Nunca encaminhar tokens, hashes ou credenciais.

## Limitações

O worker atual interpreta cada mensagem isoladamente. Quando o bot solicitar detalhes adicionais (por exemplo, cliente ou categoria), envie uma solicitação completa; respostas curtas como "eu mesmo" ainda não completam automaticamente um pedido anterior. Histórico conversacional é uma melhoria futura que requer limites de sessão, revisão de privacidade e testes contra execução duplicada.

## Validação

`php tests/ai-whatsapp-tools.php` no GitHub Actions, com MySQL 8 e MariaDB 10.11. Casos: sem padrão, padrão existente, atribuição explícita a si, entradas inválidas, cliente impedido de se tornar responsável e valor zero. A CI obrigatória precede o merge e o deploy com backup.

**Teste real seguro:** antes de reenviar uma criação, consulte se a task com o mesmo título já existe, para não duplicar solicitações feitas enquanto a IA estava aguardando.
