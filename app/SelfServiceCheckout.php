<?php
declare(strict_types=1);

/**
 * Email-verified public acquisition. Never grants tenant access before a paid
 * subscription webhook, and never automatically retries uncertain Asaas POSTs.
 */
final class SelfServiceCheckout {
 private static function available(): void {
  if(!WorkspaceTenantMode::enabled()||!PlatformAsaasBilling::ready()||
     !PlatformAsaasBilling::enabled('production'))
   fail('Contratação automática ainda não está disponível. Solicite atendimento comercial.',503);
 }
 private static function get(string $token,bool $lock=false): array {
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$token))fail('Link de contratação inválido.',404);
  $row=DB::one("SELECT o.*,u.email,u.name,w.name company,p.name plan_name,p.price_cents,
    p.extra_user_price_cents,p.allow_extra_seats,p.active,p.public_visible,p.billing_period
    FROM platform_checkout_orders o
    JOIN users u ON u.id=o.user_id JOIN workspaces w ON w.id=o.workspace_id
    JOIN platform_access_plans p ON p.id=o.plan_id
    WHERE o.verify_hash=?".($lock?' FOR UPDATE':''),
    [hash('sha256',$token)]);
  if(!$row||strtotime((string)$row['token_expires_at'])<time())
   fail('Link expirado. Contate o suporte para reativar a contratação.',410);
  return $row;
 }
 public static function register(array $p): array {
  self::available();Auth::rate('checkout-register',4,3600);
  checkCsrf();
  if(empty($p['privacy_consent']))fail('Leia e aceite a Política de Privacidade.',422);
  $email=strtolower(trim((string)($p['email']??'')));
  $name=trim((string)($p['name']??''));
  $company=trim((string)($p['company']??''));
  $password=(string)($p['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||
     mb_strlen($name)<3||mb_strlen($name)>150||mb_strlen($company)<3||mb_strlen($company)>190)
   fail('Informe nome, empresa e e-mail válidos.',422);
  $hash=Auth::password($password);
  $planId=integer($p['plan_id']??0,1);
  $seats=integer($p['extra_seats']??0,0,100);
  $plan=DB::one("SELECT id,team_limit,allow_extra_seats,extra_user_price_cents
    FROM platform_access_plans WHERE id=? AND active=1 AND public_visible=1
      AND code<>'legacy-unlimited'",[$planId]);
  if(!$plan)fail('Plano indisponível.',404);
  if($seats>0&&($plan['team_limit']===null||!(int)$plan['allow_extra_seats']||
      (int)$plan['extra_user_price_cents']<1))fail('Este plano não oferece vagas adicionais.',409);
  $token=bin2hex(random_bytes(32));
  try{
   DB::tx(static function()use($email,$name,$company,$hash,$planId,$seats,$token):void {
    if(DB::one('SELECT id FROM users WHERE email=?',[$email]))
     fail('Este e-mail já possui cadastro. Faça login para gerenciar sua assinatura.',409);
    $userId=DB::insert('users',[
      'name'=>$name,'email'=>$email,'password_hash'=>$hash,'role'=>'admin','active'=>0,
      'email_notifications'=>1,'created_at'=>now()
    ]);
    $slug='empresa-'.bin2hex(random_bytes(9));
    $ws=DB::insert('workspaces',[
      'slug'=>$slug,'name'=>$company,'owner_user_id'=>$userId,
      'status'=>'pending_payment','created_at'=>now(),'updated_at'=>now()
    ]);
    DB::run('INSERT INTO workspace_members (workspace_id,user_id,role,status,joined_at)
      VALUES (?,?,?,?,?)',[$ws,$userId,'owner','inactive',now()]);
    DB::run('INSERT INTO workspace_access_plans
      (workspace_id,plan_id,extra_seats,assigned_by,assigned_at) VALUES (?,?,?,?,?)',
      [$ws,$planId,$seats,null,now()]);
    DB::insert('platform_checkout_orders',[
      'user_id'=>$userId,'workspace_id'=>$ws,'plan_id'=>$planId,
      'extra_seats'=>$seats,'verify_hash'=>hash('sha256',$token),
      'token_expires_at'=>gmdate('Y-m-d H:i:s',time()+172800),
      'status'=>'awaiting_email','created_at'=>now(),'updated_at'=>now()
    ]);
    $verifyUrl=base('contratar.php?token='.$token);
    DB::insert('platform_mail_queue',[
      'recipient'=>$email,'subject'=>'TaskEsc — confirme sua contratação',
      'body'=>"Confirme seu e-mail para continuar a assinatura do TaskEsc:\n".$verifyUrl.
       "\n\nEste link expira em 48 horas. Se não solicitou, ignore esta mensagem.",
      'status'=>'pending','attempts'=>0,'next_attempt'=>now(),'created_at'=>now()
    ]);
   });
  }catch(AppError $e){throw $e;}
  catch(Throwable $e){
   if(str_contains($e->getMessage(),'Duplicate'))fail('Já existe uma conta com este e-mail. Faça login.',409);
   throw $e;
  }
  return ['registered'=>true,'message'=>'Enviamos um link de confirmação ao seu e-mail. Após confirmar, você poderá concluir o pagamento.'];
 }
 public static function status(array $p): array {
  $row=self::get(trim((string)($p['token']??'')));
  $subscription=DB::one("SELECT id,asaas_subscription_id,request_status,billing_status
     FROM platform_asaas_subscriptions WHERE workspace_id=? AND environment='production'",
     [(int)$row['workspace_id']]);
  $invoice=null;
  if($subscription){
   $paid=DB::one("SELECT status,invoice_url FROM platform_asaas_payments
    WHERE subscription_id=? ORDER BY id DESC LIMIT 1",[(int)$subscription['id']]);
   $invoice=(string)($paid['invoice_url']??'');
  }
  return ['status'=>$row['status'],'plan'=>$row['plan_name'],
   'amount_cents'=>PlatformPlans::amount((int)$row['price_cents'],(int)$row['extra_user_price_cents'],(int)$row['extra_seats']),
   'extra_seats'=>(int)$row['extra_seats'],'email'=>$row['email'],
   'invoice_url'=>str_starts_with((string)$invoice,'https://')?$invoice:null,
   'payment_status'=>$subscription['billing_status']??null];
 }
 public static function verify(array $p): array {
  checkCsrf();Auth::rate('checkout-verify',10,900);
  $token=trim((string)($p['token']??''));
  DB::tx(static function()use($token):void {
   $row=self::get($token,true);
   if($row['status']==='awaiting_email'){
    DB::run('UPDATE platform_checkout_orders SET status=?,verified_at=?,updated_at=? WHERE id=?',
      ['verified',now(),now(),(int)$row['id']]);
   }
  });
  return self::status(['token'=>$token]);
 }
 public static function pay(array $p): array {
  self::available();checkCsrf();Auth::rate('checkout-create-subscription',3,3600);
  $token=trim((string)($p['token']??''));
  $taxId=preg_replace('/\D/','',(string)($p['cpf_cnpj']??''));
  if(!in_array(strlen($taxId),[11,14],true))
   fail('Informe CPF ou CNPJ para emissão da cobrança.',422);
  $row=DB::tx(static function()use($token):array {
   $order=self::get($token,true);
   if($order['status']!=='verified')fail('Confirme seu e-mail antes de gerar a cobrança.',409);
   DB::run('UPDATE platform_checkout_orders SET status=?,updated_at=? WHERE id=?',
      ['creating',now(),(int)$order['id']]);
   return $order;
  });
  $ws=(int)$row['workspace_id'];
  $plan=DB::one('SELECT p.id,p.name,p.code,p.price_cents,p.extra_user_price_cents,p.allow_extra_seats,p.billing_period,a.extra_seats
   FROM workspace_access_plans a JOIN platform_access_plans p ON p.id=a.plan_id WHERE a.workspace_id=?',[$ws]);
  if(!$plan||$plan['code']==='legacy-unlimited')fail('Plano indisponível.',409);
  $amount=PlatformPlans::amount((int)$plan['price_cents'],(int)$plan['extra_user_price_cents'],(int)$plan['extra_seats']);
  $orderId=DB::insert('platform_asaas_subscriptions',[
   'workspace_id'=>$ws,'plan_id'=>(int)$plan['id'],'environment'=>'production',
   'payer_name'=>$row['name'],'payer_email'=>$row['email'],
   'amount_cents'=>$amount,'cycle'=>$plan['billing_period'],
   'due_date'=>gmdate('Y-m-d',strtotime('+1 day')),
   'request_status'=>'creating','billing_status'=>'awaiting_payment',
   'created_at'=>now(),'updated_at'=>now()
  ]);
  try{
   $customer=PlatformAsaasBilling::api('POST','/v3/customers',[
    'name'=>$row['name'],'email'=>$row['email'],'cpfCnpj'=>$taxId,
    'externalReference'=>'taskesc-new-'.$ws],'production');
   $customerId=(string)($customer['id']??'');
   if(!preg_match('/\Acus_[A-Za-z0-9]+\z/D',$customerId))throw new RuntimeException('Asaas customer missing');
   DB::run('UPDATE platform_asaas_subscriptions SET asaas_customer_id=? WHERE id=?',[$customerId,$orderId]);
   $cycle=['weekly'=>'WEEKLY','biweekly'=>'BIWEEKLY','monthly'=>'MONTHLY',
    'bimonthly'=>'BIMONTHLY','quarterly'=>'QUARTERLY','semiannually'=>'SEMIANNUALLY','yearly'=>'YEARLY'];
   if(!isset($cycle[$plan['billing_period']]))throw new RuntimeException('Unsupported cycle');
   $created=PlatformAsaasBilling::api('POST','/v3/subscriptions',[
    'customer'=>$customerId,'billingType'=>'UNDEFINED','value'=>round($amount/100,2),
    'nextDueDate'=>gmdate('Y-m-d',strtotime('+1 day')),
    'cycle'=>$cycle[$plan['billing_period']],
    'description'=>'TaskEsc - '.$plan['name'],
    'externalReference'=>'taskesc-new-checkout-'.$orderId
   ],'production');
   $subscriptionId=(string)($created['id']??'');
   if(!preg_match('/\Asub_[A-Za-z0-9]+\z/D',$subscriptionId))throw new RuntimeException('Missing Asaas subscription');
   DB::tx(static function()use($row,$orderId,$subscriptionId):void {
    DB::run('UPDATE platform_asaas_subscriptions SET asaas_subscription_id=?,request_status=?,updated_at=? WHERE id=?',
     [$subscriptionId,'created',now(),$orderId]);
    DB::run('UPDATE platform_checkout_orders SET status=?,updated_at=? WHERE id=?',
     ['awaiting_payment',now(),(int)$row['id']]);
   });
   return self::status(['token'=>$token]);
  }catch(Throwable $e){
   DB::run('UPDATE platform_asaas_subscriptions SET request_status=?,updated_at=? WHERE id=?',
    ['needs_review',now(),$orderId]);
   DB::run('UPDATE platform_checkout_orders SET status=?,updated_at=? WHERE id=?',
    ['needs_review',now(),(int)$row['id']]);
   error_log('TaskEsc checkout needs review: order='.$orderId.' class='.get_class($e));
   fail('A operação precisa de revisão. Nossa equipe verificará o Asaas antes de qualquer nova tentativa.',502);
  }
 }
 /** Call within the authenticated payment webhook transaction, never on browser status. */
 public static function activatePaid(int $subscriptionId,array $payment): void {
  if(!in_array((string)($payment['status']??''),['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'],true))return;
  $order=DB::one("SELECT o.*,s.amount_cents,s.environment FROM platform_checkout_orders o
    JOIN platform_asaas_subscriptions s ON s.workspace_id=o.workspace_id
    WHERE s.id=? AND o.status='awaiting_payment' FOR UPDATE",[$subscriptionId]);
  if(!$order||$order['environment']!=='production'||!$order['verified_at'])return;
  if((int)round((float)($payment['value']??0)*100)<(int)$order['amount_cents'])return;
  $ws=(int)$order['workspace_id'];
  DB::run('UPDATE workspaces SET status=?,updated_at=? WHERE id=? AND status=?',
    ['active',now(),$ws,'pending_payment']);
  DB::run('UPDATE users SET active=1 WHERE id=? AND active=0',[(int)$order['user_id']]);
  DB::run('UPDATE workspace_members SET status=? WHERE workspace_id=? AND user_id=? AND role=?',
    ['active',$ws,(int)$order['user_id'],'owner']);
  WorkspaceJobScope::forWorkspace($ws,static function():void {
   $origin=DB::one('SELECT id FROM workspaces WHERE slug=?',[WorkspaceFoundation::SLUG]);
   if(!$origin)throw new RuntimeException('Template workspace unavailable');
   $stages=DB::all('SELECT name,kind,color,position,active FROM stages WHERE workspace_id=? ORDER BY position',[(int)$origin['id']]);
   if(!$stages)throw new RuntimeException('Template stages missing');
   foreach($stages as $stage)DB::insert('stages',$stage);
   $categories=DB::all('SELECT name,fields_json,default_amount_cents,default_due_hours,
    default_revisions,default_priority,checklist_json,active
    FROM categories WHERE workspace_id=? ORDER BY id',[(int)$origin['id']]);
   foreach($categories as $category)
    DB::insert('categories',$category+['default_assigned_to'=>null]);
  });
  DB::run('UPDATE platform_checkout_orders SET status=?,activated_at=?,updated_at=? WHERE id=?',
   ['active',now(),now(),(int)$order['id']]);
 }
}
