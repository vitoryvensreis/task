<?php
declare(strict_types=1);
final class AiWhatsAppGroupTrigger {
 public static function named(string $body): bool {
  return (bool)preg_match('/(?<![\p{L}\p{N}_])@?(?:Task|TASK|(?i:TaskEsc))(?![\p{L}\p{N}_])/u',$body);
 }
 public static function addressed(string $body,array $jids,?callable $mention=null): bool {
  if(self::named($body))return true;
  if(!$jids)return false;
  return $mention ? (bool)$mention($jids) : WppConnect::isBotMentioned($jids);
 }
 public static function command(string $body): string {
  $result=preg_replace('/(?<![\p{L}\p{N}_])@?(?:Task|TASK|(?i:TaskEsc))(?![\p{L}\p{N}_])/u','',$body,1);
  return trim((string)$result," \t\n\r\0\x0B,:;.!?-–—");
 }
}
