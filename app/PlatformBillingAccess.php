<?php
declare(strict_types=1);

/**
 * Billing entitlements are opt-in: only a REAL production Asaas subscription
 * connected to a paid access plan is subject to the commercial access gate.
 * Both existing workspaces (and sandbox billing) remain untouched.
 *
 * Suspended customers may still log in and read/export their data.
 */
final class PlatformBillingAccess {
 private const PAID=['RECEIVED','CONFIRMED','RECEIVED_IN_CASH'];
 private const DISPUTED=['REFUNDED','REFUND_REQUESTED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE'];
 private const GRACE_DAYS=7;

 public static function state(int $workspaceId): array {
  $normal=['code'=>'not_managed','write_allowed'=>true,'invoice_url'=>null,'due_date'=>null];
  if($workspaceId<1||!PlatformAsaasBilling::ready())return $normal;
  $row=DB::one("SELECT s.id,s.request_status,s.billing_status,s.due_date,s.asaas_subscription_id,
      p.code AS plan_code,p.price_cents AS base_price_cents,p.extra_user_price_cents,p.team_limit,p.billing_period,a.extra_seats
    FROM platform_asaas_subscriptions s
    JOIN workspace_access_plans a ON a.workspace_id=s.workspace_id AND a.plan_id=s.plan_id
    JOIN platform_access_plans p ON p.id=a.plan_id AND p.active=1
    WHERE s.workspace_id=? AND s.environment='production' LIMIT 1",[$workspaceId]);
  if(!$row||$row['plan_code']==='legacy-unlimited')return $normal;
  // Unknown provider state or a pending manual review must never silently
  // become an unlimited entitlement.
  $latest=DB::one("SELECT status,due_date,invoice_url FROM platform_asaas_payments
    WHERE subscription_id=? AND due_date<=UTC_DATE()
    ORDER BY due_date DESC,id DESC LIMIT 1",[(int)$row['id']]);
  $next=DB::one("SELECT due_date,invoice_url FROM platform_asaas_payments
    WHERE subscription_id=? AND due_date>UTC_DATE()
    ORDER BY due_date ASC,id ASC LIMIT 1",[(int)$row['id']]);
  $due=(string)($latest['due_date']??$row['due_date']??'');
  $status=(string)($latest['status']??'PENDING');
  $code='awaiting_payment';$allowed=true;
  if($row['request_status']==='inactive'){$code='paused';$allowed=false;}
  elseif($row['request_status']==='cancelled'){$code='cancelled';$allowed=false;}
  elseif($row['request_status']==='needs_review'){$code='needs_review';$allowed=false;}
  elseif(in_array($status,self::DISPUTED,true)){$code='disputed';$allowed=false;}
  elseif(in_array($status,self::PAID,true)){$code='active';}
  elseif($due!==''&&strtotime($due.' +'.self::GRACE_DAYS.' days')<strtotime(gmdate('Y-m-d'))){
   $code='suspended';$allowed=false;
  } elseif($latest&&$due<gmdate('Y-m-d')){$code='grace';}
  $invoice=(string)($latest['invoice_url']??$next['invoice_url']??'');
  if(!str_starts_with($invoice,'https://'))$invoice='';
  return ['code'=>$code,'write_allowed'=>$allowed,'invoice_url'=>$invoice?:null,
    'due_date'=>$due?:null,'grace_days'=>self::GRACE_DAYS,
   'extra_seats'=>(int)$row['extra_seats'],'base_price_cents'=>(int)$row['base_price_cents'],
   'extra_user_price_cents'=>(int)$row['extra_user_price_cents'],
   'base_team_limit'=>$row['team_limit']===null?null:(int)$row['team_limit'],
   'billing_period'=>(string)$row['billing_period']];
 }
 public static function current(): array {
  Auth::admin();
  return self::state(Auth::workspaceId());
 }
 public static function guardMutation(string $action,string $method): void {
  if($method==='GET'||WorkspaceContext::legacyCiFixture()||MasterSupport::isSupport())return;
  // Essential account/security, notification and support operations remain usable.
  if(preg_match('/\\A(?:account\\.|auth\\.|notifications\\.|support\\.|chat\\.|push\\.|billing\\.)/',$action))return;
  $state=self::state(Auth::workspaceId());
  if(!$state['write_allowed'])
   fail('Assinatura com acesso de edição suspenso. Regularize o pagamento ou solicite atendimento ao suporte.',402,
    ['billing'=>['code'=>$state['code'],'write_allowed'=>false]]);
 }
}
