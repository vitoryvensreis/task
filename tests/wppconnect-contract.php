<?php
declare(strict_types=1);

define('ROOT',dirname(__DIR__));

function wppCheck(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$compose=file_get_contents(ROOT.'/ops/wppconnect/docker-compose.yml');
$env=file_get_contents(ROOT.'/ops/wppconnect/.env.example');
$proxy=file_get_contents(ROOT.'/ops/wppconnect/nginx-subdomain.example.conf');
$client=file_get_contents(ROOT.'/app/WppConnect.php');
$webhook=file_get_contents(ROOT.'/app/WppWebhook.php');
$realtime=file_get_contents(ROOT.'/app/WppRealtime.php');
$publicWebhook=file_get_contents(ROOT.'/public/wppconnect-webhook.php');
$auth=file_get_contents(ROOT.'/app/RealtimeAuth.php');
$app=file_get_contents(ROOT.'/public/assets/app.js');
$api=file_get_contents(ROOT.'/public/api.php');
$config=file_get_contents(ROOT.'/config/config.example.php');

foreach([$compose,$env,$proxy,$client,$webhook,$realtime,$publicWebhook,$auth,$app,$api,$config] as $source)wppCheck(is_string($source)&&$source!=='','WPPConnect contract file missing.');

wppCheck(str_contains($compose,'wppconnect/wppconnect-server:${WPP_SERVER_TAG:-2.10.27}'),'WPPConnect image must be version-pinned.');
wppCheck(str_contains($compose,'127.0.0.1:${PORT:-21465}:${PORT:-21465}'),'WPPConnect must bind only on loopback.');
wppCheck(str_contains($compose,'MANAGER_ENABLED: "${MANAGER_ENABLED:-false}"'),'WPPConnect Manager must default to disabled.');
wppCheck(str_contains($compose,'wppconnect_tokens')&&str_contains($compose,'wppconnect_user_data'),'WPPConnect session/token data must be persistent.');
wppCheck(str_contains($proxy,'proxy_set_header Upgrade $http_upgrade')&&str_contains($proxy,'proxy_set_header Connection "upgrade"'),'Reverse proxy must support WebSocket/Socket.IO.');
wppCheck(str_contains($proxy,'proxy_buffering off'),'Reverse proxy must avoid buffering realtime traffic.');
wppCheck(str_contains($env,'HOST=https://wppconnect.escotilhaprodutora.com'),'Public WPPConnect host mismatch.');
wppCheck(str_contains($client,"config('wppconnect',[])")&&str_contains($client,'http://127.0.0.1:21465'),'Runtime client must use private WPPConnect config with loopback default.');
wppCheck(str_contains($client,'Authorization: Bearer ')&&str_contains($client,'generate-token'),'Runtime client must authenticate WPPConnect API requests.');
wppCheck(str_contains($client,'self::requirePost()'),'State-changing WPPConnect commands must be POST-only.');
wppCheck(str_contains($client,'requestRaw')&&str_contains($client,'image/png')&&str_contains($client,'data:image/png;base64,'),'QR endpoint must support binary PNG responses from WPPConnect.');
wppCheck(str_contains($client,"'webhook'=>self::webhookUrl()"),'start-session must register the signed TaskEsc webhook.');
wppCheck(str_contains($client,"hash_hmac('sha256','taskesc-wppconnect-webhook-v1'"),'Webhook token must be derived without exposing SECRET_KEY.');
wppCheck(str_contains($publicWebhook,'WppConnect::verifyWebhookToken')&&str_contains($publicWebhook,'WppWebhook::handle'),'Public webhook must verify its token before processing.');
wppCheck(str_contains($webhook,'event_ignored')&&str_contains($webhook,'qr_digest'),'Webhook relay must ignore non-lifecycle payloads and hash QR data.');
wppCheck(!str_contains($realtime,'qrcode')&&!str_contains($realtime,'qrCode'),'Realtime publisher must not transport raw QR content.');
wppCheck(str_contains($realtime,"'wpp/global'")&&str_contains($realtime,"'wpp.changed'"),'WPP lifecycle must publish through the admin realtime channel.');
wppCheck(str_contains($auth,"\$channels[] = 'wpp/global'")&&str_contains($auth,"\$channel === 'kanban/global' || \$channel === 'wpp/global'"),'Only administrators must receive the WPP realtime channel.');
wppCheck(str_contains($app,'wppSettingsCard')&&str_contains($app,"api('wpp.qr')")&&str_contains($app,'handleWppRealtime(publication)'),'Settings must expose status, QR and realtime WPP handling.');

foreach(['wpp.status','wpp.start','wpp.qr','wpp.send_test'] as $route)wppCheck(str_contains($api,"'$route'"),'Missing API route '.$route);
wppCheck(str_contains($config,"'wppconnect' => [")&&str_contains($config,"'secret' => ''"),'Private WPPConnect config example missing.');

echo "WPPConnect step 2: isolated provider, signed webhook, admin realtime and settings panel OK\n";