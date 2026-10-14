<?php
declare(strict_types=1);

/** Two-message consent protocol, on the existing idempotency table (no schema migration). */
final class AiWhatsAppApproval {
 private static function key(string $token): string {
  $secret=(string)config('app_key','');
  if(strlen($secret)<32)throw new RuntimeException('app_key_missing');
  return 'ai-approval:'.hash_hmac('sha256',strtoupper($token),$secret);
 }
 public static function command(string $body): ?array {
  $body=trim($body);
  if(!preg_match('/\A(CONFIRMAR|CANCELAR) ([A-Fa-f0-9]{12})\z/Diu',$body,$m))return null;
  return ['tool'=>strtoupper($m[1])==='CONFIRMAR'?'_approve_admin':'_cancel_admin',
    'arguments'=>['code'=>strtoupper($m[2])]];
 }
 public static function internal(string $name): bool {
  return in_array($name,['_approve_admin','_cancel_admin'],true);
 }
 private static function privateAdmin(array $row): void {
  Auth::admin();
  if(!AiWhatsAppAdminActions::enabled()&&!AiWhatsAppAgendaTools::writeEnabled())fail('Controle administrativo da IA desativado.',403);
  if(($row['chat_type']??'')!=='private')fail('Confirmações disponíveis somente em conversa privada.',403);
  if(!Auth::can('finance.manage'))fail('Acesso administrativo necessário.',403);
 }
 public static function propose(string $action,array $args,array $row): array {
  self::privateAdmin($row);
  [$clean,$summary]=AiWhatsAppAdminActions::prepare($action,$args);
  $code=strtoupper(bin2hex(random_bytes(6)));
  $state=['status'=>'pending','actor'=>(int)Auth::$user['id'],'chat'=>(string)$row['chat_id'],
   'source_message'=>(int)$row['id'],'action'=>$action,'arguments'=>$clean,
   'expires_at'=>gmdate('Y-m-d H:i:s',time()+600)];
  // A unique message id makes reconnection and retries idempotent; token hash only in DB.
  $sourceKey='ai-source:'.(int)$row['id'];
  $hash=hash('sha256',json([$action,$clean,$row['chat_id'],Auth::$user['id']]));
  $existing=DB::one('SELECT result_json FROM idempotency WHERE user_id=? AND operation_key=? FOR UPDATE',[(int)Auth::$user['id'],$sourceKey]);
  if($existing)fail('Este pedido já foi preparado anteriormente; não será repetido.',409);
  DB::insert('idempotency',['user_id'=>Auth::$user['id'],'operation_key'=>$sourceKey,'request_hash'=>$hash,'result_json'=>json(['token_key'=>self::key($code),'status'=>'issued']),'created_at'=>now()]);
  DB::insert('idempotency',['user_id'=>Auth::$user['id'],'operation_key'=>self::key($code),'request_hash'=>$hash,'result_json'=>json($state),'created_at'=>now()]);
  audit('ai_approval',(int)Auth::$user['id'],'proposed',null,['action'=>$action,'source_message'=>$row['id']]);
  return ['kind'=>'approval_pending','summary'=>$summary,'code'=>$code,'minutes'=>10];
 }
 /** Must be called in SAME database transaction as the business operation. */
 public static function decide(string $tool,array $args,array $row): array {
  self::privateAdmin($row);
  if(!self::internal($tool))fail('Comando de aprovação desconhecido.',403);
  $code=(string)($args['code']??'');
  if(!preg_match('/\A[A-F0-9]{12}\z/D',$code))fail('Código de confirmação inválido.',422);
  $operationKey=self::key($code);$userId=(int)Auth::$user['id'];
  $saved=DB::one('SELECT result_json FROM idempotency WHERE user_id=? AND operation_key=? FOR UPDATE',[$userId,$operationKey]);
  if(!$saved)fail('Confirmação não encontrada ou pertencente a outro usuário.',404);
  $state=decode($saved['result_json'],[]);
  if((int)($state['actor']??0)!==$userId||($state['chat']??'')!==(string)$row['chat_id'])fail('Confirmação vinculada a outro usuário ou conversa.',403);
  if(($state['status']??'')!=='pending')return ['kind'=>'approval_status','message'=>'Esta solicitação já foi '.($state['status']??'processada').'.'];
  if((string)$state['expires_at']<gmdate('Y-m-d H:i:s')){
   $state['status']='expired';DB::run('UPDATE idempotency SET result_json=? WHERE user_id=? AND operation_key=?',[json($state),$userId,$operationKey]);
   return ['kind'=>'approval_status','message'=>'Código expirado. Solicite novamente a operação.'];
  }
  if($tool==='_cancel_admin'){
   $state['status']='cancelled';$state['confirmed_message']=(int)$row['id'];unset($state['arguments']);
   DB::run('UPDATE idempotency SET result_json=? WHERE user_id=? AND operation_key=?',[json($state),$userId,$operationKey]);
   audit('ai_approval',$userId,'cancelled',null,['source_message'=>$state['source_message']]);
   return ['kind'=>'approval_status','message'=>'Operação cancelada; nenhum lançamento foi executado.'];
  }
  // Recheck permissions and invariants inside transaction. Any AppError rolls
  // back this approval AND the business mutation, so reapproval is explicit.
  $result=AiWhatsAppAdminActions::run((string)$state['action'],(array)$state['arguments']);
  $state['status']='executed';$state['confirmed_message']=(int)$row['id'];$state['result']=$result;unset($state['arguments']);
  DB::run('UPDATE idempotency SET result_json=? WHERE user_id=? AND operation_key=?',[json($state),$userId,$operationKey]);
  audit('ai_approval',$userId,'executed',null,['action'=>$state['action'],'result'=>$result,'source_message'=>$state['source_message']]);
  return $result;
 }
}