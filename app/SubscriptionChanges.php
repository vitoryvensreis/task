<?php
declare(strict_types=1);

/**
 * Paid subscription upgrades. A separate one-time Asaas payment settles the
 * current-cycle difference; only after provider confirmation do we update
 * the subscription's future value and local entitlements.
 */
final class SubscriptionChanges {
 private static function owner(): int {
  Auth::admin();
  if(Auth::$origin!=='web'||MasterSupport::isSupport()||
    (string)(Auth::$workspace['role']??'')!=='owner')fail('Apenas o proprietário ADM pode alterar a assinatura.',403);
  return Auth::workspaceId();
 }
 private static function snapshot(int $workspace): array {
  $current=DB::one("SELECT s.id subscription_id,s.plan_id,s.asaas_subscription_id,
    s.asaas_customer_id,s.amount_cents,s.cycle,s.request_status,
    a.extra_seats,p.name plan_name
    FROM platform_asaas_subscriptions s
    JOIN workspace_access_plans a ON a.workspace_id=s.workspace_id AND a.plan_id=s.plan_id
    JOIN platform_access_plans p ON p.id=a.plan_id
    WHERE s.workspace_id=? AND s.environment='production'",[$workspace]);
  if(!$current||$current['request_status']!=='created'||!$current['asaas_subscription_id']||
    !$current['asaas_customer_id']||!PlatformAsaasBilling::enabled('production'))
   fail('É necessária uma assinatura ativa do Asaas para alterar o plano.',409);
  return $current;
 }
 private static function evaluate(int $workspace,array $p): array {
  $current=self::snapshot($workspace);
  $access=PlatformBillingAccess::state($workspace);
  if(($access['code']??'')!=='active')
   fail('Para alterar a assinatura, regularize primeiro as mensalidades existentes.',409);
  if(DB::one("SELECT id FROM platform_subscription_changes WHERE workspace_id=?
    AND status IN ('creating','awaiting_payment','paid','applying','needs_review') LIMIT 1",[$workspace]))
   fail('Já existe uma mudança de assinatura pendente. Conclua o pagamento ou solicite suporte.',409);
  $planId=integer($p['plan_id']??$current['plan_id'],1);
  $seats=integer($p['extra_seats']??$current['extra_seats'],0,100);
  $target=DB::one("SELECT id,name,code,price_cents,extra_user_price_cents,allow_extra_seats,
    billing_period,team_limit,active,public_visible FROM platform_access_plans WHERE id=?",[$planId]);
  if(!$target||(int)$target['active']!==1||(int)$target['public_visible']!==1||$target['code']==='legacy-unlimited'||
     $target['billing_period']!==$current['cycle'])
   fail('Escolha um plano ativo com a mesma periodicidade. Mudanças de ciclo precisam de atendimento.',409);
  if($seats>0&&($target['team_limit']===null||!(int)$target['allow_extra_seats']||
      (int)$target['extra_user_price_cents']<1))
   fail('O plano não permite contratar estas vagas adicionais.',409);
  $count=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members m
    JOIN users u ON u.id=m.user_id JOIN workspaces w ON w.id=m.workspace_id
    WHERE m.workspace_id=? AND m.status='active' AND m.role IN ('admin','staff')
    AND u.active=1 AND m.user_id<>w.owner_user_id",[$workspace])['n']??0);
  if($target['team_limit']!==null&&$count>(int)$target['team_limit']+$seats)
   fail('O novo plano possui menos vagas do que a equipe ativa.',409);
  $targetAmount=PlatformPlans::amount((int)$target['price_cents'],(int)$target['extra_user_price_cents'],$seats);
  $oldAmount=(int)$current['amount_cents'];
  if($targetAmount<=$oldAmount)fail('Reduções de preço e mudança de ciclo serão agendadas pelo suporte, sem cobrança indevida.',409);
  if($planId===(int)$current['plan_id']&&$seats===(int)$current['extra_seats'])
   fail('A assinatura já está nesta configuração.',409);
  return ['old'=>$current,'plan'=>$target,'extra_seats'=>$seats,
    'old_amount_cents'=>$oldAmount,'target_amount_cents'=>$targetAmount,
    'difference_cents'=>$targetAmount-$oldAmount,
    'notice'=>'A diferença integral será cobrada uma única vez agora; o valor novo substituirá o anterior nas próximas mensalidades, sem alterar as faturas já emitidas.'];
 }
 public static function catalog(): array {
  $workspace=self::owner();
  $state=PlatformBillingAccess::state($workspace);
  $subscription=DB::one('SELECT id,asaas_subscription_id,request_status,plan_id
    FROM platform_asaas_subscriptions WHERE workspace_id=? AND environment=?',
    [$workspace,'production']);
  $plans=PlatformPlans::publicList();
  if(!$subscription){
   $assigned=PlatformPlans::workspacePlan($workspace);
   return ['can_start'=>true,'current_plan_id'=>(int)($assigned['id']??0),
    'current_plan_name'=>$assigned['name']??'Sem assinatura','plans'=>$plans,
    'pending'=>null];
  }
  $initial=DB::one("SELECT id,status FROM platform_subscription_changes
    WHERE subscription_id=? AND change_type='initial' ORDER BY id DESC LIMIT 1",
    [(int)$subscription['id']]);
  if($initial&&$initial['status']!=='applied'){
   $url=null;
   if($subscription['asaas_subscription_id']&&$subscription['request_status']==='created'){
    try{
     $payments=PlatformAsaasBilling::api('GET',
      '/v3/subscriptions/'.$subscription['asaas_subscription_id'].'/payments?limit=10&offset=0',null,'production');
     foreach(($payments['data']??[]) as $payment){
      if(is_array($payment)&&str_starts_with((string)($payment['invoiceUrl']??''),'https://')){
       $url=$payment['invoiceUrl'];break;
      }
     }
    }catch(Throwable $e){error_log('Checkout existing invoice lookup: '.get_class($e));}
   }
   return ['can_start'=>false,'awaiting_first_payment'=>true,'pending'=>[
    'status'=>$initial['status'],'invoice_url'=>$url],
    'plans'=>$plans];
  }
  $current=self::snapshot($workspace);
  $pending=DB::one("SELECT id,status,invoice_url,amount_due_cents,target_amount_cents
    FROM platform_subscription_changes WHERE workspace_id=? ORDER BY id DESC LIMIT 1",[$workspace]);
  return ['current_plan_id'=>(int)$current['plan_id'],
    'current_plan_name'=>$current['plan_name'],
    'current_extra_seats'=>(int)$current['extra_seats'],
    'current_amount_cents'=>(int)$current['amount_cents'],
    'billing_period'=>$current['cycle'],'billing_status'=>$state['code'],
    'plans'=>$plans,'pending'=>$pending];
 }
 public static function startInitial(array $p): array {
  $workspace=self::owner();checkCsrf();Auth::rate('initial-paid-plan',3,3600);
  if(strtoupper(trim((string)($p['confirmation']??'')))!=='COMPRAR')
   fail('Digite COMPRAR antes de gerar a assinatura.',422);
  if(!PlatformAsaasBilling::enabled('production'))
   fail('A contratação automática não está habilitada.',503);
  $taxId=preg_replace('/\\D/','',(string)($p['cpf_cnpj']??''));
  if(!in_array(strlen($taxId),[11,14],true))fail('Informe CPF ou CNPJ para cobrança.',422);
  $planId=integer($p['plan_id']??0,1);$extras=integer($p['extra_seats']??0,0,100);
  $plan=DB::one("SELECT id,name,code,price_cents,extra_user_price_cents,
    billing_period,team_limit,allow_extra_seats FROM platform_access_plans
    WHERE id=? AND active=1 AND public_visible=1",[$planId]);
  if(!$plan||$plan['code']==='legacy-unlimited'||(int)$plan['price_cents']<100)
   fail('Selecione um plano público pago.',409);
  if($extras>0&&($plan['team_limit']===null||!(int)$plan['allow_extra_seats']||
     (int)$plan['extra_user_price_cents']<1))fail('Vagas adicionais indisponíveis.',409);
  $used=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members m
    JOIN users u ON u.id=m.user_id JOIN workspaces w ON w.id=m.workspace_id
    WHERE m.workspace_id=? AND m.status='active' AND m.role IN ('admin','staff')
    AND u.active=1 AND m.user_id<>w.owner_user_id",[$workspace])['n']??0);
  if($plan['team_limit']!==null&&$used>(int)$plan['team_limit']+$extras)
   fail('O plano escolhido possui menos vagas que a equipe atual.',409);
  $amount=PlatformPlans::amount((int)$plan['price_cents'],(int)$plan['extra_user_price_cents'],$extras);
  $cycles=['weekly'=>'WEEKLY','biweekly'=>'BIWEEKLY','monthly'=>'MONTHLY',
   'bimonthly'=>'BIMONTHLY','quarterly'=>'QUARTERLY','semiannually'=>'SEMIANNUALLY','yearly'=>'YEARLY'];
  if(!isset($cycles[$plan['billing_period']]))fail('Periodicidade indisponível para contratação.',409);
  $lock='taskesc-initial-sub-'.$workspace;
  if((int)(DB::one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']??0)!==1)
   fail('Já existe uma solicitação de assinatura em andamento.',409);
  try{
   if(DB::one("SELECT 1 FROM platform_asaas_subscriptions
    WHERE workspace_id=? AND environment='production'",[$workspace]))
    fail('Esta empresa já possui uma solicitação Asaas. Consulte Assinaturas.',409);
   $owner=Auth::$actor;
   $subId=DB::tx(static function()use($workspace,$owner,$plan,$extras,$amount):int {
    $id=DB::insert('platform_asaas_subscriptions',[
     'workspace_id'=>$workspace,'plan_id'=>(int)$plan['id'],
     'environment'=>'production','payer_name'=>$owner['name'],'payer_email'=>$owner['email'],
     'amount_cents'=>$amount,'cycle'=>$plan['billing_period'],
     'due_date'=>gmdate('Y-m-d',strtotime('+1 day')),
     'request_status'=>'creating','billing_status'=>'awaiting_payment',
     'created_at'=>now(),'updated_at'=>now()
    ]);
    DB::insert('platform_subscription_changes',[
     'workspace_id'=>$workspace,'subscription_id'=>$id,'requested_by'=>(int)$owner['id'],
     'change_type'=>'initial','target_plan_id'=>(int)$plan['id'],'target_extra_seats'=>$extras,
     'old_amount_cents'=>0,'target_amount_cents'=>$amount,'amount_due_cents'=>0,
     'status'=>'awaiting_payment','created_at'=>now(),'updated_at'=>now()
    ]);
    return $id;
   });
   try{
    $customer=PlatformAsaasBilling::api('POST','/v3/customers',[
     'name'=>$owner['name'],'email'=>$owner['email'],'cpfCnpj'=>$taxId,
     'externalReference'=>'taskesc-initial-workspace-'.$workspace
    ],'production');
    $cid=(string)($customer['id']??'');
    if(!preg_match('/\\Acus_[A-Za-z0-9]+\\z/D',$cid))throw new RuntimeException('Asaas customer invalid');
    DB::run('UPDATE platform_asaas_subscriptions SET asaas_customer_id=? WHERE id=?',[$cid,$subId]);
    $sub=PlatformAsaasBilling::api('POST','/v3/subscriptions',[
     'customer'=>$cid,'billingType'=>'UNDEFINED','value'=>round($amount/100,2),
     'cycle'=>$cycles[$plan['billing_period']],
     'nextDueDate'=>gmdate('Y-m-d',strtotime('+1 day')),
     'description'=>'TaskEsc - '.$plan['name'],
     'externalReference'=>'taskesc-initial-'.$subId
    ],'production');
    $sid=(string)($sub['id']??'');
    if(!preg_match('/\\Asub_[A-Za-z0-9]+\\z/D',$sid))
     throw new RuntimeException('Asaas subscription invalid');
    DB::run('UPDATE platform_asaas_subscriptions
     SET asaas_subscription_id=?,request_status=?,updated_at=? WHERE id=?',
     [$sid,'created',now(),$subId]);
    return ['created'=>true,'awaiting_payment'=>true,
     'message'=>'Assinatura criada. O plano será ativado após o pagamento.'];
   }catch(Throwable $e){
    DB::run('UPDATE platform_asaas_subscriptions SET request_status=?,updated_at=? WHERE id=?',
     ['needs_review',now(),$subId]);
    DB::run('UPDATE platform_subscription_changes SET status=?,updated_at=? WHERE subscription_id=?',
     ['needs_review',now(),$subId]);
    error_log('TaskEsc existing checkout needs review: id='.$subId.' class='.get_class($e));
    fail('A operação exige revisão do Asaas. Não tente criar outra cobrança.',502);
   }
  }finally{DB::one('SELECT RELEASE_LOCK(?) released',[$lock]);}
 }
 public static function activateInitial(int $subscriptionId,array $payment): void {
  if(!in_array((string)($payment['status']??''),['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'],true))
   return;
  $change=DB::one("SELECT id,workspace_id,target_plan_id,target_extra_seats,target_amount_cents
    FROM platform_subscription_changes WHERE subscription_id=? AND change_type='initial'
    AND status='awaiting_payment' FOR UPDATE",[$subscriptionId]);
  if(!$change||(int)round((float)($payment['value']??0)*100)<(int)$change['target_amount_cents'])
   return;
  $limit=DB::one('SELECT team_limit FROM platform_access_plans WHERE id=?',[(int)$change['target_plan_id']]);
  $used=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members m
    JOIN users u ON u.id=m.user_id JOIN workspaces w ON w.id=m.workspace_id
    WHERE m.workspace_id=? AND m.role IN ('admin','staff') AND m.status='active'
    AND u.active=1 AND m.user_id<>w.owner_user_id",[(int)$change['workspace_id']])['n']??0);
  if($limit&&$limit['team_limit']!==null&&$used>(int)$limit['team_limit']+(int)$change['target_extra_seats']){
   DB::run('UPDATE platform_subscription_changes SET status=?,last_error=?,updated_at=? WHERE id=?',
    ['needs_review','team_capacity_changed',now(),(int)$change['id']]);
   return;
  }
  DB::run('UPDATE workspace_access_plans SET plan_id=?,extra_seats=? WHERE workspace_id=?',
   [(int)$change['target_plan_id'],(int)$change['target_extra_seats'],(int)$change['workspace_id']]);
  DB::run('UPDATE platform_subscription_changes SET status=?,paid_at=?,applied_at=?,updated_at=?
    WHERE id=?',['applied',now(),now(),now(),(int)$change['id']]);
 }
 public static function quote(array $p): array {
  $value=self::evaluate(self::owner(),$p);
  return ['old_amount_cents'=>$value['old_amount_cents'],
   'target_amount_cents'=>$value['target_amount_cents'],
   'difference_cents'=>$value['difference_cents'],
   'plan_name'=>$value['plan']['name'],'extra_seats'=>$value['extra_seats'],
   'notice'=>$value['notice']];
 }
 public static function purchase(array $p): array {
  $workspace=self::owner();checkCsrf();Auth::rate('saas-upgrade',4,3600);
  if(strtoupper(trim((string)($p['confirmation']??'')))!=='COMPRAR')
   fail('Digite COMPRAR para confirmar o valor adicional.',422);
  $lock='taskesc-upgrade-'.$workspace;
  if((int)(DB::one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']??0)!==1)
   fail('Outra alteração está em andamento.',409);
  try{
   $q=self::evaluate($workspace,$p);
   // Check external recurring value before creating any extra payment.
   $remote=PlatformAsaasBilling::api('GET','/v3/subscriptions/'.$q['old']['asaas_subscription_id'],null,'production');
   if((int)round((float)($remote['value']??-1)*100)!==$q['old_amount_cents'])
    fail('Há divergência entre o Asaas e a mensalidade atual. Solicite conciliação.',409);
   $id=DB::insert('platform_subscription_changes',[
    'workspace_id'=>$workspace,'subscription_id'=>(int)$q['old']['subscription_id'],
    'requested_by'=>(int)Auth::$actor['id'],'change_type'=>'upgrade',
    'target_plan_id'=>(int)$q['plan']['id'],'target_extra_seats'=>(int)$q['extra_seats'],
    'old_amount_cents'=>$q['old_amount_cents'],'target_amount_cents'=>$q['target_amount_cents'],
    'amount_due_cents'=>$q['difference_cents'],'status'=>'creating',
    'created_at'=>now(),'updated_at'=>now()
   ]);
   try{
    $payment=PlatformAsaasBilling::api('POST','/v3/payments',[
     'customer'=>$q['old']['asaas_customer_id'],
     'billingType'=>'UNDEFINED','value'=>round($q['difference_cents']/100,2),
     'dueDate'=>gmdate('Y-m-d',strtotime('+1 day')),
     'description'=>'TaskEsc — diferença de upgrade da assinatura',
     'externalReference'=>'taskesc-change-'.$id
    ],'production');
    $remoteId=(string)($payment['id']??'');
    $url=(string)($payment['invoiceUrl']??'');
    if(!preg_match('/\Apay_[a-zA-Z0-9]+\z/D',$remoteId)||
      !str_starts_with($url,'https://'))throw new RuntimeException('Invalid Asaas invoice');
    DB::run('UPDATE platform_subscription_changes SET asaas_payment_id=?,invoice_url=?,status=?,updated_at=? WHERE id=?',
      [$remoteId,$url,'awaiting_payment',now(),$id]);
    return ['payment_required'=>true,'change_id'=>$id,'amount_due_cents'=>$q['difference_cents'],
      'target_amount_cents'=>$q['target_amount_cents'],'invoice_url'=>$url];
   }catch(Throwable $e){
    DB::run('UPDATE platform_subscription_changes SET status=?,last_error=?,updated_at=? WHERE id=?',
     ['needs_review','provider_unconfirmed',now(),$id]);
    error_log('TaskEsc upgrade payment needs review: id='.$id.' class='.get_class($e));
    fail('Não foi possível confirmar a cobrança. O suporte verificará no Asaas antes de tentar novamente.',502);
   }
  }finally{DB::one('SELECT RELEASE_LOCK(?) released',[$lock]);}
 }
 /** Called from authenticated payment webhook in the same DB transaction. */
 public static function webhookPayment(string $env,string $eventId,string $event,array $payment): array {
  if($env!=='production')return ['accepted'=>true,'ignored'=>'sandbox'];
  $id=(string)($payment['id']??'');
  if(!preg_match('/\Apay_[a-zA-Z0-9]+\z/D',$id))
   return ['accepted'=>true,'ignored'=>'unknown_payment'];
  return DB::tx(static function()use($env,$eventId,$event,$payment,$id):array {
   $row=DB::one('SELECT id,status,amount_due_cents,subscription_id FROM platform_subscription_changes
    WHERE asaas_payment_id=? FOR UPDATE',[$id]);
   if(!$row)return ['accepted'=>true,'ignored'=>'unrelated'];
   if(DB::one('SELECT 1 FROM platform_asaas_webhook_events WHERE environment=? AND event_id=?',[$env,$eventId]))
    return ['accepted'=>true,'duplicate'=>true];
   DB::run('INSERT INTO platform_asaas_webhook_events
    (environment,event_id,event_type,subscription_id,payload_hash,created_at)
    VALUES (?,?,?,?,?,?)',[$env,$eventId,$event,(int)$row['subscription_id'],
      hash('sha256',json($payment)),now()]);
   $status=(string)($payment['status']??'');
   $amount=(int)round((float)($payment['value']??0)*100);
   if($row['status']==='awaiting_payment'&&$amount>=(int)$row['amount_due_cents']&&
      in_array($status,['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'],true)){
    DB::run('UPDATE platform_subscription_changes SET status=?,paid_at=?,updated_at=? WHERE id=?',
     ['paid',now(),now(),(int)$row['id']]);
   }elseif(in_array($status,['REFUNDED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE'],true)){
    DB::run('UPDATE platform_subscription_changes SET status=?,updated_at=? WHERE id=?',
     ['disputed',now(),(int)$row['id']]);
   }
   return ['accepted'=>true,'upgrade_payment'=>true];
  });
 }
 public static function reconcilePayments(): array {
  if(!PlatformAsaasBilling::enabled('production'))return ['checked'=>0,'confirmed'=>0];
  $rows=DB::all("SELECT id,asaas_payment_id,amount_due_cents FROM platform_subscription_changes
    WHERE status='awaiting_payment' AND asaas_payment_id IS NOT NULL ORDER BY id LIMIT 12");
  $confirmed=0;
  foreach($rows as $row){
   try{
    $payment=PlatformAsaasBilling::api('GET','/v3/payments/'.$row['asaas_payment_id'],null,'production');
    if((string)($payment['id']??'')!==(string)$row['asaas_payment_id']||
       (int)round((float)($payment['value']??0)*100)<(int)$row['amount_due_cents']||
       !in_array((string)($payment['status']??''),['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'],true))continue;
    DB::tx(static function()use($row):void {
     $locked=DB::one('SELECT status FROM platform_subscription_changes WHERE id=? FOR UPDATE',[(int)$row['id']]);
     if($locked&&$locked['status']==='awaiting_payment')
      DB::run('UPDATE platform_subscription_changes SET status=?,paid_at=?,updated_at=? WHERE id=?',
       ['paid',now(),now(),(int)$row['id']]);
    });
    $confirmed++;
   }catch(Throwable $e){
    error_log('TaskEsc upgrade reconciliation error row='.(int)$row['id'].' class='.get_class($e));
   }
  }
  return ['checked'=>count($rows),'confirmed'=>$confirmed];
 }
 public static function applyPaid(): array {
  if(!PlatformAsaasBilling::enabled('production'))return ['checked'=>0];
  $rows=DB::all("SELECT id,workspace_id FROM platform_subscription_changes
    WHERE status IN ('paid','applying') ORDER BY id LIMIT 8");
  $applied=0;
  foreach($rows as $entry){
   $ws=(int)$entry['workspace_id'];
   $lock='taskesc-upgrade-'.$ws;
   if((int)(DB::one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']??0)!==1)continue;
   try{
    $row=DB::one("SELECT c.*,s.asaas_subscription_id,s.request_status,s.plan_id AS current_plan_id,
     s.amount_cents AS current_amount,s.cycle,p.billing_period
     FROM platform_subscription_changes c
     JOIN platform_asaas_subscriptions s ON s.id=c.subscription_id
     JOIN platform_access_plans p ON p.id=c.target_plan_id
     WHERE c.id=?",[(int)$entry['id']]);
    if(!$row||!in_array($row['status'],['paid','applying'],true)||
       $row['request_status']!=='created'||$row['cycle']!==$row['billing_period'])continue;
    $target=DB::one('SELECT team_limit FROM platform_access_plans WHERE id=?',[(int)$row['target_plan_id']]);
    $used=(int)(DB::one("SELECT COUNT(*) n FROM workspace_members m
      JOIN users u ON u.id=m.user_id JOIN workspaces w ON w.id=m.workspace_id
      WHERE m.workspace_id=? AND m.role IN ('admin','staff') AND m.status='active'
      AND u.active=1 AND m.user_id<>w.owner_user_id",[$ws])['n']??0);
    if($target&&$target['team_limit']!==null&&
       $used>(int)$target['team_limit']+(int)$row['target_extra_seats'])
     throw new RuntimeException('Team exceeds target plan capacity');
    $remote=PlatformAsaasBilling::api('GET','/v3/subscriptions/'.$row['asaas_subscription_id'],null,'production');
    $value=(int)round((float)($remote['value']??-1)*100);
    if($value!==(int)$row['target_amount_cents']){
     if($value!==(int)$row['old_amount_cents'])throw new RuntimeException('Subscription changed externally');
     $remote=PlatformAsaasBilling::api('PUT','/v3/subscriptions/'.$row['asaas_subscription_id'],
      ['value'=>round((int)$row['target_amount_cents']/100,2),'updatePendingPayments'=>false],'production');
     if((int)round((float)($remote['value']??-1)*100)!==(int)$row['target_amount_cents'])
      throw new RuntimeException('Asaas update not acknowledged');
    }
    DB::tx(static function()use($row):void {
     DB::run('UPDATE workspace_access_plans SET plan_id=?,extra_seats=? WHERE workspace_id=?',
      [(int)$row['target_plan_id'],(int)$row['target_extra_seats'],(int)$row['workspace_id']]);
     DB::run('UPDATE platform_asaas_subscriptions SET plan_id=?,amount_cents=?,updated_at=? WHERE id=?',
      [(int)$row['target_plan_id'],(int)$row['target_amount_cents'],now(),(int)$row['subscription_id']]);
     DB::run('UPDATE platform_subscription_changes SET status=?,applied_at=?,updated_at=? WHERE id=?',
      ['applied',now(),now(),(int)$row['id']]);
    });
    $applied++;
   }catch(Throwable $e){
    error_log('TaskEsc subscription update needs retry: change='.$entry['id'].' class='.get_class($e));
   }finally{DB::one('SELECT RELEASE_LOCK(?) released',[$lock]);}
  }
  return ['checked'=>count($rows),'applied'=>$applied];
 }
}
