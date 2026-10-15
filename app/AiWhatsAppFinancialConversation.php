<?php
declare(strict_types=1);

/**
 * Minimal, safe financial follow-up continuity.
 * Reuses ONLY the immediately preceding authorized question in this private
 * sender/chat, and only after an explicit "which period?" clarification.
 * No free-form history is forwarded to AI or saved in any new table.
 */
final class AiWhatsAppFinancialConversation
{
 public static function normalize(string $text): string {
  $text=mb_strtolower(trim($text),'UTF-8');
  $text=strtr($text,['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e',
   'í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
  $text=preg_replace('/\s+/u',' ',$text)??'';
  $text=preg_replace('/\A@?(?:taskesc|task)[,.:!?\s]+(?=\S)/u','',$text)??$text;
  return trim($text," \t\n\r\0\x0B.,!?");
 }

 /** Parse only unambiguous, read-only pending expense TOTAL requests. */
 public static function pendingRequest(string $text): ?string {
  $s=self::normalize($text);
  $pattern='/\A(?:(?:me\s+)?(?:manda|mostre|mostra|diga|fala)\s+)?(?:o\s+)?(?:total|soma)\s+(?:das?\s+)?(?:despesas(?:\/custos)?|custos(?:\/despesas)?)\s+pendentes(?:\s+(?:esse|este|neste|no)\s+mes)?\z/u';
  if(!preg_match($pattern,$s))return null;
  return preg_match('/\b(?:esse|este|neste|no)\s+mes\z/u',$s)?'month':'needs_period';
 }

 private static function monthArgs(): array {
  $now=new DateTimeImmutable('now',new DateTimeZone((string)config('timezone','America/Fortaleza')));
  return ['from'=>$now->format('Y-m-01'),'to'=>$now->format('Y-m-t'),
   'payment_status'=>'pending','limit'=>10];
 }

 public static function plan(string $message,array $row,int $userId,bool $financialPrivate): ?array {
  if(!$financialPrivate||($row['chat_type']??null)!=='private'||$userId<1)return null;
  $intent=self::pendingRequest($message);
  if($intent==='month')return ['tool'=>'finance_expenses','arguments'=>self::monthArgs()];
  if($intent==='needs_period')
   return ['text'=>'Qual período das despesas pendentes você quer consultar? Por exemplo: este mês.'];

  // A short answer may complete ONLY the most recent authorized, completed
  // question from the same user/chat within ten minutes.
  if(!preg_match('/\A(?:esse|este|neste|no)\s+mes\z/u',self::normalize($message)))return null;
  $previous=DB::one("SELECT body,ai_reply FROM ai_whatsapp_inbox
    WHERE session_name=? AND chat_id=? AND sender_id=? AND chat_type='private'
      AND auth_user_id=? AND auth_status='authorized' AND ai_state='sent'
      AND id<? AND received_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE)
    ORDER BY id DESC LIMIT 1",[
     (string)($row['session_name']??''),(string)($row['chat_id']??''),
     (string)($row['sender_id']??''),$userId,(int)($row['id']??0)
    ]);
  if(!$previous||self::pendingRequest((string)($previous['body']??''))!=='needs_period')return null;
  $reply=self::normalize((string)($previous['ai_reply']??''));
  if(!preg_match('/\bperiodo\b/u',$reply))return null;
  return ['tool'=>'finance_expenses','arguments'=>self::monthArgs()];
 }
}
