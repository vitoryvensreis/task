# WPPConnect — Passo 1: infraestrutura e API interna

Este passo prepara o WPPConnect Server para o TaskEsc usando o subdomínio:

`https://wppconnect.escotilhaprodutora.com`

## Decisões

- imagem oficial WPPConnect Server fixada em `2.10.27`;
- Docker isolado do PHP/Node dos outros projetos;
- porta `21465` vinculada apenas em `127.0.0.1`;
- Nginx/CloudPanel faz o proxy HTTPS do subdomínio;
- proxy suporta Upgrade/Connection para Socket.IO/WebSocket;
- Manager desabilitado em produção;
- volumes persistentes para tokens, sessão Chromium, uploads, imagens e logs;
- TaskEsc acessa o provider internamente por `http://127.0.0.1:21465`;
- navegador do TaskEsc não recebe SECRET_KEY nem token do WPPConnect.

O WPPConnect possui REST, webhook e Socket.IO. A estratégia do TaskEsc será manter o provider atrás do servidor e retransmitir para o frontend somente estados sanitizados pela infraestrutura já existente de Centrifugo. Isso evita expor credenciais do WhatsApp no navegador.

## Arquivos

- `ops/wppconnect/docker-compose.yml`
- `ops/wppconnect/.env.example`
- `ops/wppconnect/nginx-subdomain.example.conf`
- `app/WppConnect.php`

## 1. DNS

Crie `wppconnect.escotilhaprodutora.com` apontando para o IP público da VPS.

## 2. Subdomínio no painel

Crie o site/vhost HTTPS e aplique o conteúdo de `nginx-subdomain.example.conf` no proxy personalizado. O upstream é `127.0.0.1:21465`.

## 3. Subir o container

No servidor:

```bash
cd /home/escotilhaprodutora-task/htdocs/task.escotilhaprodutora.com/ops/wppconnect
cp .env.example .env
SECRET=$(openssl rand -hex 32)
sed -i "s/CHANGE_ME_WITH_A_RANDOM_64_HEX_SECRET/$SECRET/" .env
chmod 600 .env
docker compose pull
docker compose up -d
docker compose ps
curl -fsS http://127.0.0.1:21465/healthz
```

O `.env` real nunca deve entrar no Git.

## 4. Configurar TaskEsc

Em `config/config.php`, adicione:

```php
'wppconnect' => [
  'internal_url' => 'http://127.0.0.1:21465',
  'public_url' => 'https://wppconnect.escotilhaprodutora.com',
  'session' => 'taskesc',
  'secret' => 'MESMO_SECRET_KEY_DO_ENV',
  'webhook_url' => '',
],
```

O deploy atual preserva `config/config.php`.

## 5. API TaskEsc disponível neste passo

Depois de configurar o provider, o TaskEsc poderá chamar:

- `wpp.status` — health + estado da sessão;
- `wpp.start` — inicia a sessão;
- `wpp.qr` — obtém QR Code;
- `wpp.send_test` — envia uma mensagem de teste.

Todos exigem usuário Administrador. O SECRET_KEY e o Bearer token permanecem somente no backend.

## Próximo passo

O Passo 2 será o webhook assinado do WPPConnect + relay de status/QR/eventos pelo Centrifugo já instalado. Em seguida entra o schema de automações, telefones WhatsApp de users/clients e o editor visual.
