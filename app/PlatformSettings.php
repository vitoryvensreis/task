<?php
declare(strict_types=1);

/** Global configuration that belongs to the verified platform Master, not a tenant admin. */
final class PlatformSettings {
 private static function guard(): void {
  Auth::load();
  if(!MasterSupport::isActor()||Auth::$origin!=='web')fail('Área exclusiva do ADM Master.',403);
 }
 private static function cfgPublic(): array {
  $c=config();
  $smtp=is_array($c['smtp']??null)?$c['smtp']:[];
  $wpp=is_array($c['wppconnect']??null)?$c['wppconnect']:[];
  return [
   'google_client_id'=>(string)($c['google_client_id']??''),
   'google_secret_set'=>!empty($c['google_client_secret']),
   'smtp'=>[
    'host'=>(string)($smtp['host']??''),'port'=>(int)($smtp['port']??587),
    'encryption'=>(string)($smtp['encryption']??'tls'),'username'=>(string)($smtp['username']??''),
    'from'=>(string)($smtp['from']??''),'from_name'=>(string)($smtp['from_name']??'TaskEsc')
   ],
   'smtp_password_set'=>!empty($smtp['password']),
   'wppconnect'=>[
    'internal_url'=>(string)($wpp['internal_url']??'http://127.0.0.1:21465'),
    'public_url'=>(string)($wpp['public_url']??''),
    'session'=>(string)($wpp['session']??'taskesc'),
    'secret_set'=>strlen((string)($wpp['secret']??''))>=32,
    'configured'=>strlen((string)($wpp['secret']??''))>=32
   ]
  ];
 }
 public static function get(): array {
  self::guard();
  $global=DB::all('SELECT `key`,value FROM settings WHERE `key` IN (?,?,?,?,?,?)',
   ['brand_name','brand_color','logo_url','upload_mb','min_delivery_business_hours','retention_days']);
  $settings=[];foreach($global as $row)$settings[$row['key']]=$row['value'];
  return ['branding'=>[
   'brand_name'=>(string)($settings['brand_name']??'TaskEsc'),
   'brand_color'=>(string)($settings['brand_color']??'#ffffff'),
   'logo_url'=>(string)($settings['logo_url']??''),
   'upload_mb'=>(int)($settings['upload_mb']??0),
   'min_delivery_business_hours'=>(int)($settings['min_delivery_business_hours']??24),
   'icons'=>self::icons()
  ],'asaas'=>PlatformAsaasCredentials::get(),...self::cfgPublic()];
 }
 private static function icons(): array {
  $dir=ROOT.'/public/uploads/branding/';
  $icons=['180'=>'pwa-180.png','192'=>'pwa-192.png','512'=>'pwa-512.png','maskable'=>'pwa-maskable-512.png'];
  $out=[];
  foreach($icons as $key=>$name){
   $custom=is_file($dir.$name);
   $out[$key]=$custom?base('uploads/branding/'.$name.'?v='.filemtime($dir.$name))
     :base('assets/taskesc-logo.svg');
  }
  return $out;
 }
 public static function saveBrand(array $p): array {
  self::guard();Auth::rate('master-brand-save',12,3600);
  $fields=[];
  foreach(['brand_name','brand_color','upload_mb','min_delivery_business_hours'] as $k){
   if(!array_key_exists($k,$p))continue;
   $v=trim((string)$p[$k]);
   if($k==='brand_name'&&($v===''||mb_strlen($v)>80))fail('Nome deve ter de 1 a 80 caracteres.',422);
   if($k==='brand_color'&&!preg_match('/^#[a-f0-9]{6}$/i',$v))fail('Cor inválida.',422);
   if($k==='upload_mb')$v=(string)integer($v,0,5242880);
   if($k==='min_delivery_business_hours')$v=(string)integer($v,1,720);
   $fields[$k]=$v;
  }
  if(!$fields)fail('Nenhum campo recebido.',422);
  DB::tx(static function()use($fields):void{
   foreach($fields as $k=>$value){
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$k,$value]);
   }
   audit('settings',0,'master.brand.update',null,['fields'=>array_keys($fields)]);
  });
  return ['saved'=>true];
 }
 private static function saveConfig(array $next): void {
  $dir=ROOT.'/config';
  $temp=$dir.'/config.master.'.bin2hex(random_bytes(7)).'.tmp';
  $oldUmask=umask(0077);
  try{
   if(file_put_contents($temp,"<?php\nreturn ".var_export($next,true).";\n",LOCK_EX)===false)
    fail('Não foi possível gravar configuração privada.',500);
   @chmod($temp,0600);
   if(!@rename($temp,$dir.'/config.php'))fail('Não foi possível atualizar config/config.php.',500);
  }finally{umask($oldUmask);if(is_file($temp))@unlink($temp);}
 }
 public static function saveIntegration(array $p): array {
  self::guard();Auth::rate('master-config-save',10,3600);
  $c=config();
  $section=(string)($p['section']??'');
  $changes=[];
  if($section==='google'){
   $id=trim((string)($p['google_client_id']??''));
   if($id!==''&&strlen($id)>300)fail('Google Client ID inválido.',422);
   if($id!==''){$c['google_client_id']=$id;$changes[]='google_client_id';}
   $secret=trim((string)($p['google_client_secret']??''));
   if($secret!==''){
    if(strlen($secret)>1200)fail('Segredo Google inválido.',422);
    $c['google_client_secret']=$secret;$changes[]='google_client_secret';
   }
  }elseif($section==='email'){
   $old=is_array($c['smtp']??null)?$c['smtp']:[];
   $host=trim((string)($p['host']??''));
   if($host!==''&&!preg_match('/^[a-z0-9.-]{1,190}$/i',$host))fail('Servidor SMTP inválido.',422);
   $username=trim((string)($p['username']??''));
   $from=trim((string)($p['from']??''));
   if($from!==''&&!filter_var($from,FILTER_VALIDATE_EMAIL))fail('Remetente inválido.',422);
   if(strlen($username)>190)fail('Usuário SMTP inválido.',422);
   $next=['host'=>$host,'port'=>integer($p['port']??587,1,65535),
    'encryption'=>enumv($p['encryption']??'tls',['tls','ssl'],'criptografia'),
    'username'=>$username,'from'=>$from,'from_name'=>mb_substr(trim((string)($p['from_name']??'TaskEsc')),0,100)];
   if(trim((string)($p['password']??''))!==''){
    if(strlen((string)$p['password'])>1024)fail('Senha SMTP inválida.',422);
    $next['password']=(string)$p['password'];
   }
   $c['smtp']=array_merge($old,$next);$changes[]='smtp';
  }elseif($section==='whatsapp'){
   $old=is_array($c['wppconnect']??null)?$c['wppconnect']:[];
   $internal=rtrim(trim((string)($p['internal_url']??'http://127.0.0.1:21465')),'/');
   if(!preg_match('#^https?://(?:127\\.0\\.0\\.1|localhost)(?::\\d{1,5})?$#i',$internal))
    fail('WPPConnect só pode usar endereço interno localhost.',422);
   $public=rtrim(trim((string)($p['public_url']??'')),'/');
   if($public!==''&&(!filter_var($public,FILTER_VALIDATE_URL)||!str_starts_with($public,'https://')||strlen($public)>250))
    fail('A URL pública deve usar HTTPS.',422);
   $session=trim((string)($p['session']??'taskesc'));
   if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$session))fail('Nome de sessão inválido.',422);
   $next=['internal_url'=>$internal,'public_url'=>$public,'session'=>$session];
   $secret=trim((string)($p['secret']??''));
   if($secret!==''){
    if(strlen($secret)<32||strlen($secret)>512)fail('Segredo WPPConnect inválido.',422);
    $next['secret']=$secret;
   }
   $c['wppconnect']=array_merge($old,$next);$changes[]='wppconnect';
  }else fail('Seção desconhecida.',422);
  if(!$changes)fail('Nenhuma mudança enviada.',422);
  self::saveConfig($c);
  audit('settings',0,'master.integration.update',null,['section'=>$section,'fields'=>$changes]);
  return ['saved'=>true,'section'=>$section];
 }
 public static function uploadBranding(?array $logo, array $uploads): array {
  self::guard();Auth::rate('master-icon-upload',12,3600);
  $expected=[
   'logo'=>['name'=>'logo','max'=>5*1024*1024,'mimes'=>['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif']],
   'icon_180'=>['name'=>'pwa-180.png','max'=>3*1024*1024,'mimes'=>['image/png'=>'png']],
   'icon_192'=>['name'=>'pwa-192.png','max'=>3*1024*1024,'mimes'=>['image/png'=>'png']],
   'icon_512'=>['name'=>'pwa-512.png','max'=>3*1024*1024,'mimes'=>['image/png'=>'png']],
   'icon_maskable_512'=>['name'=>'pwa-maskable-512.png','max'=>3*1024*1024,'mimes'=>['image/png'=>'png']]
  ];
  $inputs=$uploads;if($logo!==null)$inputs['logo']=$logo;
  $dir=ROOT.'/public/uploads/branding';
  if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))fail('Diretório de branding indisponível.',500);
  $done=[];$logoUrl='';
  foreach($expected as $key=>$spec){
   $file=$inputs[$key]??null;
   if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
   if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||(int)($file['size']??0)<1||(int)($file['size']??0)>$spec['max'])fail('Arquivo inválido ou muito grande: '.$key,422);
   $tmp=(string)($file['tmp_name']??'');
   if(!is_uploaded_file($tmp))fail('Upload inválido.',422);
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';
   if(!isset($spec['mimes'][$mime])||@getimagesize($tmp)===false)fail('Imagem inválida para '.$key,422);
   $name=$key==='logo'?'logo-master-'.bin2hex(random_bytes(8)).'.'.$spec['mimes'][$mime]:$spec['name'];
   if(!@move_uploaded_file($tmp,$dir.'/'.$name))fail('Erro ao gravar imagem.',500);
   @chmod($dir.'/'.$name,0644);
   $done[]=$key;
   if($key==='logo')$logoUrl=base('uploads/branding/'.$name);
  }
  if(!$done)fail('Envie ao menos uma imagem.',422);
  if($logoUrl!=='')DB::run('INSERT INTO settings (`key`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['logo_url',$logoUrl]);
  audit('settings',0,'master.brand.upload',null,['assets'=>$done]);
  return ['uploaded'=>$done,'logo_url'=>$logoUrl,'icons'=>self::icons()];
 }
}
