<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$basePath=(string)(parse_url((string)config('base_url',''),PHP_URL_PATH)??'');
$basePath='/'.trim($basePath,'/');
if($basePath==='/')$basePath='';
$name=trim((string)setting('brand_name','TaskEsc'))?:'TaskEsc';

$dir=ROOT.'/public/uploads/branding';
$custom192=$dir.'/pwa-192.png';
$custom512=$dir.'/pwa-512.png';
$customMask=$dir.'/pwa-maskable-512.png';

$icon192=is_file($custom192)?($basePath.'/uploads/branding/pwa-192.png?v='.filemtime($custom192)):($basePath.'/icons/icon-192.png');
$icon512=is_file($custom512)?($basePath.'/uploads/branding/pwa-512.png?v='.filemtime($custom512)):($basePath.'/icons/icon-512.png');
$iconMask=is_file($customMask)?($basePath.'/uploads/branding/pwa-maskable-512.png?v='.filemtime($customMask)):($basePath.'/icons/icon-maskable-512.png');

echo json_encode([
 'id'=>($basePath?:'/').'?app=taskesc',
 'name'=>$name,
 'short_name'=>mb_substr($name,0,18),
 'description'=>'Gestão de demandas, produção e entregas em um só fluxo.',
 'lang'=>'pt-BR',
 'start_url'=>($basePath?:'').'/dashboard?source=pwa',
 'scope'=>($basePath?:'').'/',
 'display'=>'standalone',
 'display_override'=>['window-controls-overlay','standalone'],
 'orientation'=>'any',
 'background_color'=>'#050507',
 'theme_color'=>'#8057e3',
 'categories'=>['productivity','business'],
 'icons'=>[
  ['src'=>$icon192,'sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
  ['src'=>$icon512,'sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],
  ['src'=>$iconMask,'sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable']
 ]
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
