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
 public static function url(int $id): ?string {
  $a=self::locate($id);
  return $a?base('avatar.php?id='.$id.'&v='.(string)filemtime($a[0])):null;
 }
 public static function upload(?array $file): array {
  Auth::load();
  if(Auth::$origin!=='web'||MasterSupport::isSupport()||Auth::isPreview())fail('Alteração de foto não permitida nesta sessão.',403);
  checkCsrf();
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('Selecione uma foto JPEG, PNG ou WebP.',422);
  $size=(int)($file['size']??0);
  if($size<1||$size>3*1024*1024)fail('A foto deve ter até 3 MB.',413);
  $tmp=(string)($file['tmp_name']??'');
  if(!is_uploaded_file($tmp))fail('Upload inválido.',422);
  $info=@getimagesize($tmp);
  if(!$info||($info[0]??0)<16||($info[1]??0)<16||($info[0]??0)>4096||($info[1]??0)>4096)fail('Dimensões de imagem inválidas.',422);
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
  if(!isset(self::MIME_EXT[$mime])||($info['mime']??'')!==$mime)fail('Imagem inválida.',422);
  $dir=self::dir();
  if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))fail('Armazenamento indisponível.',500);
  $id=(int)Auth::$user['id'];$ext=self::MIME_EXT[$mime];
  $temp=$dir.'/upload-'.bin2hex(random_bytes(12)).'.tmp';
  if(!move_uploaded_file($tmp,$temp))fail('Não foi possível enviar a foto.',500);
  @chmod($temp,0640);
  $target=$dir.'/u'.$id.'.'.$ext;
  if(!@rename($temp,$target)){@unlink($temp);fail('Não foi possível salvar a foto.',500);}
  foreach(['jpg','png','webp'] as $other)if($other!==$ext)@unlink($dir.'/u'.$id.'.'.$other);
  clearstatcache(true,$target);
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
