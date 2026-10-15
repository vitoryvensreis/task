# TaskEsc AI — Bloco Monitoramento, Passo 1 de 3 (15/10/2026)

## Escopo implementado
Sem adicionar tabelas/migrations (schema **29** permanece intacto), o worker registra telemetria mínima fora do diretório `public/`, em `storage/logs/ai-usage-AAAA-MM-DD.jsonl` (UTC). `.gitignore` já ignora `storage/logs/*`.

Cada tentativa de geração Gemini/Groq registra **apenas**:
- data/hora em UTC, provedor e identificador público do modelo;
- HTTP numérico, `curl_errno` numérico e duração em milissegundos;
- tokens de entrada/saída/total informados pelo provedor, se presentes; caso contrário `null` (nunca inventar quantidades).

Cada mensagem encaminhada pelo worker para um plano registra **somente** a origem da interpretação:
- `fast_path`: comando reconhecido diretamente em PHP;
- `cache_hit`: interpretação de leitura aproveitada da memória, com leitura atual do banco;
- `model_route`: caminho de interpretação pelo modelo (pode chamar um ou dois provedores por conta do fallback).

Não salva texto da pergunta, prompt, conteúdo de resposta, argumento de ferramenta, nome do usuário, IP, JID, número WhatsApp, ID da mensagem, ID da tarefa, chave ou token privado. A escrita do log é best-effort com trava de arquivo; eventual falha de disco **não** bloqueia processamento, transações, cobranças, confirmações ou mensagens. O arquivo fica com modo restrito `0600` ao ser criado.

## Consulta local (já no Passo 1)
```bash
cd /home/escotilhaprodutora-task/htdocs/task.escotilhaprodutora.com
php bin/ai-usage-report.php 7
```
Retorna JSON agregado por até 30 dias com tentativas de API, HTTP 2xx versus erros, latência média, tokens reportados, contagem de rotas PHP/cache/modelo e quebra por provedor.

**Importante sobre economia:** cada `fast_path` ou `cache_hit` evita a etapa de planejamento por IA, mas **não significa necessariamente um valor em reais poupado**. Não se estimam custos monetários sem uma tabela de preços versionada. Tentativas de fallback são contabilizadas por provedor; `model_route` não é o mesmo que uma chamada HTTP. Eventos e tokens históricos anteriores à instalação não podem ser reconstruídos; a coleta começa a partir do deploy.

## Próximas etapas
- **Passo 2/3:** painel administrativo, leitura agregada segura, filtros por período/modelo/provedor, cache e duração.
- **Passo 3/3:** limites de uso configuráveis, alertas, retenção/limpeza de arquivos e validação de produção.

## Qualidade
- Cobertura de testes: resposta Gemini/Groq com usage, HTTP 429, timeout, ausência de usage, PII não persistida.
- CI deve aprovar validação PHP, MySQL 8 e MariaDB 10.11. Merge e deploy somente após backup e health check.
- Nenhuma alteração no módulo financeiro, agenda, grupos, permissões, interface ou banco de produção.
