# TaskEsc AI — Monitoramento, Passo 2/3 (15/10/2026)

## Objetivo
Transformar as métricas privadas JSONL do Passo 1 em um painel visual no TaskEsc, **somente para o administrador autenticado**. Não há alteração de schema nem retorno dos registros brutos.

## Interface
Menu lateral **Monitoramento IA** (rota `/monitoramento-ia`), seguindo estilo escuro do TaskEsc e com layout responsivo:
- Filtros de período: hoje (UTC), 7, 14 e 30 dias.
- Filtro de provedor: todos, Gemini ou Groq.
- Indicadores: tentativas de chamada, tokens realmente informados, falhas HTTP/transporte, latência média.
- Gráfico diário de HTTP 2xx × falhas.
- Roteamento local: consultas `fast_path`, `cache_hit` e `model_route`; exibição **somente quando filtro Todos** porque esses eventos não identificam um provedor.
- Detalhamento por provedor e por ID público de modelo.
- Estado vazio e aviso de coleta iniciada somente no deploy anterior.

## API e segurança
- `GET /api.php?action=ai.usage_dashboard&days=7&provider=all`
- **Auth::admin()** e bloqueio de client-preview. Para tokens de API, requer escopo de leitura (`api.read`/`tools.read`).
- Somente parâmetros `days` ∈ {1,7,14,30} e `provider` ∈ {all,gemini,groq}.
- `AiWhatsAppUsageDashboard` só expõe estatísticas agregadas; não retorna texto, telefone, identificador de mensagem, usuário, prompt, resposta, código de confirmação ou credencial.
- Lê somente arquivos privados de dias específicos, limites máximos de **100.000 eventos por dia** e de **1.200 bytes por evento**; avisa em caso de arquivo truncado.
- Não envia requests para modelos durante o uso do painel. Não estima valores em reais nem atribui cache a um provedor específico.
- Todas as strings dinâmicas no HTML são escapadas; o CSS usa tokens de design do TaskEsc.

## Validação
- Testes de agregação com dados históricos artificiais: tokens presentes/ausentes, Gemini HTTP 200, Groq HTTP 429 e timeout, contagens de cache/PHP, médias, filtros, períodos vazios e ausência de permissão para equipe/cliente-preview.
- Validação Node estática da interface, rotas e filtro, mais `node --check`.
- PHP + MySQL 8 + MariaDB 10.11 no GitHub Actions; deploy somente com backup e health checks.

## Próximo passo
**Passo 3/3**: alertas de quota, limites de consumo configuráveis, retenção automática e validações operacionais. Nenhum bloqueio de utilização ou exclusão de logs entra neste passo.
