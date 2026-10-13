# WPPConnect — Passo 2: painel, webhook e realtime

Este passo conecta o WPPConnect ao TaskEsc sem expor credenciais do provider ao navegador.

## Fluxo

```text
WPPConnect
   │ webhook HTTPS assinado
   ▼
public/wppconnect-webhook.php
   │
   ▼
WppWebhook
   │
   ▼
event_outbox
   │
   ▼
worker realtime
   │
   ▼
Centrifugo
   │
   ▼
wpp/global
   │
   ▼
Administradores conectados
```

O frontend nunca abre uma conexão autenticada diretamente com o Socket.IO do WPPConnect. O TaskEsc permanece como fronteira de segurança.

## Autenticação do webhook

Quando `wppconnect.webhook_url` estiver vazio, o TaskEsc gera automaticamente:

```text
https://task.escotilhaprodutora.com/wppconnect-webhook.php?token=<HMAC>
```

O token é derivado por HMAC do `SECRET_KEY` já configurado no servidor. O `SECRET_KEY` original nunca aparece na URL e nunca é enviado ao navegador.

O endpoint:

- aceita somente POST;
- valida o token com `hash_equals`;
- limita o corpo a 1 MiB;
- aceita JSON;
- ignora eventos que não sejam de ciclo de vida da sessão;
- nunca envia QR bruto, mensagens ou contatos para o Outbox.

## Eventos aceitos

O WPPConnect historicamente envia eventos como `status-find` e `qrcode`. O TaskEsc também aceita variações de estado/sessão/conexão para tolerar diferenças entre releases.

Todos são normalizados para:

```text
event_type: wpp.changed
channel:    wpp/global
```

Payload realtime permitido:

- `operation_id`;
- `kind`;
- `status`;
- `connected`;
- `qr_available`;
- `received_at`.

O conteúdo do QR é somente transformado em Data URI no endpoint autenticado `wpp.qr`, solicitado pelo painel administrativo.

## Permissão realtime

`wpp/global` é exclusivo de Administradores.

Equipe, Cliente e modo preview não recebem nem conseguem assinar o canal.

## Painel

Em **Configurações → WhatsApp**, o Administrador vê:

- provider Online/Offline;
- WhatsApp Conectado/Aguardando/Offline;
- sessão;
- número conectado quando disponível;
- endpoint público;
- iniciar/atualizar sessão;
- exibir QR;
- atualizar status;
- enviar mensagem de teste.

O painel é responsivo e reage ao `wpp.changed` pelo Centrifugo.

## WEBHOOK_URL persistente no container

Depois do deploy deste passo, configure o `WEBHOOK_URL` real sem imprimir o token no terminal:

```bash
cd /home/escotilhaprodutora-task/htdocs/task.escotilhaprodutora.com

export WEBHOOK_URL="$(php -r 'require "app/bootstrap.php"; echo WppConnect::webhookUrl();')"

cd ops/wppconnect

python3 - <<'PY'
from pathlib import Path
import os
p=Path(".env")
value=os.environ["WEBHOOK_URL"]
lines=p.read_text().splitlines()
found=False
out=[]
for line in lines:
    if line.startswith("WEBHOOK_URL="):
        out.append("WEBHOOK_URL="+value)
        found=True
    else:
        out.append(line)
if not found:
    out.append("WEBHOOK_URL="+value)
p.write_text("\n".join(out)+"\n")
PY

unset WEBHOOK_URL

docker compose up -d --force-recreate
docker compose ps
```

O valor fica apenas no `.env` privado da VPS.

## Teste esperado

1. abrir Configurações → WhatsApp;
2. confirmar Provider Online;
3. clicar em **Conectar WhatsApp**;
4. QR aparecer no TaskEsc;
5. ler o QR pelo celular;
6. status mudar para Conectado sem F5;
7. enviar mensagem de teste;
8. desconectar/reconectar e confirmar atualização realtime.

## Escopo

Este passo não altera schema do banco.

O próximo passo é o motor de automações + números WhatsApp em Users/Clients/Tasks.
