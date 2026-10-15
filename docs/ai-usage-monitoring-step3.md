# TaskEsc AI — Etapa 3/3: limites, alertas e retenção

## Política segura (nenhuma migração)
Configuração persistida como JSON na tabela existente settings (`ai_usage_policy_v1`). Sem SaaS, tabelas ou dependências adicionais.

**Padrão:** `enabled=false`, limites Gemini/Groq `0` (ilimitados), alerta em 80%, retenção de 30 dias. Nenhuma chamada existente é bloqueada sem o administrador ativar o mecanismo. Retenção não pode ser menor que 30 dias para não prejudicar os gráficos.

O administrador acessa **Monitoramento IA** e configura:
- Ativar/desativar cotas diárias (UTC).
- Limites diários de **tentativas ao Gemini e ao Groq**, separadamente (0 = ilimitado), de 0 a 100.000.
- Percentual para alerta interno (50% a 99%).
- Retenção privada de arquivos de telemetria (30 a 365 dias).

## Funcionamento técnico
- `AiWhatsAppProvider::plan` reserva cada tentativa **antes do HTTP**, com `SELECT ... FOR UPDATE` + transação MySQL.
- Caso o modelo primário atinja a cota, o roteador ainda pode usar outro provedor configurado com cota disponível. Se ambos estiverem bloqueados, retorna mensagem explicativa `ai_usage_daily_limit` sem retry de worker. Uma reserva não é estornada após timeout, evitando exceder o teto por reinício/retry.
- Consultas PHP diretas, cache, agenda, financeiro e operações já locais continuam funcionando; limite de IA afeta apenas a chamada ao modelo.
- As quotas são **de tentativas**, não de tokens financeiros. Tokens podem não ter sido informados por erro HTTP; portanto o sistema não promete limite financeiro exato.
- APIs: `GET ai.usage_policy` e `POST ai.usage_policy_save`; obrigatoriamente ADM. POST usa o CSRF do frontend ou token de API com escopo de escrita. GET não pode salvar configuração.
- Cron existente envia alertas via notificações internas **somente para ADMs**, com chave única por dia/provedor/nível/usuário. Sem WhatsApp/email automático e sem loop de notificações.
- Cron remove somente `storage/logs/ai-usage-YYYY-MM-DD.jsonl` com datas anteriores à retenção; não segue links simbólicos, não remove php.log ou logs de outros projetos. Remove contadores antigos específicos sem tocar em outras settings. Falha de manutenção não interrompe as demais rotinas do cron.

## Validação
Testes PHP/MySQL/MariaDB verificam idempotência, cotas independentes, rejeição de excesso, avisos, funções autorizadas e manutenção conservadora. Node verifica interface e rotas. Integração operacional deve ser validada no painel após o deploy.

**Observações:** retenção depende de o cron do TaskEsc continuar executando. Contadores diários seguem fuso UTC. Alertas são notificação interna, sem estimativa de R$ economizados. O usuário decide se ativa tetos.
