# TaskEsc AI — Memória de perguntas e tratamento de erros

## O que é salvo e o que é reaproveitado

Cada mensagem recebida pelo WPPConnect já é persistida de forma deduplicada em `ai_whatsapp_inbox`, vinculada a chat, remetente, autorização e resultado (não é necessário criar outra cópia da conversa).

Quando Gemini/Groq interpreta uma pergunta que resulta em **ferramenta somente de leitura**, o worker pode guardar **apenas o plano estruturado**, como `list_tasks(query="Aula")`, em um namespace exclusivo na tabela já existente `idempotency` (`ai-read-plan-v1:`), por até **7 dias**. A chave é um HMAC baseado na pergunta, usuário, sessão, chat, tipo da conversa e catálogo autorizado; o banco não guarda uma segunda cópia do texto no cache. Uma pergunta repetida no mesmo escopo ignora o provedor de IA e **executa novamente a leitura autorizada**, para refletir tarefas, agenda, pagamentos e saldos atuais. A autorização do usuário e a participação no grupo continuam sendo revalidadas antes de executar a ferramenta e de enviar a resposta.

O cache jamais guarda ou repete respostas antigas, textos gerados pelo modelo, confirmações, criação, edição, movimentação, cobrança, pagamento, cancelamento ou exclusão. Mensagens com referência de tempo relativa (amanhã, hoje, este mês etc.), senha ou chave de API não entram no cache de interpretação. Comandos simples existentes no fast path continuam resolvidos diretamente pelo PHP e sem modelo.

A implementação reutiliza a tabela `idempotency` que já existia, isolando suas chaves das chaves de negócio. **Schema permanece 29; nenhuma migração ou flag nova.** O histórico de mensagens segue sujeito à política de retenção existente do TaskEsc.

## Erros claros no WhatsApp

- HTTP 429 do provedor: `limite de requisições da IA`; tenta Groq caso configurado e, se falhar, devolve mensagem sem fazer novas tentativas desnecessárias.
- HTTP 401/403: `chave de API/configuração`; HTTP 404: `modelo indisponível`.
- Modelo malformado ou múltiplas ferramentas: `Não entendi a solicitação com segurança. Reformule...`.
- Ferramenta fora do catálogo: `Não posso executar essa operação neste chat...`.
- Indisponibilidade de rede/provedor: até três tentativas espaçadas, depois uma resposta explicando indisponibilidade.
- Outras falhas inesperadas: resposta genérica segura, jamais stack trace, chave, prompt, telefones ou dados privados.

O worker reserva a tentativa de entrega **antes** de chamar WPPConnect, preservando a garantia at-most-once. Ele não reenvia automaticamente entregas incertas. Ações de negócio continuam sendo transacionais e idempotentes.

**Limite:** falhas antigas que já terminaram em `failed` não serão reenviadas automaticamente. Use `php bin/ai-whatsapp-diagnose.php` para investigação; novos pedidos usarão o tratamento acima.

## Testes

`php tests/ai-whatsapp-tools.php`, executado na CI contra MySQL 8 e MariaDB 10.11: primeira interpretação via planner, repetição sem planner, dados atualizados no segundo resultado, isolamento por usuário/chat/catálogo, proibição de cache em ações e segredos, falhas definitivas com resposta, erros de cota e conexão com retries e entrega única.

A funcionalidade só deve ser mesclada após aprovação de todos os checks e deploy com backup validado.
