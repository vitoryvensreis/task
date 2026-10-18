<?php
declare(strict_types=1);

/**
 * Isolated support threads: an administrator only sees their own exchange
 * with the platform Master. Master can reply without impersonation.
 */
final class SupportDesk {
 private static function actor(): array {
  Auth::load();
  if(Auth::$origin!=='web'||Auth::isPreview())fail('Suporte disponível somente na sessão web.',403);
  if(MasterSupport::isActor())return ['master'=>true,'id'=>(int)Auth::$actor['id']];
  if((Auth::$user['role']??'')!=='admin'||(int)(Auth::$workspace['id']??0)<1)
   fail('Somente administradores podem utilizar o suporte.',403);
  return ['master'=>false,'id'=>(int)Auth::$user['id']];
 }

 private static function target(array $input,array $actor): array {
  if((int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
   fail('O atendimento está temporariamente limitado ao workspace homologado.',503);
  if($actor['master']){
   $workspace=integer($input['workspace_id']??0,1);
   $tenant=integer($input['tenant_user_id']??0,1);
  }else{
   $workspace=Auth::workspaceId();
   $tenant=$actor['id'];
  }
  $row=DB::one("SELECT w.id,w.name,u.id AS tenant_user_id,u.name AS tenant_name,u.email AS tenant_email
    FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id
    JOIN users u ON u.id=wm.user_id
    WHERE w.id=? AND w.slug=? AND w.status=?
      AND wm.user_id=? AND wm.role IN ('owner','admin') AND wm.status='active'
      AND u.active=1 AND u.role='admin'",
    [$workspace,WorkspaceFoundation::SLUG,WorkspaceFoundation::STATUS,$tenant]);
  if(!$row)fail('Conversa de suporte não encontrada.',404);
  return $row;
 }

 public static function threads(): array {
  $actor=self::actor();
  $args=[];$where="WHERE w.slug=? AND w.status=? AND wm.role IN ('owner','admin')
    AND wm.status='active' AND u.active=1 AND u.role='admin'";
  $args=[WorkspaceFoundation::SLUG,WorkspaceFoundation::STATUS];
  if(!$actor['master']){$where.=" AND u.id=?";$args[]=$actor['id'];}
  return DB::all("SELECT w.id AS workspace_id,w.name AS workspace_name,u.id AS tenant_user_id,
     u.name AS tenant_name,u.email AS tenant_email,
     (SELECT m.body FROM workspace_support_messages m WHERE m.workspace_id=w.id
        AND m.tenant_user_id=u.id ORDER BY m.id DESC LIMIT 1) AS last_body,
     (SELECT MAX(m.created_at) FROM workspace_support_messages m WHERE m.workspace_id=w.id
        AND m.tenant_user_id=u.id) AS last_at
    FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id
    JOIN users u ON u.id=wm.user_id $where
    ORDER BY last_at DESC,u.name",$args);
 }

 private static function messagesFor(array $target): array {
  $rows=DB::all("SELECT m.id,m.sender_user_id,m.sender_role,m.body,m.created_at,
      m.file_mime,u.name AS sender_name
    FROM workspace_support_messages m JOIN users u ON u.id=m.sender_user_id
    WHERE m.workspace_id=? AND m.tenant_user_id=?
    ORDER BY m.id DESC LIMIT 80",
    [(int)$target['id'],(int)$target['tenant_user_id']]);
  $rows=array_reverse($rows);
  foreach($rows as &$row){
   $row['image_url']=$row['file_mime']?base('support-image.php?id='.(int)$row['id']):null;
   unset($row['file_mime']);
  }
  unset($row);
  return $rows;
 }

 public static function messages(array $p): array {
  $actor=self::actor();$target=self::target($p,$actor);
  return ['thread'=>['workspace_id'=>(int)$target['id'],
      'workspace_name'=>$target['name'],'tenant_user_id'=>(int)$target['tenant_user_id'],
      'tenant_name'=>$target['tenant_name']],
    'messages'=>self::messagesFor($target)];
 }

 public static function send(array $p,?array $image=null): array {
  $actor=self::actor();$target=self::target($p,$actor);
  Auth::rate('support-message',30,300);
  $body=trim((string)($p['body']??''));
  if(mb_strlen($body)>4000)fail('A mensagem deve ter até 4.000 caracteres.',422);
  $nonce=trim((string)($p['nonce']??''));
  if(!preg_match('/\A[a-f0-9]{32}\z/D',$nonce))fail('Identificador de envio inválido.',422);
  $attached=$image&&($image['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
  if($body===''&&!$attached)fail('Escreva uma mensagem ou selecione uma imagem.',422);
  $previous=DB::one('SELECT id FROM workspace_support_messages WHERE workspace_id=?
    AND tenant_user_id=? AND sender_user_id=? AND client_nonce=? LIMIT 1',
    [(int)$target['id'],(int)$target['tenant_user_id'],$actor['id'],$nonce]);
  if($previous)return ['sent'=>true,'duplicate'=>true,'id'=>(int)$previous['id']];

  $filename=null;$mime=null;
  if($attached){
   if(($image['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||(int)($image['size']??0)>5*1024*1024||(int)($image['size']??0)<1)
    fail('A imagem deve ter no máximo 5 MB.',422);
   $tmp=(string)($image['tmp_name']??'');
   if(!is_uploaded_file($tmp))fail('Anexo inválido.',422);
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';
   $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
   if(!isset($types[$mime])||getimagesize($tmp)===false)
    fail('Envie uma imagem JPG, PNG, WEBP ou GIF válida.',422);
   $dir=ROOT.'/storage/support';
   if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))fail('Armazenamento indisponível.',500);
   $filename=bin2hex(random_bytes(18)).'.'.$types[$mime];
   if(!move_uploaded_file($tmp,$dir.'/'.$filename))fail('Não foi possível guardar a imagem.',500);
   @chmod($dir.'/'.$filename,0600);
  }
  try{
   $id=DB::insert('workspace_support_messages',[
    'workspace_id'=>(int)$target['id'],'tenant_user_id'=>(int)$target['tenant_user_id'],
    'sender_user_id'=>$actor['id'],'sender_role'=>$actor['master']?'master':'tenant',
    'client_nonce'=>$nonce,'body'=>$body,'file_name'=>$filename,'file_mime'=>$mime,'created_at'=>now()
   ]);
  }catch(Throwable $error){
   if($filename)@unlink(ROOT.'/storage/support/'.$filename);
   $duplicate=DB::one('SELECT id FROM workspace_support_messages WHERE workspace_id=? AND tenant_user_id=?
     AND sender_user_id=? AND client_nonce=?',
     [(int)$target['id'],(int)$target['tenant_user_id'],$actor['id'],$nonce]);
   if($duplicate)return ['sent'=>true,'duplicate'=>true,'id'=>(int)$duplicate['id']];
   throw $error;
  }
  return ['sent'=>true,'id'=>$id];
 }

 public static function serveImage(array $p): never {
  $actor=self::actor();$id=integer($p['id']??0,1);
  $msg=DB::one('SELECT workspace_id,tenant_user_id,file_name,file_mime
    FROM workspace_support_messages WHERE id=?',[$id]);
  if(!$msg||!$msg['file_name'])fail('Imagem indisponível.',404);
  self::target(['workspace_id'=>$msg['workspace_id'],'tenant_user_id'=>$msg['tenant_user_id']],$actor);
  $name=(string)$msg['file_name'];
  if(!preg_match('/\A[a-f0-9]{36}\.(png|jpg|webp|gif)\z/D',$name))fail('Imagem inválida.',404);
  $path=ROOT.'/storage/support/'.$name;
  if(!is_file($path))fail('Imagem não encontrada.',404);
  header('Content-Type: '.(string)$msg['file_mime']);
  header('Content-Disposition: inline; filename="imagem-suporte.'.pathinfo($name,PATHINFO_EXTENSION).'"');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, no-store');
  readfile($path);exit;
 }
}
