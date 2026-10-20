<?php
final class Files {
 private static function ci(): bool {return WorkspaceContext::legacyCiFixture();}
 private static function where(string $alias=''): string {return self::ci()?'':' AND '.($alias!==''?$alias.'.':'').'workspace_id=?';}
 private static function args(array $params): array {return self::ci()?$params:[...$params,Auth::workspaceId()];}
 private static function row(array $v): array {return self::ci()?$v:($v+['workspace_id'=>Auth::workspaceId()]);}
 public static function add(array $p): array {
  $d=Auth::demand(integer($p['demand_id'],1),true);$staff=Auth::$user['role']!=='client';$kind=$staff?enumv($p['kind']??'input',['input','reference','review','final'],'tipo'):'input';$finalLink=null;
  $row=['demand_id'=>$d['id'],'author_id'=>Auth::$user['id'],'label'=>required($p,'label',255),'shared'=>$staff?(int)(bool)($p['shared']??1):1,'kind'=>$kind,'created_at'=>now()];
  if(!empty($p['url'])){$row['url']=urlv($p['url']);$row['storage_provider']='url';}
  elseif(isset($_FILES['file'])){
   $f=$_FILES['file'];if($f['error']!==UPLOAD_ERR_OK)fail('O envio falhou. Verifique o limite de upload da hospedagem.');
   $limitMb=(int)setting('upload_mb','0');$max=$limitMb>0?$limitMb*1024*1024:0;if($max>0&&$f['size']>$max)fail('Arquivo acima do limite configurado.');if($f['size']<1)fail('O arquivo está vazio.');
   $name=basename(str_replace('\\','/',$f['name']));self::uploadExtension($name);
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])?:'application/octet-stream';
   $folder=Google::ensureDemandFileFolder($d,$kind);$drive=Google::uploadToDrive($folder['account_id'],$f['tmp_name'],$name,$mime,$folder['folder_id']);$finalLink=$kind==='final'?($folder['share_url']??null):null;
   $row+=['original_name'=>mb_substr($name,0,255),'disk_name'=>null,'storage_provider'=>'drive','drive_file_id'=>$drive['id'],'drive_account_id'=>$folder['account_id'],'drive_web_view_url'=>$drive['webViewLink']??null,'mime'=>$drive['mimeType']??$mime,'size_bytes'=>(int)($drive['size']??$f['size'])];
  }else fail('Envie um arquivo ou um link.');
  if(in_array($kind,['review','final'],true)&&!$row['shared'])fail('Uma versão para aprovação precisa estar compartilhada.');
  return DB::tx(function() use($d,$kind,$row,$finalLink): array {
   Auth::demand((int)$d['id'],true);$save=$row;if(in_array($kind,['review','final'],true))$save['version_no']=(int)DB::one('SELECT COALESCE(MAX(version_no),0) AS n FROM files WHERE demand_id=?'.self::where(),self::args([$d['id']]))['n']+1;
   $id=DB::insert('files',self::row($save));audit('demand',$d['id'],'file.added',null,['file_id'=>$id,'version'=>$save['version_no']??null,'storage'=>$save['storage_provider']??'local']);Notify::demand($d,$kind==='review'?'Versão para aprovação':'Novo arquivo',$save['label'],!$save['shared']);
   DemandRealtime::emit('demand.file_added',$d,['change'=>'file.added','file_id'=>$id,'version_no'=>$save['version_no']??null,'shared'=>(int)$save['shared']],(int)Auth::$user['id'],[],!$save['shared']?'staff':'all');
   if($kind==='final')DemandRealtime::emit('demand.final_delivered',$d,['change'=>'final.delivered','file_id'=>$id,'version_no'=>$save['version_no']??null,'shared'=>(int)$save['shared']],(int)Auth::$user['id']);
   return ['id'=>$id,'version_no'=>$save['version_no']??null,'storage'=>$save['storage_provider']??'local','final_delivery_link'=>$finalLink];
  });
 }

 private static function uploadExtension(string $name): string {
  $name=basename(str_replace('\\','/',$name));if($name===''||$name==='.'||$name==='..'||preg_match('/[\\x00-\\x1F\\x7F]/',$name))fail('Nome de arquivo inválido.');
  return strtolower(pathinfo($name,PATHINFO_EXTENSION));
 }

 /**
  * Only the transaction that persists the last missing final file may emit the
  * final-delivered event. The idempotent upload keys are already unique in files.
  * A locking read sees the latest committed files even under REPEATABLE READ.
  */
 private static function finalDeliveryBatchReady(array $state,array $d): bool {
  $batchId=(string)($state['batch_id']??'');
  if($batchId==='')return true; // Legacy single-file/API uploads.
  $total=(int)($state['batch_total']??0);
  if($total<1||$total>500)throw new LogicException('Invalid final delivery batch metadata.');
  $keys=[];$userId=(int)Auth::$user['id'];$demandId=(int)$d['id'];
  for($index=0;$index<$total;$index++)$keys[]=hash('sha256',$userId.'|'.$demandId.'|'.$batchId.'-'.$index);
  $placeholders=implode(',',array_fill(0,count($keys),'?'));
  $files=DB::all('SELECT upload_key,kind FROM files WHERE demand_id=? AND upload_key IN ('.$placeholders.') FOR UPDATE',[$demandId,...$keys]);
  if(count($files)!==$total)return false;
  foreach($files as $file)if(($file['kind']??'')!=='final')fail('O lote contém um arquivo que não é entrega final.',409);
  return true;
 }

 private static function finalizeDriveUpload(string $token,array $state,array $drive): array {
  $d=Auth::demand((int)$state['demand_id'],true);$uploadKey=$state['upload_key']??null;
  if($uploadKey){
   $existing=DB::one('SELECT id,version_no,storage_provider,drive_file_id FROM files WHERE demand_id=? AND upload_key=?'.self::where().' LIMIT 1',self::args([$d['id'],$uploadKey]));
   if($existing){
    if(!empty($drive['id'])&&($existing['drive_file_id']??'')!==$drive['id'])try{Google::deleteDriveFile((int)$state['account_id'],(string)$drive['id']);}catch(Throwable $e){error_log('TaskEsc duplicate upload cleanup: '.$e->getMessage());}
    unset($_SESSION['drive_uploads'][$token]);$response=['complete'=>true,'id'=>(int)$existing['id'],'version_no'=>$existing['version_no']!==null?(int)$existing['version_no']:null,'storage'=>$existing['storage_provider']??'drive','next_offset'=>(int)$state['size'],'final_delivery_link'=>$state['final_delivery_link']??null,'deduplicated'=>true];$_SESSION['drive_uploads_done'][$token]=$response+['user_id'=>(int)Auth::$user['id'],'expires'=>time()+900];return $response;
   }
  }
  if(empty($drive['id']))fail('O Google Drive não confirmou o arquivo enviado.',502);
  $result=DB::tx(function() use($d,$state,$drive,$uploadKey): array {
   Auth::demand((int)$d['id'],true);
   // Serializes simultaneous finalizations for the same task, including uploads from other tabs.
   DB::one('SELECT id FROM demands WHERE id=?'.self::where().' FOR UPDATE',self::args([(int)$d['id']]));
   if($uploadKey){
    $existing=DB::one('SELECT id,version_no,storage_provider,drive_file_id FROM files WHERE demand_id=? AND upload_key=?'.self::where().' LIMIT 1 FOR UPDATE',self::args([$d['id'],$uploadKey]));
    if($existing)return ['existing'=>$existing];
   }
   $row=['demand_id'=>$d['id'],'author_id'=>Auth::$user['id'],'label'=>$state['label'],'shared'=>(int)$state['shared'],'kind'=>$state['kind'],'created_at'=>now(),'original_name'=>mb_substr((string)$state['name'],0,255),'disk_name'=>null,'storage_provider'=>'drive','drive_file_id'=>$drive['id'],'drive_account_id'=>(int)$state['account_id'],'drive_web_view_url'=>$drive['webViewLink']??null,'mime'=>$drive['mimeType']??$state['mime'],'size_bytes'=>(int)($drive['size']??$state['size']),'upload_key'=>$uploadKey];
   if(in_array($state['kind'],['review','final'],true))$row['version_no']=(int)DB::one('SELECT COALESCE(MAX(version_no),0) AS n FROM files WHERE demand_id=?'.self::where(),self::args([$d['id']]))['n']+1;
   try{$id=DB::insert('files',self::row($row));}
   catch(PDOException $e){
    if(!$uploadKey||!str_contains(strtolower($e->getMessage()),'duplicate'))throw $e;
    $existing=DB::one('SELECT id,version_no,storage_provider,drive_file_id FROM files WHERE upload_key=?'.self::where().' LIMIT 1',self::args([$uploadKey]));if(!$existing)throw $e;return ['existing'=>$existing];
   }
   audit('demand',$d['id'],'file.added',null,['file_id'=>$id,'version'=>$row['version_no']??null,'storage'=>'drive']);Notify::demand($d,$state['kind']==='review'?'Versão para aprovação':'Novo arquivo',$row['label'],!$row['shared']);
   DemandRealtime::emit('demand.file_added',$d,['change'=>'file.added','file_id'=>$id,'version_no'=>$row['version_no']??null,'shared'=>(int)$row['shared']],(int)Auth::$user['id'],[],!$row['shared']?'staff':'all');
   if($state['kind']==='final'&&self::finalDeliveryBatchReady($state,$d))DemandRealtime::emit('demand.final_delivered',$d,['change'=>'final.delivered','file_id'=>$id,'version_no'=>$row['version_no']??null,'shared'=>(int)$row['shared']],(int)Auth::$user['id']);
   return ['id'=>$id,'version_no'=>$row['version_no']??null,'storage'=>'drive'];
  });
  if(isset($result['existing'])){
   $existing=$result['existing'];if(!empty($drive['id'])&&($existing['drive_file_id']??'')!==$drive['id'])try{Google::deleteDriveFile((int)$state['account_id'],(string)$drive['id']);}catch(Throwable $cleanup){error_log('TaskEsc duplicate upload cleanup: '.$cleanup->getMessage());}
   $response=['complete'=>true,'id'=>(int)$existing['id'],'version_no'=>$existing['version_no']!==null?(int)$existing['version_no']:null,'storage'=>$existing['storage_provider']??'drive','next_offset'=>(int)$state['size'],'final_delivery_link'=>$state['final_delivery_link']??null,'deduplicated'=>true];
  }else $response=['complete'=>true,'id'=>(int)$result['id'],'version_no'=>$result['version_no']??null,'storage'=>'drive','next_offset'=>(int)$state['size'],'final_delivery_link'=>$state['final_delivery_link']??null];
  unset($_SESSION['drive_uploads'][$token]);$_SESSION['drive_uploads_done'][$token]=$response+['user_id'=>(int)Auth::$user['id'],'expires'=>time()+900];return $response;
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
  $batchId=trim((string)($p['batch_id']??''));$batchTotal=0;
  if($batchId!==''){
   if($kind!=='final'||!preg_match('/^[A-Za-z0-9_-]{16,80}$/',$batchId))fail('Lote de entrega final inválido.',400);
   $batchTotal=integer($p['batch_total']??0,1,500);$batchIndex=integer($p['batch_index']??-1,0,$batchTotal-1);
   if($uploadId!==$batchId.'-'.$batchIndex)fail('Arquivo não pertence ao lote indicado.',409);
  }elseif(isset($p['batch_total'])||isset($p['batch_index']))fail('Lote incompleto.',400);
  $uploadKey=$uploadId!==''?hash('sha256',(string)Auth::$user['id'].'|'.$d['id'].'|'.$uploadId):null;
  foreach($_SESSION['drive_uploads']??[] as $key=>$state)if((int)($state['expires']??0)<time())unset($_SESSION['drive_uploads'][$key]);
  foreach($_SESSION['drive_uploads_done']??[] as $key=>$state)if((int)($state['expires']??0)<time())unset($_SESSION['drive_uploads_done'][$key]);
  if($uploadKey){
   $existing=DB::one('SELECT id,version_no,storage_provider FROM files WHERE demand_id=? AND upload_key=?'.self::where().' LIMIT 1',self::args([$d['id'],$uploadKey]));
   if($existing)return ['complete'=>true,'id'=>(int)$existing['id'],'version_no'=>$existing['version_no']!==null?(int)$existing['version_no']:null,'storage'=>$existing['storage_provider']??'drive','next_offset'=>$size,'size'=>$size,'deduplicated'=>true];
   foreach($_SESSION['drive_uploads']??[] as $token=>$state)if(($state['upload_key']??null)===$uploadKey&&(int)($state['user_id']??0)===(int)Auth::$user['id']&&(int)($state['demand_id']??0)===(int)$d['id']){
    return ['upload_token'=>$token,'direct_url'=>(string)$state['location'],'chunk_size'=>(int)($state['chunk_size']??1048576),'next_offset'=>(int)($state['offset']??0),'size'=>$size,'unlimited'=>$limitMb===0,'resumed'=>true];
   }
  }
  $folder=Google::ensureDemandFileFolder($d,$kind);$location=Google::startDriveUpload($folder['account_id'],$name,$mime,$size,$folder['folder_id']);
  $chunkSize=min($size,16*1024*1024);if($size>$chunkSize&&$chunkSize%262144!==0)$chunkSize=(int)(floor($chunkSize/262144)*262144);$chunkSize=max(262144,$chunkSize);
  $token=bin2hex(random_bytes(24));$_SESSION['drive_uploads'][$token]=['user_id'=>(int)Auth::$user['id'],'demand_id'=>(int)$d['id'],'account_id'=>(int)$folder['account_id'],'location'=>$location,'label'=>$label,'name'=>$name,'mime'=>$mime,'size'=>$size,'offset'=>0,'chunk_size'=>$chunkSize,'upload_key'=>$uploadKey,'batch_id'=>$batchId,'batch_total'=>$batchTotal,'kind'=>$kind,'shared'=>$shared,'final_delivery_link'=>$kind==='final'?($folder['share_url']??null):null,'expires'=>time()+518400];
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
  Auth::staff();$id=integer($p['file_id']??0,1);
  $f=DB::one('SELECT * FROM files WHERE id=?'.self::where(),self::args([$id]));
  if(!$f)fail('Anexo não encontrado.',404);
  $d=Auth::demand((int)$f['demand_id'],true);
  $isDrive=($f['storage_provider']??'local')==='drive';
  // Somente por opção explícita: remove a referência, mantendo o arquivo no Drive.
  $keepDrive=$isDrive&&((int)($p['keep_drive']??0)===1);
  if(!$keepDrive&&$isDrive&&!empty($f['drive_file_id'])&&!empty($f['drive_account_id'])){
   try{Google::deleteDriveFile((int)$f['drive_account_id'],(string)$f['drive_file_id']);}
   catch(AppError $e){
    $googleStatus=(int)($e->details['google_status']??0);
    $requiresReconnect=$googleStatus===401||Google::refreshErrorCategory($e)==='reconnect'||preg_match('/expired or revoked|token.*expir|revogad/i',$e->getMessage());
    if($requiresReconnect)fail('O acesso ao Google Drive expirou ou foi revogado. Reconecte a conta em Configurações > Meu Google Drive para apagar também no Drive, ou remova somente o registro no TaskEsc.',409,['requires_google_reconnect'=>true,'can_keep_drive'=>true]);
    if($googleStatus===403)fail('O Google Drive recusou a exclusão. Confirme a conta e as permissões de edição, ou remova somente o registro no TaskEsc.',409,['can_keep_drive'=>true]);
    throw $e;
   }catch(RuntimeException $e){
    if(str_contains(mb_strtolower($e->getMessage()),'desconectad'))fail('A conta Google está desconectada. Reconecte-a em Configurações > Meu Google Drive ou remova somente o registro no TaskEsc.',409,['requires_google_reconnect'=>true,'can_keep_drive'=>true]);
    throw $e;
   }
  }elseif(($f['storage_provider']??'local')==='local'&&!empty($f['disk_name'])){
   $path=ROOT.'/storage/uploads/'.basename((string)$f['disk_name']);
   if(is_file($path)&&!@unlink($path))throw new RuntimeException('Não foi possível remover o arquivo local.');
  }
  return DB::tx(function() use($id,$f,$d,$keepDrive): array {
   $current=DB::one('SELECT id,demand_id FROM files WHERE id=?'.self::where().' FOR UPDATE',self::args([$id]));
   if(!$current)return ['deleted'=>true,'id'=>$id,'drive_preserved'=>$keepDrive];
   if((int)$current['demand_id']!==(int)$d['id'])fail('O anexo mudou de task durante a exclusão.',409);
   DB::run('UPDATE comments SET version_id=NULL WHERE version_id=?'.self::where(),self::args([$id]));
   DB::run('DELETE FROM reviews WHERE file_id=?'.self::where(),self::args([$id]));
   DB::run('DELETE FROM files WHERE id=?'.self::where(),self::args([$id]));
   audit('demand',(int)$d['id'],'file.deleted',$f,['file_id'=>$id,'storage'=>$f['storage_provider']??'local','drive_preserved'=>$keepDrive]);
   if(!empty($f['shared']))Notify::demand($d,$keepDrive?'Vínculo do arquivo removido':'Arquivo removido',$f['label']??$f['original_name']??$d['title']);
   DemandRealtime::emit('demand.file_deleted',$d,['change'=>'file.deleted','file_id'=>$id,'version_no'=>$f['version_no']??null,'shared'=>(int)($f['shared']??0),'drive_preserved'=>$keepDrive],(int)Auth::$user['id'],[],empty($f['shared'])?'staff':'all');
   return ['deleted'=>true,'id'=>$id,'drive_preserved'=>$keepDrive];
  });
 }

 public static function review(array $p): array {
  return DB::tx(function() use($p): array {
   $f=DB::one('SELECT * FROM files WHERE id=?'.self::where().' FOR UPDATE',self::args([integer($p['file_id'],1)]));if(!$f||!$f['version_no'])fail('Versão não encontrada.',404);$d=Auth::demand((int)$f['demand_id'],true);Auth::client((int)$d['client_id'],'approve');if(!$f['shared']&&Auth::$user['role']==='client')fail('Arquivo privado.',403);$decision=enumv($p['decision']??'',['approved','changes'],'decisão');if($f['approval']===$decision)fail('Essa decisão já está registrada.');if($decision==='changes')required($p,'message');DB::update('files',$f['id'],['approval'=>$decision,'approved_by'=>Auth::$user['id'],'approved_at'=>now()]);DB::insert('reviews',self::row(['file_id'=>$f['id'],'user_id'=>Auth::$user['id'],'decision'=>$decision,'message'=>$p['message']??'','created_at'=>now()]));$latest=DB::one('SELECT id FROM files WHERE demand_id=? AND version_no IS NOT NULL'.self::where().' ORDER BY version_no DESC LIMIT 1',self::args([$d['id']]));$moved=false;if($decision==='changes'&&(int)$latest['id']===(int)$f['id']){$target=DB::one("SELECT * FROM stages WHERE active=1".self::where()." ORDER BY (LOWER(name) LIKE '%ajuste%') DESC,(kind='production') DESC,position,id LIMIT 1",self::args([]));if(!$target)fail('Crie uma coluna ativa no Kanban para receber os ajustes.',409);DB::update('demands',$d['id'],['stage_id'=>$target['id'],'version'=>$d['version']+1,'updated_at'=>now(),'completed_at'=>null]);DB::insert('transitions',self::row(['demand_id'=>$d['id'],'from_stage'=>$d['stage_id'],'to_stage'=>$target['id'],'actor_id'=>Auth::$user['id'],'happened_at'=>now()]));$moved=true;}audit('demand',$d['id'],'review.'.$decision,null,['file_id'=>$f['id'],'version_no'=>$f['version_no'],'message'=>$p['message']??'']);Notify::demand($d,$decision==='approved'?'Versão aprovada':'Ajustes solicitados',$d['title']);$fresh=$moved?(DB::one('SELECT * FROM demands WHERE id=?',[$d['id']])?:$d):$d;DemandRealtime::emit('demand.file_reviewed',$fresh,['change'=>'file.reviewed','file_id'=>(int)$f['id'],'version_no'=>(int)$f['version_no'],'approval'=>$decision,'decision'=>$decision],(int)Auth::$user['id']);$rounds=(int)DB::one("SELECT COUNT(*) AS n FROM reviews r JOIN files f ON f.id=r.file_id WHERE f.demand_id=? AND r.decision='changes'",[$d['id']])['n'];return ['saved'=>true,'revision_rounds'=>$rounds,'exceeds_included'=>$rounds>(int)$d['revisions_limit']];
  });
 }
}
