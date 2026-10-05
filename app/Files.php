<?php
final class Files {
 public static function add(array $p): array {
  $d=Auth::demand(integer($p['demand_id'],1),true);$staff=Auth::$user['role']!=='client';$kind=$staff?enumv($p['kind']??'input',['input','reference','review','final'],'tipo'):'input';
  $row=['demand_id'=>$d['id'],'author_id'=>Auth::$user['id'],'label'=>required($p,'label',255),'shared'=>$staff?(int)(bool)($p['shared']??1):1,'kind'=>$kind,'created_at'=>now()];
  if(!empty($p['url'])){$row['url']=urlv($p['url']);$row['storage_provider']='url';}
  elseif(isset($_FILES['file'])){
   $f=$_FILES['file'];if($f['error']!==UPLOAD_ERR_OK)fail('O envio falhou. Verifique o limite de upload da hospedagem.');
   $limitMb=(int)setting('upload_mb','0');$max=$limitMb>0?$limitMb*1024*1024:0;if($max>0&&$f['size']>$max)fail('Arquivo acima do limite configurado.');if($f['size']<1)fail('O arquivo está vazio.');
   $name=basename(str_replace('\\','/',$f['name']));self::uploadExtension($name);
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])?:'application/octet-stream';
   $folder=Google::ensureDemandFileFolder($d,$kind);$drive=Google::uploadToDrive($folder['account_id'],$f['tmp_name'],$name,$mime,$folder['folder_id']);
   $row+=['original_name'=>mb_substr($name,0,255),'disk_name'=>null,'storage_provider'=>'drive','drive_file_id'=>$drive['id'],'drive_account_id'=>$folder['account_id'],'drive_web_view_url'=>$drive['webViewLink']??null,'mime'=>$drive['mimeType']??$mime,'size_bytes'=>(int)($drive['size']??$f['size'])];
  }else fail('Envie um arquivo ou um link.');
  if(in_array($kind,['review','final'])){$row['version_no']=(int)DB::one('SELECT COALESCE(MAX(version_no),0) AS n FROM files WHERE demand_id=?',[$d['id']])['n']+1;if(!$row['shared'])fail('Uma versão para aprovação precisa estar compartilhada.');}
  $id=DB::insert('files',$row);audit('demand',$d['id'],'file.added',null,['file_id'=>$id,'version'=>$row['version_no']??null,'storage'=>$row['storage_provider']??'local']);Notify::demand($d,$kind==='review'?'Versão para aprovação':'Novo arquivo',$row['label'],!$row['shared']);return ['id'=>$id,'version_no'=>$row['version_no']??null,'storage'=>$row['storage_provider']??'local','final_delivery_link'=>$kind==='final'?($folder['share_url']??null):null];
 }

 private static function uploadExtension(string $name): string {
  $name=basename(str_replace('\\','/',$name));if($name===''||$name==='.'||$name==='..'||preg_match('/[\\x00-\\x1F\\x7F]/',$name))fail('Nome de arquivo inválido.');
  return strtolower(pathinfo($name,PATHINFO_EXTENSION));
 }

 private static function finalizeDriveUpload(string $token,array $state,array $drive): array {
  $d=Auth::demand((int)$state['demand_id'],true);$uploadKey=$state['upload_key']??null;
  if($uploadKey){
   $existing=DB::one('SELECT id,version_no,storage_provider,drive_file_id FROM files WHERE demand_id=? AND upload_key=? LIMIT 1',[$d['id'],$uploadKey]);
   if($existing){
    if(!empty($drive['id'])&&($existing['drive_file_id']??'')!==$drive['id'])try{Google::deleteDriveFile((int)$state['account_id'],(string)$drive['id']);}catch(Throwable $e){error_log('TaskEsc duplicate upload cleanup: '.$e->getMessage());}
    unset($_SESSION['drive_uploads'][$token]);$response=['complete'=>true,'id'=>(int)$existing['id'],'version_no'=>$existing['version_no']!==null?(int)$existing['version_no']:null,'storage'=>$existing['storage_provider']??'drive','next_offset'=>(int)$state['size'],'final_delivery_link'=>$state['final_delivery_link']??null,'deduplicated'=>true];$_SESSION['drive_uploads_done'][$token]=$response+['user_id'=>(int)Auth::$user['id'],'expires'=>time()+900];return $response;
   }
  }
  if(empty($drive['id']))fail('O Google Drive não confirmou o arquivo enviado.',502);
  $row=['demand_id'=>$d['id'],'author_id'=>Auth::$user['id'],'label'=>$state['label'],'shared'=>(int)$state['shared'],'kind'=>$state['kind'],'created_at'=>now(),'original_name'=>mb_substr((string)$state['name'],0,255),'disk_name'=>null,'storage_provider'=>'drive','drive_file_id'=>$drive['id'],'drive_account_id'=>(int)$state['account_id'],'drive_web_view_url'=>$drive['webViewLink']??null,'mime'=>$drive['mimeType']??$state['mime'],'size_bytes'=>(int)($drive['size']??$state['size']),'upload_key'=>$uploadKey];
  if(in_array($state['kind'],['review','final'],true))$row['version_no']=(int)DB::one('SELECT COALESCE(MAX(version_no),0) AS n FROM files WHERE demand_id=?',[$d['id']])['n']+1;
  try{$id=DB::insert('files',$row);}
  catch(PDOException $e){
   if(!$uploadKey||!str_contains(strtolower($e->getMessage()),'duplicate'))throw $e;
   $existing=DB::one('SELECT id,version_no,storage_provider,drive_file_id FROM files WHERE upload_key=? LIMIT 1',[$uploadKey]);if(!$existing)throw $e;
   if(!empty($drive['id'])&&($existing['drive_file_id']??'')!==$drive['id'])try{Google::deleteDriveFile((int)$state['account_id'],(string)$drive['id']);}catch(Throwable $cleanup){error_log('TaskEsc duplicate upload cleanup: '.$cleanup->getMessage());}
   unset($_SESSION['drive_uploads'][$token]);$response=['complete'=>true,'id'=>(int)$existing['id'],'version_no'=>$existing['version_no']!==null?(int)$existing['version_no']:null,'storage'=>$existing['storage_provider']??'drive','next_offset'=>(int)$state['size'],'final_delivery_link'=>$state['final_delivery_link']??null,'deduplicated'=>true];$_SESSION['drive_uploads_done'][$token]=$response+['user_id'=>(int)Auth::$user['id'],'expires'=>time()+900];return $response;
  }
  unset($_SESSION['drive_uploads'][$token]);audit('demand',$d['id'],'file.added',null,['file_id'=>$id,'version'=>$row['version_no']??null,'storage'=>'drive']);Notify::demand($d,$state['kind']==='review'?'Versão para aprovação':'Novo arquivo',$row['label'],!$row['shared']);
  $response=['complete'=>true,'id'=>$id,'version_no'=>$row['version_no']??null,'storage'=>'drive','next_offset'=>(int)$state['size'],'final_delivery_link'=>$state['final_delivery_link']??null];$_SESSION['drive_uploads_done'][$token]=$response+['user_id'=>(int)Auth::$user['id'],'expires'=>time()+900];return $response;
 }

 private static function activeUpload(string $token): array {
  if(!preg_match('/^[a-f0-9]{48}$/',$token))fail('Sessão de upload inválida.',400);
  $done=$_SESSION['drive_uploads_done'][$token]??null;if($done&&(int)($done['user_id']??0)===(int)Auth::$user['id']&&(int)($done['expires']??0)>=time()){unset($done['user_id'],$done['expires']);return ['done'=>$done];}
  $state=$_SESSION['drive_uploads'][$token]??null;if(!$state||(int)($state['user_id']??0)!==(int)Auth::$user['id']||(int)($state['expires']??0)<time()){unset($_SESSION['drive_uploads'][$token]);fail('A sessão de upload expirou. Inicie o envio novamente.',409);}
  Auth::demand((int)$state['demand_id']);return ['state'=>$state];
 }

 public static function uploadStatus(array $p): array {
  $token=(string)($p['upload_token']??'');$session=self::activeUpload($token);if(isset($session['done']))return $session['done'];$state=$session['state'];
  $status=Google::driveUploadStatus((int)$state['account_id'],(string)$state['location'],(int)$state['size']);
  if(!empty($status['complete']))return self::finalizeDriveUpload($token,$state,$status['file']??[]);
  $next=max(0,min((int)$state['size'],(int)($status['next_offset']??0)));$_SESSION['drive_uploads'][$token]['offset']=$next;$_SESSION['drive_uploads'][$token]['expires']=time()+518400;
  return ['complete'=>false,'next_offset'=>$next,'size'=>(int)$state['size']];
 }

 public static function uploadStart(array $p): array {
  $d=Auth::demand(integer($p['demand_id']??0,1),true);$staff=Auth::$user['role']!=='client';$kind=$staff?enumv($p['kind']??'input',['input','reference','review','final'],'tipo'):'input';$shared=$staff?(int)(bool)($p['shared']??1):1;
  if(in_array($kind,['review','final'],true)&&!$shared)fail('Uma versão para aprovação precisa estar compartilhada.');
  $label=required($p,'label',255);$name=basename(str_replace('\\','/',required($p,'name',255)));self::uploadExtension($name);
  $rawSize=$p['size']??0;if(filter_var($rawSize,FILTER_VALIDATE_INT)===false||(int)$rawSize<1)fail('Tamanho de arquivo inválido.');$size=(int)$rawSize;$limitMb=(int)setting('upload_mb','0');$max=$limitMb>0?$limitMb*1024*1024:0;if($max>0&&$size>$max)fail('Arquivo acima do limite configurado de '.$limitMb.' MB.');
  $mime=trim((string)($p['mime']??'application/octet-stream'));if($mime===''||strlen($mime)>120||preg_match('/[\r\n]/',$mime))$mime='application/octet-stream';
  $uploadId=trim((string)($p['upload_id']??''));if($uploadId!==''&&!preg_match('/^[A-Za-z0-9_-]{12,96}$/',$uploadId))fail('Identificador de upload inválido.',400);
  $uploadKey=$uploadId!==''?hash('sha256',(string)Auth::$user['id'].'|'.$d['id'].'|'.$uploadId):null;
  foreach($_SESSION['drive_uploads']??[] as $key=>$state)if((int)($state['expires']??0)<time())unset($_SESSION['drive_uploads'][$key]);
  foreach($_SESSION['drive_uploads_done']??[] as $key=>$state)if((int)($state['expires']??0)<time())unset($_SESSION['drive_uploads_done'][$key]);
  if($uploadKey){
   $existing=DB::one('SELECT id,version_no,storage_provider FROM files WHERE demand_id=? AND upload_key=? LIMIT 1',[$d['id'],$uploadKey]);
   if($existing)return ['complete'=>true,'id'=>(int)$existing['id'],'version_no'=>$existing['version_no']!==null?(int)$existing['version_no']:null,'storage'=>$existing['storage_provider']??'drive','next_offset'=>$size,'size'=>$size,'deduplicated'=>true];
   foreach($_SESSION['drive_uploads']??[] as $token=>$state)if(($state['upload_key']??null)===$uploadKey&&(int)($state['user_id']??0)===(int)Auth::$user['id']&&(int)($state['demand_id']??0)===(int)$d['id']){
    return ['upload_token'=>$token,'direct_url'=>(string)$state['location'],'chunk_size'=>(int)($state['chunk_size']??1048576),'next_offset'=>(int)($state['offset']??0),'size'=>$size,'unlimited'=>$limitMb===0,'resumed'=>true];
   }
  }
  $folder=Google::ensureDemandFileFolder($d,$kind);$location=Google::startDriveUpload($folder['account_id'],$name,$mime,$size,$folder['folder_id']);
  $chunkSize=min($size,16*1024*1024);if($size>$chunkSize&&$chunkSize%262144!==0)$chunkSize=(int)(floor($chunkSize/262144)*262144);$chunkSize=max(262144,$chunkSize);
  $token=bin2hex(random_bytes(24));$_SESSION['drive_uploads'][$token]=['user_id'=>(int)Auth::$user['id'],'demand_id'=>(int)$d['id'],'account_id'=>(int)$folder['account_id'],'location'=>$location,'label'=>$label,'name'=>$name,'mime'=>$mime,'size'=>$size,'offset'=>0,'chunk_size'=>$chunkSize,'upload_key'=>$uploadKey,'kind'=>$kind,'shared'=>$shared,'final_delivery_link'=>$kind==='final'?($folder['share_url']??null):null,'expires'=>time()+518400];
  return ['upload_token'=>$token,'direct_url'=>$location,'chunk_size'=>$chunkSize,'next_offset'=>0,'size'=>$size,'unlimited'=>$limitMb===0];
 }

 public static function uploadChunk(string $token,string $binary,int $offset): array {
  if(!preg_match('/^[a-f0-9]{48}$/',$token))fail('Sessão de upload inválida.',400);$done=$_SESSION['drive_uploads_done'][$token]??null;if($done&&(int)($done['user_id']??0)===(int)Auth::$user['id']&&(int)($done['expires']??0)>=time()){unset($done['user_id'],$done['expires']);return $done;}$state=$_SESSION['drive_uploads'][$token]??null;
  if(!$state||(int)$state['user_id']!==(int)Auth::$user['id']||(int)$state['expires']<time()){unset($_SESSION['drive_uploads'][$token]);fail('A sessão de upload expirou. Inicie o envio novamente.',409);}
  if($offset!==(int)$state['offset'])fail('O bloco recebido está fora de ordem. Recomece o upload.',409,['expected_offset'=>(int)$state['offset']]);
  $length=strlen($binary);$chunkLimit=max(262144,(int)($state['chunk_size']??1048576));if($length<1||$length>$chunkLimit)fail('Tamanho de bloco inválido.',413);$isFinal=$offset+$length===(int)$state['size'];if(!$isFinal&&$length%262144!==0)fail('Bloco intermediário inválido.',400);
  Auth::demand((int)$state['demand_id']);
  try{$result=Google::uploadDriveChunk((int)$state['account_id'],(string)$state['location'],$binary,$offset,(int)$state['size'],(string)$state['mime']);}
  catch(Throwable $uploadError){
   try{$status=Google::driveUploadStatus((int)$state['account_id'],(string)$state['location'],(int)$state['size']);if($status['complete'])$result=$status;elseif((int)$status['next_offset']>$offset){$_SESSION['drive_uploads'][$token]['offset']=(int)$status['next_offset'];$_SESSION['drive_uploads'][$token]['expires']=time()+518400;return ['complete'=>false,'next_offset'=>(int)$status['next_offset'],'recovered'=>true];}else throw $uploadError;}
   catch(Throwable $statusError){throw $uploadError;}
  }
  if(!$result['complete']){$_SESSION['drive_uploads'][$token]['offset']=(int)$result['next_offset'];$_SESSION['drive_uploads'][$token]['expires']=time()+518400;return ['complete'=>false,'next_offset'=>(int)$result['next_offset']];}
  return self::finalizeDriveUpload($token,$state,$result['file']??[]);
 }

 public static function delete(array $p): array {
  Auth::staff();$id=integer($p['file_id']??0,1);$f=DB::one('SELECT * FROM files WHERE id=?',[$id]);if(!$f)fail('Anexo não encontrado.',404);$d=Auth::demand((int)$f['demand_id']);
  if(($f['storage_provider']??'local')==='drive'&&!empty($f['drive_file_id'])&&!empty($f['drive_account_id']))Google::deleteDriveFile((int)$f['drive_account_id'],(string)$f['drive_file_id']);
  elseif(($f['storage_provider']??'local')==='local'&&!empty($f['disk_name'])){$path=ROOT.'/storage/uploads/'.basename((string)$f['disk_name']);if(is_file($path)&&!@unlink($path))throw new RuntimeException('Não foi possível remover o arquivo local.');}
  return DB::tx(function() use($id,$f,$d): array {$current=DB::one('SELECT id,demand_id FROM files WHERE id=? FOR UPDATE',[$id]);if(!$current)return ['deleted'=>true,'id'=>$id];if((int)$current['demand_id']!==(int)$d['id'])fail('O anexo mudou de task durante a exclusão.',409);DB::run('UPDATE comments SET version_id=NULL WHERE version_id=?',[$id]);DB::run('DELETE FROM reviews WHERE file_id=?',[$id]);DB::run('DELETE FROM files WHERE id=?',[$id]);audit('demand',(int)$d['id'],'file.deleted',$f,['file_id'=>$id,'storage'=>$f['storage_provider']??'local']);if(!empty($f['shared']))Notify::demand($d,'Arquivo removido',$f['label']??$f['original_name']??$d['title']);return ['deleted'=>true,'id'=>$id];});
 }

 public static function review(array $p): array {
  $f=DB::one('SELECT * FROM files WHERE id=? FOR UPDATE',[integer($p['file_id'],1)]);if(!$f||!$f['version_no'])fail('Versão não encontrada.',404);$d=Auth::demand((int)$f['demand_id'],true);Auth::client((int)$d['client_id'],'approve');if(!$f['shared']&&Auth::$user['role']==='client')fail('Arquivo privado.',403);$decision=enumv($p['decision']??'',['approved','changes'],'decisão');if($f['approval']===$decision)fail('Essa decisão já está registrada.');if($decision==='changes')required($p,'message');DB::update('files',$f['id'],['approval'=>$decision,'approved_by'=>Auth::$user['id'],'approved_at'=>now()]);DB::insert('reviews',['file_id'=>$f['id'],'user_id'=>Auth::$user['id'],'decision'=>$decision,'message'=>$p['message']??'','created_at'=>now()]);$latest=DB::one('SELECT id FROM files WHERE demand_id=? AND version_no IS NOT NULL ORDER BY version_no DESC LIMIT 1',[$d['id']]);if($decision==='changes'&&(int)$latest['id']===(int)$f['id']){$target=DB::one("SELECT * FROM stages WHERE active=1 ORDER BY (LOWER(name) LIKE '%ajuste%') DESC,(kind='production') DESC,position,id LIMIT 1");if(!$target)fail('Crie uma coluna ativa no Kanban para receber os ajustes.',409);DB::update('demands',$d['id'],['stage_id'=>$target['id'],'version'=>$d['version']+1,'updated_at'=>now(),'completed_at'=>null]);DB::insert('transitions',['demand_id'=>$d['id'],'from_stage'=>$d['stage_id'],'to_stage'=>$target['id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]);}audit('demand',$d['id'],'review.'.$decision,null,['file_id'=>$f['id'],'version_no'=>$f['version_no'],'message'=>$p['message']??'']);Notify::demand($d,$decision==='approved'?'Versão aprovada':'Ajustes solicitados',$d['title']);$rounds=(int)DB::one("SELECT COUNT(*) AS n FROM reviews r JOIN files f ON f.id=r.file_id WHERE f.demand_id=? AND r.decision='changes'",[$d['id']])['n'];return ['saved'=>true,'revision_rounds'=>$rounds,'exceeds_included'=>$rounds>(int)$d['revisions_limit']];
 }
}
