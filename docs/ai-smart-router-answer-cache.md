# TaskEsc AI — Roteador inteligente + cache de perguntas e respostas (15/10/2026)

## Objetivo
Combinar o roteamento existente **PHP direto → contexto privado → memória de interpretação → modelo** com redução de tokens em casos simples e reutilização segura de respostas.

## Roteador inteligente
- As regras locais, confirmação administrativa, memória contextual de 10 minutos e cache de planos de 7 dias continuam em primeiro lugar.
- Apenas perguntas não reconhecidas localmente são avaliadas por `AiWhatsAppSmartRouter`.
- Perguntas **simples de leitura** sobre somente um domínio recebem catálogo menor de ferramentas autorizadas: tarefas, agenda, financeiro ou cadastros. Se ambos os modelos já estiverem configurados, pode-se priorizar Groq em leituras simples, mantendo Gemini como fallback. Não habilita chaves nem altera as cotas existentes.
- Perguntas de múltiplos módulos, ambíguas, longas ou contendo verbos de escrita mantêm o catálogo completo e provedor principal configurado (normalmente Gemini). O roteador **não executa** ações e não amplia o catálogo permitido ao usuário.
- Mesmo depois do planejamento, o worker continua verificando permissão/autenticação e exige confirmação para ações sensíveis.

## Cache de perguntas e respostas
A tabela `ai_whatsapp_inbox` **já registra as mensagens recebidas**. O novo `AiWhatsAppAnswerCache` armazena apenas resposta final + HMAC do retorno da ferramenta read-only e metadados criptograficamente derivados na tabela `idempotency` existente, **não** armazena cópia do resultado bruto ou identificadores de WhatsApp em texto claro.

Uma resposta só pode ser reutilizada se:
1. A consulta é somente leitura e autorizada **na conversa privada**, na mesma sessão, chat, remetente e usuário;
2. O conjunto de ferramentas/permissões é o mesmo;
3. A pergunta e os argumentos interpretados são idênticos;
4. A consulta **é executada novamente** pelo TaskEsc e seu resultado atual tem o mesmo HMAC do resultado anterior;
5. A entrada foi gravada nos últimos **7 dias**.

Se qualquer dado atual mudar, o Task formata a nova resposta e substitui o registro. **Financeiro, tarefas e agenda nunca usam um resultado antigo sem validação ao vivo.** A economia direta maior continua sendo evitar a chamada ao LLM no cache de interpretações e no PHP; o cache de respostas evita reformatação, mas não promete reduzir consultas SQL.

Não reutiliza respostas de criação, alteração, exclusão, cobrança, pagamentos, aprovações, códigos de confirmação, erros ou respostas de texto livre do modelo. As respostas de ajuda continuam sendo montadas dinamicamente a partir das ferramentas atuais. Sem cache cross-user/cross-chat/cross-group.

## Monitoramento e retenção
- Evento anônimo `answer_cache_hit` no painel Monitoramento IA. Somente contagem agregada; sem perguntas, contatos, IDs ou valores financeiros.
- O cron existente exclui em lotes de até 500 linhas só o prefixo `ai-answer-v1:` expirado após 7 dias. Não remove chaves de outros módulos ou integrações.
- Schema 29 preservado; sem novas tabelas, migrations, SaaS ou mudança no frontend de tarefas.

## Testes
- PHP/MySQL 8/MariaDB 10.11: roteamento read-only por domínio, catálogo reduzido, chamadas complexas preservadas, recusa de ferramentas que não foram oferecidas.
- Cache com dado igual, dado alterado, total financeiro alterado, argumento alterado, usuário/chat diferente, grupo, catálogo alterado e tentativa de gravação de operação de escrita.
- CI verde obrigatório, seguido de backup pré-deploy e health checks do worker.
- Após publicação, confirmar duas consultas repetidas no WhatsApp e observar a métrica no monitoramento.

## Limite deliberado
Não foi implementado um banco de treinamento com todas as respostas reutilizáveis sem prazo. Guardar e responder sem verificar dados privados dinâmicos criaria risco real de dados desatualizados ou vazamento de permissões. O Task grava a conversa no inbox como já fazia e guarda somente caches comprovadamente seguros.
