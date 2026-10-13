# Realtime — Passo 3

Este passo adiciona somente o consumidor do `event_outbox`. Nenhum código de frontend/WebSocket é ativado aqui.

## Fluxo

1. `bin/realtime-worker.php` busca até 20 eventos vencidos por ciclo.
2. Cada evento usa um named lock MySQL (`GET_LOCK`) antes de ser relido/processado, impedindo dois workers de publicar a mesma linha simultaneamente.
3. `CentrifugoPublisher` chama exclusivamente a API local configurada em `/etc/taskesc/realtime.json`, no endpoint `POST /api/publish`, usando `X-API-Key`.
4. `event_key` é enviado como `idempotency_key` do publish para reduzir duplicação em retries/crashes.
5. Em sucesso, o worker grava `published_at` e limpa `last_error` por compare-and-swap.
6. Em falha, incrementa `attempts`, grava somente um código seguro em `last_error` e move `available_at` com backoff exponencial de 5s até 300s.
7. Após 8 falhas o evento continua preservado no Outbox, mas deixa de ser consumido automaticamente e aparece como `exhausted` no health.

## Serviço

O deploy instala `taskesc-realtime-worker.service` usando o mesmo usuário/grupo do pool PHP-FPM do TaskEsc, que já possui leitura da configuração privada realtime. O serviço usa `Restart=on-failure` e grava heartbeat em `storage/realtime-worker-health.json`.

## Health e diagnóstico

```bash
php bin/realtime-worker.php --health
sudo python3 ops/realtime-step3.py check --deploy-path=/caminho/taskesc
sudo python3 ops/realtime-step3.py diagnostic --deploy-path=/caminho/taskesc
```

O diagnóstico cria um evento `realtime.diagnostic`, publica no canal simples `diagnostic` e exige `published_at` antes de concluir com sucesso. Nenhum secret, payload bruto ou erro de transporte é escrito em log.

O health diferencia **liveness do worker** de **saúde da fila**. Heartbeat/Redis/Centrifugo quebrados continuam retornando falha. Já eventos históricos que chegaram ao limite de 8 tentativas permanecem preservados e aparecem como `queue_status: warning` / `queue_warning: exhausted_events`, sem derrubar um worker que está ativo e processando eventos novos.
