# TaskEsc AI — Gemini primário, Groq secundário (schema 29)

## Arquitetura

WhatsApp WPPConnect → Webhook/Inbox deduplicada → checagem de usuário e grupo → Gemini `gemini-3.8-flash` (interpretador de intenção) → gateway `TaskEscTaskApi` (a mesma camada de regras usada pelos endpoints REST `/api/v1/tasks`) → `Demands` (autorização, auditoria, transação, Outbox) → resposta WhatsApp. Se houver indisponibilidade **do provedor** Gemini e chave secundária configurada, o interpretador tenta Groq. O fallback não executa ações por conta própria; a escrita de dados segue única e idempotente.

A API do TaskEsc é consumida por **gateway interno** na mesma aplicação PHP — sem chamada HTTP a localhost e sem token Bearer universal. Os endpoints REST públicos permanecem disponíveis para integrações externas com tokens por usuário. Não permitir que a IA gere SQL, altere o remetente, eleve permissões, acesse dados financeiros ou escolha endpoints arbitrários.

O catálogo de operações atual é `list_tasks`, `create_task`, `edit_task` (título, briefing, prioridade e prazo) e `move_task`, sempre sujeito ao papel e vínculos do usuário. Não executar vários comandos por mensagem. Não alterar regras de menção em grupos, integrantes ADM e verificação PN/LID. Transações e resposta at-most-once preservadas.

## Configuração segura

1. Revogue **qualquer chave Groq que já tenha sido enviada em mensagens** e gere outra em https://console.groq.com/keys.
2. Gere uma chave da API Gemini em https://aistudio.google.com/apikey. Plano Google One Pro não substitui chave/cota de API.
3. Entre pelo **terminal SSH da VPS** no diretório do projeto TaskEsc e rode `php bin/configure-taskesc-ai.php`. O script pergunta a chave Gemini e uma nova chave Groq opcional, com entrada oculta e sem passá-las por argumentos. Altera somente `config/config.php` privado e mantém outras configurações existentes.
4. Reinicie `sudo systemctl restart taskesc-ai-whatsapp-worker.service`.
5. Confira `php bin/ai-whatsapp-worker.php --health` (`enabled=true`, `schema=29`, `status=ok`). O health do worker valida apenas configuração e estrutura, não comunicação com a API externa.
6. Teste de ponta a ponta a partir do WhatsApp autorizado: **“Liste minhas 5 últimas tasks”**. Para criação/edição, use uma task de teste e confirme alterações no TaskEsc.

## Configuração de referência (NUNCA publique chaves)

```php
'ai_whatsapp' => [
 'enabled' => false,
 'provider' => 'gemini',
 'fallback_provider' => 'groq',
 'gemini_api_key' => '',
 'gemini_model' => 'gemini-3.8-flash',
 'groq_api_key' => '',
 'groq_model' => 'llama-3.3-70b-versatile',
],
```

A funcionalidade permanece desativada se `enabled` não estiver `true` ou não houver chave de algum provedor. Se Gemini estiver configurado, será o primeiro. Groq é utilizado somente se Gemini estiver indisponível ou ausente e estiver configurado.

## Testes e implantação

- `php tests/ai-whatsapp-providers.php` valida contrato Gemini, function calling e bloqueio de respostas maliciosas, sem qualquer API externa.
- `php tests/ai-whatsapp-tools.php` valida transações e permissões sobre MySQL e MariaDB na CI.
- Deploy por GitHub Actions com backup obrigatório antes de migrações. Nenhuma alteração no schema 29 nesta atualização.
- Nunca salvar segredo em GitHub, histórico do shell, issue, chat, commit, logs ou ZIP.

> O deploy instala **código**, não ativa fornecedores automaticamente. O administrador precisa instalar as chaves **novas** na VPS e testar uma requisição real.
