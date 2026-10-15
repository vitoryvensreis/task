# TaskEsc AI — consulta de aulas e migração de modelo Groq (14/10/2026)

## Problema observado

- O modelo `gemini-3.8-flash` retornou HTTP 200 no diagnóstico de **metadados** da chave (isso não garante que `generateContent` funcione em todas as condições).
- O modelo Groq `llama-3.3-70b-versatile` retornou HTTP 404 no diagnóstico. Segundo a documentação oficial Groq (https://console.groq.com/docs/deprecations), `llama-3.3-70b-versatile` foi descontinuado em 16/08/2026 para Free/Developer.
- A frase *"Task, encontre as tarefas relacionadas à edição de aulas e me diga quais ainda estão em produção"* encontrava zero tarefas com os dois filtros (consulta por "aula" e etapa "Em produção"), mas informava apenas `Nenhuma task encontrada`, sem procurar correspondências em outras etapas.
- A variação *"... me diga quais são elas"* ainda dependia do provedor de IA.

## Alterações

1. Pesquisa de aulas por linguagem curta passa a aceitar as duas frases. Ambas seguem pelo método autorizado `TaskEscTaskApi::list` e não gastam requisições de IA.
2. Quando uma pesquisa explicitamente filtrada por etapa retorna zero, `show_other_stages=true` permite consultar de novo sem a restrição de etapa, **mantendo as mesmas permissões, busca e limite**. A resposta diferencia ausência na coluna de ausência no sistema.
3. Catálogo `list_tasks` aceita `show_other_stages` opcional (boolean), aplicado apenas quando também há etapa selecionada, e usa os registros atuais. Nenhuma exclusão, movimentação ou modificação de tarefas.
4. Modelo padrão Groq em `config.example.php` e na ausência de configuração explícita mudou para `openai/gpt-oss-120b`, uma opção de produção com tool calling publicada pelo Groq. **Não reescrever ou publicar `config/config.php` privado**, que continua com o modelo configurado anteriormente.
5. `php bin/ai-model-check.php` usa GET da API Groq `/openai/v1/models` para listar apenas IDs selecionados que a chave pode acessar, quando o modelo atual retornar 404. Não usa chat completions, não mostra chaves nem respostas brutas. A lista vem da API da própria conta.

## Ajustar o modelo na VPS (após deploy)

```bash
cd /home/escotilhaprodutora-task/htdocs/task.escotilhaprodutora.com
php bin/ai-model-check.php
```

Se houver `available_replacements`, escolha um dos modelos realmente retornados (preferencialmente `openai/gpt-oss-120b` quando disponível). Depois edite o `config/config.php` privado:

```php
'groq_model' => 'openai/gpt-oss-120b',
```

Salve, execute `php -l config/config.php`, reinicie `taskesc-ai-whatsapp-worker.service` e verifique com `php bin/ai-model-check.php`.

**Não copie API keys para o GitHub ou para o chat.** Se uma credencial tiver sido exposta, substitua no console oficial.

## Teste no WhatsApp

- `Task, encontre as tarefas relacionadas à edição de aulas e me diga quais são elas`
- `Task, encontre as tarefas relacionadas à edição de aulas e me diga quais ainda estão em produção`

A primeira lista até 10 correspondências de "aula" nas etapas visíveis. A segunda prioriza "Em produção", mas quando não houver coincidência mostra quantas correspondências de "aula" existem em outras etapas. Essas consultas são somente leitura.

A consulta por `q=aula` utiliza a pesquisa já existente de tarefas (título, cliente, categoria, responsável ou ID). Não há busca semântica por briefing completo neste passo.

Testes: `php tests/ai-whatsapp-fast-path.php`, `php tests/ai-whatsapp-tools.php` no MySQL 8 e MariaDB 10.11.

## Correção de diagnóstico (após teste real de 14/10)

A chave Groq retornou um caso inconsistente: `GET /models/openai%2Fgpt-oss-120b` devolveu HTTP 404, enquanto `GET /models` devolveu HTTP 200 e incluiu o identificador exato `openai/gpt-oss-120b`. Logo, **não use o GET de detalhe com ID contendo barra para concluir se o modelo está disponível à conta**.

O comando de diagnóstico agora consulta o inventário oficial `GET /openai/v1/models` uma única vez:
- `status: "listed"`: ID ativo presente na listagem autenticada. `generation_tested: false` significa que o funcionamento real ainda **não foi validado**.
- `status: "not_listed"`: ID ausente ou inativo; alternativas só são sugeridas se presentes no mesmo inventário.
- `auth_or_access_rejected`, `quota_limit` ou `connection_failed`: falha na própria consulta. Não se deve inferir indisponibilidade do modelo.
- A listagem não demonstra que a API de geração e o tool calling estão operacionais.

Para testar o fallback real sem mexer no TaskEsc:
```bash
php bin/ai-model-check.php --probe-groq
```
O parâmetro opcional emite **uma única chamada pequena ao modelo** com uma função fictícia `taskesc_diagnostic` (nenhuma operação de negócio é executada). Isso **pode consumir tokens/créditos do Groq**. A saída exibe somente status e código HTTP; nunca a API key, o corpo da conversa, o prompt nem a resposta bruta.

Quando `probe.status` for `operational`, o Groq aceitou a geração e retornou a chamada de ferramenta esperada. Se a listagem estiver OK e o probe falhar, examine apenas o status sintético e o código HTTP exibidos.
