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
$api=file_get_contents(ROOT.'/public/api.php');
$config=file_get_contents(ROOT.'/config/config.example.php');

foreach([$compose,$env,$proxy,$client,$api,$config] as $source)wppCheck(is_string($source)&&$source!=='','WPPConnect contract file missing.');

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
foreach(['wpp.status','wpp.start','wpp.qr','wpp.send_test'] as $route)wppCheck(str_contains($api,"'$route'"),'Missing API route '.$route);
wppCheck(str_contains($config,"'wppconnect' => [")&&str_contains($config,"'secret' => ''"),'Private WPPConnect config example missing.');

echo "WPPConnect step 1: isolated container, WebSocket proxy and authenticated backend client OK\n";