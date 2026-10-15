# TaskEsc AI — HTTP 400 do catálogo completo (15/10/2026)

## Evidência de produção

O PHP do TaskEsc registrou o HTTP 400 do Gemini com `curl_errno=0` ao usar o assistente com o catálogo completo, seguido por falha genérica do Groq. Consultas diretas ao banco e o probe com **uma** função artificial do Groq já tinham passado. Isso aponta para incompatibilidade de formato em uma requisição maior, mas o 400 genérico **não permite afirmar uma causa única sem nova validação**.

## Incompatibilidade reproduzida no PHP

O TaskEsc define ferramentas sem argumentos — `agenda_calendars`, `list_team`, `list_events`. A serialização padrão do PHP transforma `['properties'=>[]]` em JSON `"properties":[]` (lista). A API de funções espera um esquema de objeto ou permite omitir inteiramente os parâmetros quando a ferramenta não recebe nenhum argumento. Ver a documentação: https://ai.google.dev/api/generate-content e https://console.groq.com/docs/tool-use/local-tool-calling.

### Ajustes

- Gemini: para funções sem argumentos, omite `parameters` da FunctionDeclaration; para funções com argumentos preserva esquema tipado e validação do modelo.
- Groq: transforma `properties: []` em `properties: {}` **somente no corpo da requisição** (com `stdClass`), preservando o catálogo original de ferramentas e as ACLs. Também normaliza objetos vazios aninhados.
- Ambas APIs: HTTP 400/422 é `ai_provider_bad_request`; há apenas uma tentativa de cada provedor configurado e, se ambos rejeitarem, o WhatsApp envia explicação clara sem agendar novas tentativas caras. Falhas temporárias continuam sujeitas a retry.
- Groq: novos logs estruturados com `provider=groq http=<n> curl_errno=<n>`, sem prompt, credenciais, IDs, corpo ou texto do erro da API.

## Segurança e validação

O modelo não recebe credenciais ou SQL, não pode executar funções fora do catálogo autorizado; aprovações e operações sensíveis continuam protegidas por confirmação em mensagem separada. Nenhum banco, schema, chave, configuração privada ou tarefa será alterado por esta correção.

Validação de CI: `tests/ai-whatsapp-providers.php` para esquema sem argumentos e objetos aninhados, e `tests/ai-whatsapp-tools.php` no MySQL 8 e MariaDB 10.11 com catálogo REAL do ADM, mais HTTP 400 sem retry.

**A CI não testa chamadas de rede usando chaves de produção.** Depois do deploy, teste pelo WhatsApp uma análise livre e use o relatório do arquivo `storage/logs/php.log` se persistir 400. Não publicar antes de CI verde e backup.
