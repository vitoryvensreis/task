<?php
declare(strict_types=1);

/**
 * Deterministic admin finance intent: narrow syntax only. All mutations still
 * pass through AiWhatsAppApproval::propose and a second CONFIRMAR code message.
 */
final class AiWhatsAppFinancialWriteIntent
{
 public static function plan(string $body,array $catalog,bool $private): ?array {
  if(!$private||mb_strlen($body,'UTF-8')>180)return null;
  $names=array_column(array_column($catalog,'function'),'name');
  $input=AiWhatsAppFinancialConversation::normalize($body);
  $input=preg_replace('/\A(?:sou\s+(?:o\s+)?administrador[, ]+)\s*/u','',$input)??$input;
  if(in_array('create_expense',$names,true)){
   if(preg_match('/\A(?:adicione|adiciona|adicion[eoa]|adciona|cadastre|cadastra|registre|registra|lance|lanca)\s+(?:uma\s+)?(?:despesa|dispesa|custo)(?:\s+(?:para|pra)\s+mim)?\s+de\s+(?:r\$\s*)?([0-9]{1,6}(?:\.[0-9]{3})*(?:,[0-9]{2})?|[0-9]{1,6}(?:\.[0-9]{2})?)\s+(?:com\s+|de\s+)?([a-z0-9][a-z0-9 ._\/-]{1,70})\z/u',$input,$m)){
    $cents=self::money($m[1]);
    $description=trim($m[2]);
    if($cents!==null&&!preg_match('/\b(?:pague|pago|paga|apague|exclua|edite|mova|cancele|recebi)\b/u',$description)){
     $date=(new DateTimeImmutable('today',new DateTimeZone((string)config('timezone','America/Fortaleza'))))->format('Y-m-d');
     return ['tool'=>'create_expense','arguments'=>[
      'description'=>mb_convert_case($description,MB_CASE_TITLE,'UTF-8'),
      'category'=>'Outros','amount_cents'=>$cents,'spent_on'=>$date]];
    }
   }
  }
  // Without a named client and a payment method, a real cash receipt cannot
  // be reconciled to the existing invoices/payments ledger.
  if(in_array('register_receipt',$names,true)&&preg_match('/\A(?:adicione|adiciona|adciona|registre|registra|lance|lanca)\s+(?:uma\s+)?(?:entrada|receita|recebimento)(?:\s+(?:para|pra)\s+mim)?\s+de\s+(?:r\$\s*)?[0-9.,]+(?:\s+\S.{0,80})?\z/u',$input)){
   if(!preg_match('/\b(?:pix|dinheiro|transferencia|cartao|boleto)\b/u',$input)
      ||!preg_match('/\b(?:do|da|cliente)\s+\S+/u',$input))
    return ['text'=>'Consigo registrar uma entrada recebida no TaskEsc. Informe o cliente, a descrição, o valor, a data e a forma de recebimento (ex.: Pix). Vou preparar o lançamento para sua confirmação, sem registrar nada antes dela.'];
  }
  return null;
 }
 private static function money(string $raw): ?int {
  if(str_contains($raw,',')){$raw=str_replace('.','',$raw);[$units,$decimals]=explode(',',$raw,2);}
  elseif(preg_match('/\.[0-9]{2}\z/',$raw))[$units,$decimals]=explode('.',$raw,2);
  else{$units=str_replace('.','',$raw);$decimals='00';}
  if(!ctype_digit($units)||!ctype_digit($decimals)||strlen($decimals)!==2)return null;
  $cents=(int)$units*100+(int)$decimals;
  return $cents>0&&$cents<=100000000?$cents:null;
 }
}
