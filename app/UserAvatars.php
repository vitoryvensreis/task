<?php
declare(strict_types=1);
/** User-owned photos stored on the VPS, outside the web root. */
final class UserAvatars {
 private const MIME_EXT=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
 private static function dir(): string{return ROOT.'/storage/avatars';}
 private static function locate(int $id): ?array {
  if($id<1)return null;
  foreach(['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'] as $ext=>$mime){
   $p=self::dir().'/u'.$id.'.'.$ext;
   if(is_file($p))return [$p,$mime];
  }
  return null;
 }
 private const MAX_BYTES=10*1024*1024;
 private static function version(int $id,array $source): string {
  $path=self::dir().'/u'.$id.'.rev';
  $stored=@file_get_contents($path);
  if(is_string($stored)&&preg_match('/\\A[a-f0-9]{24}\\z/D',trim($stored)))return trim($stored);
  return (string)filemtime($source[0]).'-'.(string)filesize($source[0]);
 }
 public static function url(int $id): ?string {
  $a=self::locate($id);
  return $a?base('avatar.php?id='.$id.'&v='.self::version($id,$a)):null;
 }
 /**
  * Atomic publication per user. The new photo replaces the previous image;
  * other extensions are deleted (also when changing JPEG -> WebP/PNG).
  * Called with an already validated, staged upload inside the private directory.
  */
 private static function publish(int $id,string $temp,string $ext): void {
  if($id<1||!in_array($ext,self::MIME_EXT,true))throw new InvalidArgumentException('Foto inválida.');
  $dir=self::dir();
  $lock=@fopen($dir.'/u'.$id.'.lock','c');
  if(!$lock){@unlink($temp);fail('Não foi possível proteger a substituição da foto.',500);}
  try {
   if(!flock($lock,LOCK_EX))fail('Não foi possível bloquear a foto para substituição.',500);
   $target=$dir.'/u'.$id.'.'.$ext;
   if(!@rename($temp,$target))fail('Não foi possível publicar a foto.',500);
   @chmod($target,0640);
   foreach(self::MIME_EXT as $otherExt){
    if($otherExt===$ext)continue;
    $previous=$dir.'/u'.$id.'.'.$otherExt;
    if(is_file($previous)&&!@unlink($previous))fail('Nova foto salva, mas não foi possível remover a anterior.',500);
   }
   $revision=bin2hex(random_bytes(12));
   $revFile=$dir.'/u'.$id.'.rev';
   $revTemp=$dir.'/rev-'.bin2hex(random_bytes(10)).'.tmp';
   try{
    if(@file_put_contents($revTemp,$revision,LOCK_EX)!==strlen($revision)||!@rename($revTemp,$revFile))
     fail('Foto salva, mas não foi possível atualizar a versão em cache.',500);
    @chmod($revFile,0600);
   }finally{@unlink($revTemp);}
   clearstatcache(true,$target);
  }finally {
   @unlink($temp);
   @flock($lock,LOCK_UN);@fclose($lock);
  }
 }
 public static function upload(?array $file): array {
  Auth::load();
  if(Auth::$origin!=='web'||MasterSupport::isSupport()||Auth::isPreview())fail('Alteração de foto não permitida nesta sessão.',403);
  checkCsrf();
  $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
  if($error===UPLOAD_ERR_INI_SIZE||$error===UPLOAD_ERR_FORM_SIZE)
   fail('O servidor recusou o tamanho da foto. O limite permitido é de 10 MB.',413);
  if(!is_array($file)||$error!==UPLOAD_ERR_OK)fail('Selecione uma foto JPEG, PNG ou WebP.',422);
  $size=(int)($file['size']??0);
  if($size<1||$size>self::MAX_BYTES)fail('A foto deve ter até 10 MB.',413);
  $tmp=(string)($file['tmp_name']??'');
  if(!is_uploaded_file($tmp))fail('Upload inválido.',422);
  $info=@getimagesize($tmp);
  if(!$info||($info[0]??0)<16||($info[1]??0)<16||($info[0]??0)>8192||($info[1]??0)>8192||($info[0]*$info[1])>50000000)
   fail('Dimensões de imagem inválidas.',422);
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
  if(!isset(self::MIME_EXT[$mime])||($info['mime']??'')!==$mime)fail('Imagem inválida.',422);
  $dir=self::dir();
  if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))fail('Armazenamento indisponível.',500);
  $id=(int)Auth::$user['id'];$ext=self::MIME_EXT[$mime];
  $temp=$dir.'/upload-'.bin2hex(random_bytes(12)).'.tmp';
  if(!move_uploaded_file($tmp,$temp))fail('Não foi possível enviar a foto.',500);
  @chmod($temp,0640);
  self::publish($id,$temp,$ext);
  $url=self::url($id);
  Auth::$user['avatar_url']=$url;
  audit('user',$id,'profile.photo.updated');
  return ['saved'=>true,'avatar_url'=>$url];
 }
 public static function serve(int $id): never {
  Auth::load();
  if($id!==(int)Auth::$user['id']){
   $target=DB::one('SELECT id,role,active FROM users WHERE id=?',[$id]);
   if(!$target||(int)$target['active']!==1)fail('Foto indisponível.',404);
   $allow=MasterSupport::isActor()||$target['role']==='master'||WorkspaceContext::legacyCiFixture();
   if(!$allow&&($wid=(int)(Auth::$workspace['id']??0))>0)
    $allow=(bool)DB::one('SELECT 1 FROM workspace_members WHERE user_id=? AND workspace_id=? AND status=? LIMIT 1',[$id,$wid,'active']);
   if(!$allow)fail('Foto indisponível.',404);
  }
  $file=self::locate($id);if(!$file)fail('Foto indisponível.',404);
  header('Content-Type: '.$file[1]);
  header('Content-Disposition: inline; filename="avatar"');
  header('Cache-Control: private, max-age=300');
  header('X-Content-Type-Options: nosniff');
  readfile($file[0]);exit;
 }
}
