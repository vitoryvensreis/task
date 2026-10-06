# Realtime — Passo 1

Camada opcional em `app/Realtime.php`, disponível pelo autoloader existente. Nenhuma
requisição atual a utiliza. Não há alteração em banco, sessões, frontend, CSP,
publicações, canais ou emissão de tokens. `REALTIME_ENABLED` permanece `false`.

## Configuração privada

`/etc/taskesc/realtime.json`, fora do Git e do DocumentRoot. O exemplo sem segredos
está em `config/realtime.example.json`. O provisionador lê apenas na VPS os valores
de `/root/taskesc-realtime-secrets.env`; não executa esse arquivo como shell, não
imprime seu conteúdo e não importa os valores no ambiente da aplicação.

O provisionador identifica o `fastcgi_pass` do virtual host TaskEsc em `nginx -T`,
encontra o pool correspondente e verifica UID/GID dos workers existentes. O diretório
e arquivo pertencem a `root:root`; ACLs POSIX concedem somente travessia/leitura
ao UID identificado do PHP-FPM. Root mantém escrita; grupo e demais usuários não
recebem acesso. Não se alteram grupos, serviço FPM ou permissões do arquivo original.
O arquivo é instalado atomicamente. Uma cópia privada dos arquivos anteriores fica
em `/var/backups/taskesc-realtime/<UTC>/`, acessível somente por root.

Chaves: `REALTIME_ENABLED`, `REDIS_HOST`, `REDIS_PORT`, `CENTRIFUGO_API_URL`,
`CENTRIFUGO_WS_URL`, `CENTRIFUGO_HMAC_SECRET`, `CENTRIFUGO_API_KEY`.
O provisionador aceita aliases convencionais Centrifugo para as duas credenciais,
mas recusa valores ausentes ou conflitantes. Não gera ou rotaciona segredos.

Redis e a API são restritos a loopback. Não há conexões persistentes, redirects,
proxy HTTP, mudanças de sessões ou cache de dados. Configuração ausente, malformada
ou inacessível desativa a camada; erros de conexão retornam `null`/`unavailable`.
Timeouts: Redis 500 ms para conectar/ler; API 500 ms para conectar e 1500 ms no total.
Os métodos de diagnóstico e representação JSON não retornam segredos nem erros brutos.

## Health interno e deploy

`php bin/realtime-health.php` verifica Redis com `PING` e a API autenticada do
Centrifugo com `POST /api/info` (somente leitura). O diagnóstico pode testar a
infraestrutura mesmo com a feature flag desligada. Retorna `ok` ou `degraded`;
somente esse comando de diagnóstico retorna exit code 1 em falhas.

O script está fora de `public/`, não carrega o bootstrap, não inicia sessões e não
acessa o banco. Também permite uma chamada FastCGI direta com endereço de loopback
para verificar as restrições reais do pool; não se cria endpoint ou rota pública.
`public/health.php` mantém o comportamento anterior.

O workflow valida PHP 8.4, JavaScript e cenários de falha antes do deploy. Faz backup
do banco e snapshot do código em produção antes de qualquer alteração. Depois
provisiona a configuração privada, atualiza somente o código e testa o PHP-FPM
diretamente, além do health público existente. Nenhuma migração ou limpeza de banco
é executada pelo deploy deste passo. Em caso de health realtime degradado, o workflow
falha para sinalizar o problema; o TaskEsc continua independente desta camada.

Validação sem infraestrutura: `php tests/realtime.php` (com ou sem extensão Redis).
O provisionador exige root (ou sudo não interativo), Python 3 e libacl já instalada;
falha sem alterar os grupos/serviços se não conseguir aplicar as ACLs.
