<?php
declare(strict_types=1);
/**
 * Platform-only Asaas billing. Never uses a tenant's finance tables and never
 * charges the two legacy unlimited accounts automatically.
 */
final class PlatformAsaasBilling {
 private const CYCLES=[
  'weekly'=>'WEEKLY','biweekly'=>'BIWEEKLY','monthly'=>'MONTHLY',
  'bimonthly'=>'BIMONTHLY','quarterly'=>'QUARTERLY',
  'semiannually'=>'SEMIANNUALLY','yearly'=>'YEARLY'
 ];
 public static function ready(): bool {
  return (int)(DB::one("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()
   AND TABLE_NAME IN ('platform_asaas_subscriptions','platform_asaas_payments','platform_asaas_webhook_events')")['n']??0)===3;
 }
 public static function env(): string {
  $env=setting('platform_asaas_env','sandbox');
  return in_array($env,['sandbox','production'],true)?$env:'sandbox';
 }
 public static function enabled(?string $environment=null): bool {
  $env=$environment??self::env();
  return in_array($env,['sandbox','production'],true)&&self::ready()&&
   setting('platform_asaas_enabled_'.$env,'0')==='1'&&
   setting('platform_asaas_api_key_'.$env,'')!==''&&
   setting('platform_asaas_webhook_token_'.$env,'')!=='';
 }
 public static function webhookUrl(string $env): string {
  if(!in_array($env,['sandbox','production'],true))return '';
  return base('asaas-webhook.php').'?environment='.$env;
 }
 private static function master(): void {
  Auth::load();
  if(Auth::$origin!=='web'||!MasterSupport::isActor()||MasterSupport::isSupport())
   fail('Disponível somente ao ADM Master.',403);
 }
 private static function validApiRoute(string $path): bool {
  // Delimitadores iguais (~): valida somente o caminho relativo da API v3.
  return preg_match('~\\A/v3/[a-zA-Z0-9/_?=&-]+\\z~D',$path)===1;
 }
 public static function api(string $method,string $path,?array $data=null,?string $env=null,?string $keyOverride=null): array {
  $env=$env??self::env();
  if(!in_array($env,['sandbox','production'],true)||!self::validApiRoute($path))
   throw new InvalidArgumentException('Invalid Asaas API route.');
  $stored=setting('platform_asaas_api_key_'.$env,'');
  if($keyOverride===null&&$stored==='')throw new RuntimeException('asaas_key_not_configured');
  $key=$keyOverride??secretDecrypt($stored);
  if(!function_exists('curl_init'))throw new RuntimeException('asaas_curl_unavailable');
  $root=$env==='production'?'https://api.asaas.com':'https://api-sandbox.asaas.com';
  $ch=curl_init($root.$path);
  $headers=['Accept: application/json','User-Agent: TaskEsc/1.0','access_token: '.$key];
  if($data!==null)$headers[]='Content-Type: application/json';
  curl_setopt_array($ch,[
   CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
   CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>35,CURLOPT_HTTPHEADER=>$headers,
   CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true,
   CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
  ]);
  if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR));
  try{
   $result=curl_exec($ch);
   $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
   if(!is_string($result)){
    $reason=curl_errno($ch);
    throw new RuntimeException('asaas_transport_'.(int)$reason);
   }
   if($http<200||$http>=300){
    // Only approved error *codes* are used; never include the remote body,
    // request headers or access token in user-facing errors / application logs.
    $decoded=json_decode($result,true);
    $code=(string)($decoded['errors'][0]['code']??'');
    $known=['invalid_environment','invalid_access_token','invalid_access_token_format',
      'access_token_not_found','ip_not_allowed','invalid_ip','invalid_request'];
    $reason=in_array($code,$known,true)?'_'.$code:'';
    throw new RuntimeException('asaas_http_'.$http.$reason);
   }
   $decoded=json_decode($result,true,64,JSON_THROW_ON_ERROR);
   if(!is_array($decoded))throw new RuntimeException('asaas_invalid_response');
   return $decoded;
  }finally{curl_close($ch);}
 }
 public static function connection(array $p=[]): array {
  self::master();
  Auth::rate('master-asaas-connection-test',10,3600);
  $env=enumv($p['environment']??self::env(),['sandbox','production'],'ambiente');
  // Opcional: testar a chave digitada em memória, sem substituir a credencial gravada.
  $provided=trim((string)($p['api_key']??''));
  if($provided!==''&&(strlen($provided)<12||strlen($provided)>1024||preg_match('/\\s/',$provided)))
   fail('Formato de chave inválido. Confira se copiou a credencial completa, sem espaços.',422);
  try{$info=self::api('GET','/v3/myAccount/status/',null,$env,$provided!==''?$provided:null);}
  catch(Throwable $e){
   $category=PlatformAsaasCredentials::diagnosticCode($e);
   error_log('TaskEsc Asaas connection test failed: '.$category.' environment='.$env);
   fail(PlatformAsaasCredentials::diagnosticMessage($e),502,['code'=>$category]);
  }
  return ['connected'=>true,'environment'=>$env,
   'credential_source'=>$provided!==''?'temporary':'saved',
   'general_status'=>$info['general']??'unknown'];
 }
 public static function status(): array {
  self::master();
  $env=self::env();
  return ['ready'=>self::ready(),'enabled'=>self::enabled($env),
   'environment'=>$env,'webhook_url'=>self::webhookUrl($env),
   'total_subscriptions'=>self::ready()?(int)(DB::one('SELECT COUNT(*) n FROM platform_asaas_subscriptions WHERE environment=?',[$env])['n']??0):0];
 }
 public static function workspace(int $workspaceId): array {
  if(!self::ready())return ['enabled'=>false,'subscriptions'=>[],'payments'=>[]];
  $env=self::env();
  $items=DB::all('SELECT id,plan_id,environment,asaas_subscription_id,
    payer_name,payer_email,amount_cents,cycle,due_date,request_status,billing_status,
    created_at FROM platform_asaas_subscriptions WHERE workspace_id=? AND environment=?
    ORDER BY id DESC',[$workspaceId,$env]);
  $payments=[];
  foreach($items as $item)
   $payments=array_merge($payments,DB::all('SELECT asaas_payment_id,status,amount_cents,due_date,invoice_url,paid_at
     FROM platform_asaas_payments WHERE subscription_id=? ORDER BY id DESC LIMIT 25',[(int)$item['id']]));
  return ['enabled'=>self::enabled($env),'subscriptions'=>$items,'payments'=>$payments,
   'access'=>PlatformBillingAccess::state($workspaceId)];
 }
 public static function start(array $input): array {
  self::master();Auth::rate('asaas-subscription-create',5,3600);
  $env=self::env();
  if(!self::enabled($env))fail('Configure e habilite o Asaas no ADM Master antes de cobrar.',409);
  if(strtoupper(trim((string)($input['confirmation']??'')))!=='COBRAR')
   fail('Confirme explicitamente COBRAR para gerar a assinatura.',422);
  $workspace=integer($input['workspace_id']??0,1);
  $name=trim((string)($input['payer_name']??''));
  $email=strtolower(trim((string)($input['payer_email']??'')));
  $taxId=preg_replace('/\D+/','',(string)($input['cpf_cnpj']??''));
  if(mb_strlen($name)<3||mb_strlen($name)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)||
    !in_array(strlen($taxId),[11,14],true))fail('Informe nome, e-mail e CPF/CNPJ válidos do pagador.',422);
  $plan=DB::one('SELECT p.id,p.name,p.code,p.price_cents,p.extra_user_price_cents,p.billing_period,a.extra_seats
   FROM workspace_access_plans a JOIN platform_access_plans p ON p.id=a.plan_id
   JOIN workspaces w ON w.id=a.workspace_id
   WHERE a.workspace_id=? AND p.active=1 AND w.status IN (\'staged\',\'active\')',[$workspace]);
  if(!$plan||$plan['code']==='legacy-unlimited'||(int)$plan['price_cents']<100||
    !isset(self::CYCLES[$plan['billing_period']]))
   fail('Associe um plano pago com ciclo suportado antes de cobrar. Contas legadas não são cobradas.',409);
  if(!self::ready())fail('Migração Asaas ainda não aplicada.',503);
  $orderId=DB::tx(static function()use($workspace,$plan,$env,$name,$email):int {
   if(DB::one('SELECT id FROM platform_asaas_subscriptions WHERE workspace_id=? AND environment=? FOR UPDATE',[$workspace,$env]))
    fail('Já existe uma solicitação de cobrança; consulte o histórico antes de tentar outra.',409);
   return DB::insert('platform_asaas_subscriptions',[
    'workspace_id'=>$workspace,'plan_id'=>(int)$plan['id'],'environment'=>$env,
    'payer_name'=>$name,'payer_email'=>$email,'amount_cents'=>PlatformPlans::amount((int)$plan['price_cents'],(int)$plan['extra_user_price_cents'],(int)$plan['extra_seats']),
    'cycle'=>$plan['billing_period'],'due_date'=>gmdate('Y-m-d',strtotime('+2 days')),
    'request_status'=>'creating','billing_status'=>'awaiting_payment','created_at'=>now(),'updated_at'=>now()
   ]);
  });
  // Never automatically retry an uncertain payment-provider POST. The order
  // remains in needs_review until a human checks Asaas to avoid duplication.
  try{
   $customer=self::api('POST','/v3/customers',[
    'name'=>$name,'email'=>$email,'cpfCnpj'=>$taxId,
    'externalReference'=>'taskesc-'.$env.'-workspace-'.$workspace
   ],$env);
   $cid=(string)($customer['id']??'');
   if(!preg_match('/\Acus_[a-zA-Z0-9]+\z/D',$cid))throw new RuntimeException('asaas_customer_id_invalid');
   DB::run('UPDATE platform_asaas_subscriptions SET asaas_customer_id=?,updated_at=? WHERE id=?',
     [$cid,now(),$orderId]);
   $subscription=self::api('POST','/v3/subscriptions',[
    'customer'=>$cid,'billingType'=>'UNDEFINED',
    'value'=>round(PlatformPlans::amount((int)$plan['price_cents'],(int)$plan['extra_user_price_cents'],(int)$plan['extra_seats'])/100,2),
    'nextDueDate'=>gmdate('Y-m-d',strtotime('+2 days')),
    'cycle'=>self::CYCLES[$plan['billing_period']],
    'description'=>'TaskEsc - '.$plan['name'],
    'externalReference'=>'taskesc-'.$env.'-subscription-'.$orderId
   ],$env);
   $sid=(string)($subscription['id']??'');
   if(!preg_match('/\Asub_[a-zA-Z0-9]+\z/D',$sid))throw new RuntimeException('asaas_subscription_id_invalid');
   DB::run('UPDATE platform_asaas_subscriptions SET asaas_subscription_id=?,
    request_status=?,updated_at=? WHERE id=?',[$sid,'created',now(),$orderId]);
   return ['created'=>true,'subscription_id'=>$sid,'environment'=>$env,'billing_status'=>'awaiting_payment',
     'message'=>'Assinatura criada. Pagamento ainda não confirmado; consulte as cobranças para obter o link.'];
  }catch(Throwable $ex){
   DB::run('UPDATE platform_asaas_subscriptions SET request_status=?,updated_at=? WHERE id=?',
     ['needs_review',now(),$orderId]);
   error_log('TaskEsc Asaas order requires review: order='.$orderId.' reason='.$ex->getMessage());
   fail('Asaas não confirmou a operação. Verifique a conta antes de repetir para não gerar cobranças duplicadas.',502);
  }
 }
 private static function upsertPayment(array $payment,int $localId,string $env): void {
  $id=(string)($payment['id']??'');
  if(!preg_match('/\Apay_[a-zA-Z0-9]+\z/D',$id))return;
  $status=mb_substr((string)($payment['status']??'PENDING'),0,40);
  $amount=max(0,(int)round((float)($payment['value']??0)*100));
  $due=(string)($payment['dueDate']??'');
  $date=preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$due)?$due:null;
  $url=(string)($payment['invoiceUrl']??'');
  if(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https')$url='';
  $paid=in_array($status,['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'],true)?now():null;
  DB::run("INSERT INTO platform_asaas_payments
    (subscription_id,environment,asaas_payment_id,status,amount_cents,due_date,invoice_url,paid_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      status=CASE
       WHEN status IN ('REFUNDED','REFUND_REQUESTED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE')
        THEN status
       WHEN VALUES(status) IN ('REFUNDED','REFUND_REQUESTED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE')
        THEN VALUES(status)
       WHEN status IN ('CONFIRMED','RECEIVED','RECEIVED_IN_CASH')
          AND VALUES(status) IN ('PENDING','OVERDUE','CONFIRMED')
        THEN status
       ELSE VALUES(status) END,
      amount_cents=VALUES(amount_cents),
      due_date=COALESCE(VALUES(due_date),due_date),
      invoice_url=COALESCE(NULLIF(VALUES(invoice_url),''),invoice_url),
      paid_at=COALESCE(platform_asaas_payments.paid_at,VALUES(paid_at)),updated_at=VALUES(updated_at)",
    [$localId,$env,$id,$status,$amount,$date,$url?:null,$paid,now()]);
  // A cobrança nova pode ser futura; um webhook antigo nunca deve sobrescrever o
  // estado financeiro da competência mais recente que já venceu.
  self::recomputeBilling($localId);
 }
 private static function recomputeBilling(int $localId): void {
  $subscription=DB::one('SELECT request_status FROM platform_asaas_subscriptions WHERE id=?',[$localId]);
  if(!$subscription)return;
  $invoice=DB::one("SELECT status FROM platform_asaas_payments
     WHERE subscription_id=? AND due_date<=UTC_DATE()
     ORDER BY due_date DESC,id DESC LIMIT 1",[$localId]);
  $status=(string)($invoice['status']??'PENDING');
  $billing=match($status){
   'RECEIVED','CONFIRMED','RECEIVED_IN_CASH'=>'paid',
   'OVERDUE'=>'overdue',
   'REFUNDED','REFUND_REQUESTED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE'=>'disputed',
   'DELETED'=>'cancelled',default=>'awaiting_payment'
  };
  if($subscription['request_status']==='inactive')$billing='paused';
  if($subscription['request_status']==='cancelled')$billing='cancelled';
  DB::run('UPDATE platform_asaas_subscriptions SET billing_status=?,updated_at=? WHERE id=?',
    [$billing,now(),$localId]);
 }
 public static function refresh(array $p): array {
  self::master();Auth::rate('asaas-refresh',12,3600);
  if(!self::enabled())fail('Asaas não está habilitado.',409);
  $id=integer($p['workspace_id']??0,1);
  $order=DB::one('SELECT id,asaas_subscription_id FROM platform_asaas_subscriptions
    WHERE environment=? AND workspace_id=?',[self::env(),$id]);
  if(!$order||!$order['asaas_subscription_id'])fail('Assinatura não encontrada.',404);
  self::syncPayments((int)$order['id'],(string)$order['asaas_subscription_id'],self::env());
  return self::workspace($id);
 }
 private static function syncPayments(int $localId,string $subscriptionId,string $environment): int {
  $count=0;
  // Asaas paginates payments. A single default page can miss the current
  // invoice and accidentally leave the wrong access state.
  for($offset=0;$offset<2000;$offset+=100){
   $page=self::api('GET','/v3/subscriptions/'.$subscriptionId.'/payments?limit=100&offset='.$offset,null,$environment);
   $entries=$page['data']??[];
   if(!is_array($entries))throw new RuntimeException('asaas_payments_invalid');
   foreach($entries as $payment)if(is_array($payment)){self::upsertPayment($payment,$localId,$environment);$count++;}
   if(empty($page['hasMore']))break;
  }
  self::recomputeBilling($localId);
  return $count;
 }
 /** Recovery sync: webhooks remain primary. Process a small rotating batch only
  * every few hours, never on every request or every tenant's cron.
  */
 public static function reconcile(): array {
  if(!self::enabled('production'))return ['status'=>'disabled','checked'=>0];
  $last=strtotime(setting('platform_asaas_reconcile_last',''))?:0;
  if(time()-$last<14400)return ['status'=>'scheduled','checked'=>0];
  $lock=DB::one("SELECT GET_LOCK('taskesc-asaas-reconcile',0) AS acquired");
  if((int)($lock['acquired']??0)!==1)return ['status'=>'busy','checked'=>0];
  $checked=0;$failed=0;
  try{
   $cursor=max(0,(int)setting('platform_asaas_reconcile_cursor','0'));
   $rows=DB::all("SELECT id,asaas_subscription_id FROM platform_asaas_subscriptions
    WHERE environment='production' AND request_status='created'
    AND asaas_subscription_id IS NOT NULL AND id>?
    ORDER BY id LIMIT 20",[$cursor]);
   if(!$rows){$cursor=0;}
   foreach($rows as $row){
    try{
     self::syncPayments((int)$row['id'],(string)$row['asaas_subscription_id'],'production');
     $checked++;
    }catch(Throwable $e){
     $failed++;error_log('Asaas reconciliation error for subscription row '.(int)$row['id'].': '.get_class($e));
    }
    $cursor=(int)$row['id'];
   }
   foreach(['platform_asaas_reconcile_cursor'=>(string)$cursor,
             'platform_asaas_reconcile_last'=>now()] as $key=>$value){
    DB::run('INSERT INTO settings (`key`,value) VALUES (?,?)
      ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$value]);
   }
  }finally{DB::one("SELECT RELEASE_LOCK('taskesc-asaas-reconcile') AS released");}
  return ['status'=>$failed?'partial':'ok','checked'=>$checked,'failed'=>$failed];
 }
 public static function updateSeats(array $p,bool $customer=false): array {
  if($customer){
   Auth::admin();
   if(Auth::$origin!=='web'||MasterSupport::isSupport()||
      (string)(Auth::$workspace['role']??'')!=='owner')
    fail('Somente o proprietário ADM pode contratar vagas adicionais.',403);
  }else self::master();
  Auth::rate('asaas-seats-update',10,3600);
  $workspace=$customer?Auth::workspaceId():integer($p['workspace_id']??0,1);
  $requested=integer($p['extra_seats']??0,0,100000);
  if(strtoupper(trim((string)($p['confirmation']??'')))!=='ATUALIZAR')
   fail('Confirme ATUALIZAR para modificar o valor da recorrência.',422);
  $lock='taskesc-seats-'.$workspace;
  if((int)(DB::one('SELECT GET_LOCK(?,0) AS acquired',[$lock])['acquired']??0)!==1)
   fail('Outra atualização de usuários está em andamento.',409);
  try{
   $row=DB::one('SELECT a.workspace_id,a.plan_id,a.extra_seats,p.code,p.name,p.team_limit,p.price_cents,p.extra_user_price_cents,
    w.owner_user_id FROM workspace_access_plans a
    JOIN platform_access_plans p ON p.id=a.plan_id AND p.active=1
    JOIN workspaces w ON w.id=a.workspace_id WHERE a.workspace_id=?',[$workspace]);
   if(!$row||$row['code']==='legacy-unlimited'||$row['team_limit']===null)
    fail('A empresa não possui um plano limitado elegível para vagas extras.',409);
   $price=(int)$row['extra_user_price_cents'];
   if($requested>0&&$price===0)fail('Defina o preço da vaga adicional no plano antes de vender.',409);
   $used=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members wm
    JOIN users u ON u.id=wm.user_id WHERE wm.workspace_id=? AND wm.status='active'
    AND wm.role IN ('admin','staff') AND u.active=1 AND u.id<>?",
    [$workspace,(int)$row['owner_user_id']])['n']??0);
   if($used>(int)$row['team_limit']+$requested)
    fail('A empresa utiliza mais usuários do que o novo limite. Remova usuários antes de reduzir vagas.',409);
   $current=(int)$row['extra_seats'];
   $total=PlatformPlans::amount((int)$row['price_cents'],$price,$requested);
   if($current===$requested)return ['updated'=>false,'extra_seats'=>$current,'total_cents'=>$total];
   $subscription=DB::one("SELECT id,environment,asaas_subscription_id,request_status,amount_cents
    FROM platform_asaas_subscriptions WHERE workspace_id=? AND environment='production' LIMIT 1",[$workspace]);
   if($customer&&!$subscription)fail('A assinatura precisa estar ativa em produção para comprar vagas adicionais.',409);
   if($subscription){
    if(!in_array($subscription['request_status'],['created','inactive'],true)||
       !$subscription['asaas_subscription_id'])
     fail('Assinatura em análise. Regularize com o Asaas antes de alterar usuários.',409);
    if(!self::enabled('production'))fail('Habilite a integração Asaas de produção.',409);
    $expected=PlatformPlans::amount((int)$row['price_cents'],$price,$current);
    if((int)$subscription['amount_cents']!==$expected)
     fail('A mensalidade registrada diverge do plano. Revise a assinatura antes de alterar vagas.',409);
    $sid=(string)$subscription['asaas_subscription_id'];
    $remote=self::api('GET','/v3/subscriptions/'.$sid,null,'production');
    if((int)round((float)($remote['value']??-1)*100)!==$expected)
     fail('Valor no Asaas diferente do TaskEsc. Faça a conciliação antes de alterar vagas.',409);
    $updated=self::api('PUT','/v3/subscriptions/'.$sid,
      ['value'=>round($total/100,2),'updatePendingPayments'=>false],'production');
    if((int)round((float)($updated['value']??-1)*100)!==$total)
     fail('Asaas não confirmou o novo valor. Confira a assinatura antes de repetir.',502);
   }
   DB::tx(static function()use($workspace,$requested,$subscription,$total,$current):void {
    DB::run('UPDATE workspace_access_plans SET extra_seats=? WHERE workspace_id=?',[$requested,$workspace]);
    if($subscription)DB::run('UPDATE platform_asaas_subscriptions SET amount_cents=?,updated_at=? WHERE id=?',
      [$total,now(),(int)$subscription['id']]);
    audit('platform',0,'master.asaas.seats_updated',null,
      ['workspace_id'=>$workspace,'previous_extra_seats'=>$current,'extra_seats'=>$requested,'total_cents'=>$total]);
   });
   return ['updated'=>true,'extra_seats'=>$requested,'total_cents'=>$total,
      'message'=>$subscription?'Nova mensalidade aplicada às próximas cobranças no Asaas.':'Vagas configuradas. Serão incluídas quando a assinatura for criada.'];
  }finally{DB::one('SELECT RELEASE_LOCK(?) AS released',[$lock]);}
 }
 public static function toggle(array $p): array {
  self::master();Auth::rate('asaas-recurring-toggle',8,3600);
  $workspace=integer($p['workspace_id']??0,1);
  $operation=enumv($p['operation']??'',['pause','resume'],'ação de assinatura');
  $confirmation=$operation==='pause'?'PAUSAR':'RETOMAR';
  if(strtoupper(trim((string)($p['confirmation']??'')))!==$confirmation)
   fail('Digite '.$confirmation.' para confirmar a alteração da assinatura.',422);
  $env=self::env();
  if(!self::enabled($env))fail('Configure o Asaas antes de alterar cobranças.',409);
  $row=DB::one('SELECT id,asaas_subscription_id,request_status FROM platform_asaas_subscriptions
    WHERE environment=? AND workspace_id=?',[$env,$workspace]);
  if(!$row||!preg_match('/\\Asub_[A-Za-z0-9]+\\z/D',(string)($row['asaas_subscription_id']??'')))
   fail('Assinatura inexistente ou ainda não confirmada no Asaas.',409);
  $active=$row['request_status']==='created';
  if(($operation==='pause'&&!$active)||($operation==='resume'&&$active))
   fail('A assinatura já está neste estado.',409);
  $body=$operation==='pause'?['status'=>'INACTIVE']:
    ['status'=>'ACTIVE','nextDueDate'=>gmdate('Y-m-d',strtotime('+2 days'))];
  // Somente uma ação explícita do Master escreve no provedor.
  self::api('PUT','/v3/subscriptions/'.$row['asaas_subscription_id'],$body,$env);
  DB::tx(static function()use($row,$operation,$workspace,$env):void{
   DB::run('UPDATE platform_asaas_subscriptions
    SET request_status=?,billing_status=?,updated_at=? WHERE id=?',
    [$operation==='pause'?'inactive':'created',
     $operation==='pause'?'paused':'awaiting_payment',now(),(int)$row['id']]);
   audit('platform',0,'master.asaas.'.$operation,null,
    ['workspace_id'=>$workspace,'environment'=>$env,'subscription_row_id'=>(int)$row['id']]);
  });
  return self::workspace($workspace);
 }
 public static function webhook(string $env,string $token,array $payload): array {
  if(!in_array($env,['sandbox','production'],true)||!self::enabled($env))
   throw new RuntimeException('asaas_webhook_disabled');
  $expected=secretDecrypt(setting('platform_asaas_webhook_token_'.$env,''));
  if($token===''||!hash_equals($expected,$token))throw new RuntimeException('asaas_webhook_unauthorized');
  $event=(string)($payload['event']??'');$eventId=(string)($payload['id']??'');
  if(!preg_match('/\Aevt_[a-zA-Z0-9-]+\z/D',$eventId)||
     !preg_match('/\A(?:PAYMENT|SUBSCRIPTION)_[A-Z_]+\z/D',$event))
   throw new RuntimeException('asaas_webhook_invalid');
  $object=str_starts_with($event,'PAYMENT_')?($payload['payment']??null):($payload['subscription']??null);
  if(!is_array($object))throw new RuntimeException('asaas_webhook_missing_object');
  $subscriptionId=str_starts_with($event,'PAYMENT_')?(string)($object['subscription']??''):(string)($object['id']??'');
  if($subscriptionId===''||!preg_match('/\Asub_[A-Za-z0-9]+\z/D',$subscriptionId))
   return ['accepted'=>true,'ignored'=>'unrelated'];
  return DB::tx(static function()use($env,$payload,$event,$eventId,$subscriptionId,$object):array {
   if(DB::one('SELECT 1 FROM platform_asaas_webhook_events WHERE environment=? AND event_id=?',[$env,$eventId]))
    return ['accepted'=>true,'duplicate'=>true];
   $row=DB::one('SELECT id FROM platform_asaas_subscriptions WHERE environment=?
    AND asaas_subscription_id=? FOR UPDATE',[$env,$subscriptionId]);
   if(!$row)return ['accepted'=>true,'ignored'=>'unknown_subscription'];
   $id=(int)$row['id'];
   DB::run('INSERT INTO platform_asaas_webhook_events
    (environment,event_id,event_type,subscription_id,payload_hash,created_at) VALUES (?,?,?,?,?,?)',
    [$env,$eventId,$event,$id,hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),now()]);
   if(str_starts_with($event,'PAYMENT_'))self::upsertPayment($object,$id,$env);
   elseif(in_array($event,['SUBSCRIPTION_INACTIVATED','SUBSCRIPTION_DELETED'],true)){
    DB::run('UPDATE platform_asaas_subscriptions SET request_status=?,updated_at=? WHERE id=?',
      [$event==='SUBSCRIPTION_DELETED'?'cancelled':'inactive',now(),$id]);
    self::recomputeBilling($id);
   } elseif($event==='SUBSCRIPTION_ACTIVATED'){
    DB::run('UPDATE platform_asaas_subscriptions SET request_status=?,updated_at=? WHERE id=?',
      ['created',now(),$id]);
    self::recomputeBilling($id);
   }
   return ['accepted'=>true,'idempotent'=>true];
  });
 }
}
