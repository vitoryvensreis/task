<?php
declare(strict_types=1);

/**
 * Public commercial interest: assisted sale. No account, subscription or
 * payment is created by this endpoint. Master verifies the customer first.
 */
final class PlatformSales {
 public static function request(array $input): array {
  Auth::rate('platform-sales-lead',5,3600);
  if(trim((string)($input['website']??''))!=='')return ['received'=>true];
  if(empty($input['privacy_consent']))fail('Confirme a leitura da Política de Privacidade.',422);
  $name=trim((string)($input['name']??''));
  $company=trim((string)($input['company']??''));
  $email=strtolower(trim((string)($input['email']??'')));
  $phone=trim((string)($input['phone']??''));
  if(mb_strlen($name)<3||mb_strlen($name)>120||mb_strlen($company)>140||
     !filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||
     !preg_match('/\\A[0-9+ ()-]{8,25}\\z/D',$phone))
   fail('Informe nome, e-mail e telefone de contato válidos.',422);
  $planId=integer($input['plan_id']??0,1);
  $plan=DB::one('SELECT id,name,price_cents,extra_user_price_cents,allow_extra_seats,team_limit,billing_period FROM platform_access_plans
    WHERE id=? AND active=1 AND public_visible=1 AND code<>?',[$planId,'legacy-unlimited']);
  if(!$plan)fail('Plano público não encontrado.',404);
  $extras=integer($input['extra_seats']??0,0,100);
  if($extras>0&&(!(int)$plan['allow_extra_seats']||$plan['team_limit']===null||(int)$plan['extra_user_price_cents']===0))
   fail('O plano selecionado não oferece vagas adicionais.',422);
  $total=PlatformPlans::amount((int)$plan['price_cents'],(int)$plan['extra_user_price_cents'],$extras);
  // Keep lead delivery out of tenant-scoped mail queues. Only verified Masters
  // can receive requests; no client/workspace gets another company's leads.
  $recipients=DB::all("SELECT DISTINCT email FROM platform_master_identities
     WHERE status='verified' AND email<>'' LIMIT 10");
  if(!$recipients)fail('Canal comercial temporariamente indisponível.',503);
  $text="Nova solicitação comercial TaskEsc\nPlano: {$plan['name']} (ID {$plan['id']})\n".
    "Vagas extras: $extras | Total recorrente: R$ ".number_format($total/100,2,',','.')." / {$plan['billing_period']}\n".
    "Contato: $name\nEmpresa: ".($company?:'Não informada')."\nE-mail: $email\nTelefone: $phone\n".
    "Recebido em UTC: ".now()."\n\nResponder ao contato e validar contratação no ADM Master. Nenhuma cobrança foi iniciada.";
  DB::tx(static function()use($recipients,$text):void {
   foreach($recipients as $recipient){
    $to=strtolower(trim((string)$recipient['email']));
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))continue;
    DB::insert('platform_mail_queue',[
      'recipient'=>$to,'subject'=>'TaskEsc — nova solicitação de contratação',
      'body'=>$text,'status'=>'pending','attempts'=>0,
      'next_attempt'=>now(),'created_at'=>now()
    ]);
   }
  });
  return ['received'=>true,'message'=>'Solicitação enviada. Nossa equipe entrará em contato para confirmar os detalhes.'];
 }
}
