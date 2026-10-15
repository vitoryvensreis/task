# TaskEsc AI — Memória contextual entre mensagens (Passo 1/3)

## Problema
O TaskEsc respondia a perguntas explícitas, mas não conseguia continuar naturalmente "liste minhas tarefas" → "e as do Saulo?" → "só as atrasadas" nem "meus compromissos hoje" → "e amanhã?" sem reenviar tudo para o modelo. O contexto financeiro separado ("total despesas pendentes" → "esse mês") já estava funcionando.

## Implementação
- `AiWhatsAppConversationContext` guarda **somente a última intenção autorizada de leitura** (`tool` e `arguments`), referenciada ao ID da mensagem recebida. Não armazena resposta ou resultado financeiro.
- Reaproveita a tabela `idempotency` existente com chave HMAC por sessão, chat, remetente e catálogo de ferramentas; usuário é isolado também pela PK. Sem novas tabelas, migrations ou mudanças no schema 29.
- Contexto disponível **só no privado**, por **até 10 minutos**, e **apenas se a mensagem imediatamente anterior daquele remetente/chat tiver sido autorizada, processada e enviada**. Qualquer outra mensagem entre as duas invalida o reaproveitamento. Mudança de permissões ou ferramentas invalida pela impressão digital do catálogo.
- Jamais guarda planos de escrita (`create_task`, `edit_task`, `move_task`, operações administrativas), códigos de confirmação, prompts, conteúdo de conversas ou resultados.
- O worker revalida identidade e permissões imediatamente antes de cada operação. Uma continuação sempre refaz a consulta ao banco. A memória não reutiliza valores antigos.

## Frases de continuidade determinísticas
| Contexto anterior | Nova mensagem | Comportamento |
| --- | --- | --- |
| `list_tasks` | "e as do Saulo?" | Muda somente termo de pesquisa para Saulo |
| `list_tasks` | "só as atrasadas" | Filtra tarefas vencidas ainda abertas |
| `list_tasks` | "e as concluídas?" | Consulta a etapa Concluídas, descarta filtro de atraso contraditório |
| `list_tasks` | "só as em produção" | Consulta a etapa Em produção |
| `agenda_list` | "e amanhã?" | Consulta o próximo dia no fuso configurado |
| `finance_expenses` | "e só as pagas" / "e só as pendentes" | Mantém período e altera apenas situação do pagamento |

Fora desses padrões seguros, o comportamento anterior do Gemini/Groq continua; não se adivinha uma interpretação com efeito de escrita.

## Verificação
- Novos testes na suíte `tests/ai-whatsapp-tools.php`: fluxo real de 4 mensagens sem chamadas de IA, mudança de filtros, isolamento usuário/chat/grupo, interrupção por outro assunto, não reutilização de planos de escrita, continuação de agenda e finanças.
- Testes completos em PHP, MySQL 8 e MariaDB 10.11.
- Nenhuma mudança em credenciais, limites de IA, financeiro, funções autorizadas ou visibilidade de grupos.

## Próximos passos
2/3. Consultas contextuais compostas e acompanhamento com planejamento IA **limitado e explicitamente autorizado**, sem repetição do catálogo completo e sem risco de operações implícitas.
3/3. Respostas mais naturais, perguntas de desambiguação úteis e métricas de continuidade/cache sem armazenar conversas ou PII.

O comportamento de "esse mês" após pendências financeiras continua preservado pelo módulo anterior.
