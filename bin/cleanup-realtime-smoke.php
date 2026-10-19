<?php
declare(strict_types=1);
/** Safe cleanup of smoke-test users. Default is dry-run; --apply deletes after a full backup. */
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI !== 'cli') {fwrite(STDERR,"Somente CLI.\n");exit(2);}
$apply=in_array('--apply',$argv,true);
$users=DB::all("SELECT id,name,email,role FROM users WHERE BINARY name='Realtime Smoke' ORDER BY id");
$targets=array_values(array_filter($users,static fn(array $u):bool =>
 preg_match('/\Arealtime-smoke-[a-f0-9]+@taskesc\.invalid\z/iD',(string)$u['email'])===1 && $u['role']==='staff'));
if(count($targets)>20)throw new RuntimeException('Quantidade inesperada. Limpeza cancelada.');
$safeLinks=['user_clients'=>true,'workspace_members'=>true,'team_invitations'=>true,'api_tokens'=>true,
 'push_subscriptions'=>true,'push_delivery_log'=>true,'notifications'=>true];
$refs=DB::all("SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users'
 AND TABLE_NAME<>'users' ORDER BY TABLE_NAME,COLUMN_NAME");
$report=['mode'=>$apply?'apply':'dry-run','matched'=>count($targets),'deleted'=>[],'blocked'=>[]];
foreach($targets as $u) {
 $id=(int)$u['id'];$blocked=[];$deletions=[];
 foreach($refs as $ref) {
  $table=(string)$ref['table_name'];$column=(string)$ref['column_name'];
  if(!preg_match('/\A[a-zA-Z0-9_]+\z/D',$table)||!preg_match('/\A[a-zA-Z0-9_]+\z/D',$column))
   throw new RuntimeException('Referência SQL inesperada.');
  $n=(int)(DB::one("SELECT COUNT(*) AS n FROM `{$table}` WHERE `{$column}`=?",[$id])['n']??0);
  if($n<1)continue;
  if(isset($safeLinks[$table]))$deletions[]=[$table,$column];
  else $blocked[]=$table.'.'.$column.' ('.$n.')';
 }
 foreach([['demands','assigned_to'],['demands','created_by'],['files','author_id'],['audit_log','user_id']] as [$table,$column]){
  $exists=DB::one("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",[$table,$column]);
  if(!$exists)continue;
  $n=(int)(DB::one("SELECT COUNT(*) AS n FROM `{$table}` WHERE `{$column}`=?",[$id])['n']??0);
  if($n>0)$blocked[]=$table.'.'.$column.' ('.$n.')';
 }
 $blocked=array_values(array_unique($blocked));
 if($blocked){$report['blocked'][]=['id'=>$id,'reason'=>$blocked];continue;}
 if(!$apply){$report['deleted'][]=['id'=>$id,'email'=>$u['email'],'pending'=>true];continue;}
 DB::tx(static function()use($id,$deletions):void {
  $u=DB::one('SELECT id,name,email,role FROM users WHERE id=? FOR UPDATE',[$id]);
  if(!$u||$u['name']!=='Realtime Smoke'||$u['role']!=='staff'||
   preg_match('/\Arealtime-smoke-[a-f0-9]+@taskesc\.invalid\z/iD',(string)$u['email'])!==1)
   throw new RuntimeException('Conta mudou durante a limpeza.');
  foreach($deletions as [$table,$column])DB::run("DELETE FROM `{$table}` WHERE `{$column}`=?",[$id]);
  DB::run('DELETE FROM users WHERE id=?',[$id]);
 });
 $report['deleted'][]=['id'=>$id,'email'=>$u['email']];
}
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
if($report['blocked'])exit(1);
