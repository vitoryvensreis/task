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

$defaultIcon=$basePath.'/assets/taskesc-logo.svg';
$icon192=is_file($custom192)?($basePath.'/uploads/branding/pwa-192.png?v='.filemtime($custom192)):$defaultIcon;
$icon512=is_file($custom512)?($basePath.'/uploads/branding/pwa-512.png?v='.filemtime($custom512)):$defaultIcon;
$iconMask=is_file($customMask)?($basePath.'/uploads/branding/pwa-maskable-512.png?v='.filemtime($customMask)):$defaultIcon;

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
 'background_color'=>'#050607',
 'theme_color'=>'#101820',
 'categories'=>['productivity','business'],
 'icons'=>[
  ['src'=>$icon192,'sizes'=>is_file($custom192)?'192x192':'any','type'=>is_file($custom192)?'image/png':'image/svg+xml','purpose'=>'any'],
  ['src'=>$icon512,'sizes'=>is_file($custom512)?'512x512':'any','type'=>is_file($custom512)?'image/png':'image/svg+xml','purpose'=>'any'],
  ['src'=>$iconMask,'sizes'=>is_file($customMask)?'512x512':'any','type'=>is_file($customMask)?'image/png':'image/svg+xml','purpose'=>'maskable']
 ]
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
