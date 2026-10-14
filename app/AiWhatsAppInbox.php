<?php
declare(strict_types=1);

/**
 * Entrada passiva do TaskEsc AI: recebe e deduplica, sem consultar IA,
 * emitir Outbox ou responder no WhatsApp.
 */
final class AiWhatsAppInbox
{
    public static function receive(array $payload): array
    {
        $event=strtolower(trim((string)($payload['event']??$payload['type']??'')));
        if(!in_array($event,['onmessage','on-message','message','message.received'],true)){
            return ['accepted'=>false,'reason'=>'event_ignored'];
        }
        // O WPPConnect pode entregar a mensagem encapsulada em body/data
        // OU diretamente no envelope (body nesse caso e' o texto da mensagem).
        // Nunca tratar o corpo textual como se fosse o objeto da mensagem.
        $message=is_array($payload['body']??null)?$payload['body']
            :(is_array($payload['data']??null)?$payload['data']:$payload);
        if(!is_array($message))return ['accepted'=>false,'reason'=>'invalid_message'];

        $session=WppConnect::sessionName();
        $incomingSession=$payload['session']??$message['session']??null;
        if($incomingSession!==null&&(!is_string($incomingSession)||!hash_equals($session,$incomingSession))){
            return ['accepted'=>false,'reason'=>'session_mismatch'];
        }

        $fromMe=$message['fromMe']??$message['from_me']??$message['key']['fromMe']??false;
        if($fromMe===true||$fromMe===1||$fromMe==='true'||$fromMe==='1'){
            return ['accepted'=>false,'reason'=>'outbound_ignored'];
        }

        $providerId=$message['id']??$message['messageId']??$message['key']['id']??null;
        if(is_array($providerId))$providerId=$providerId['_serialized']??$providerId['id']??null;
        if(!is_string($providerId)||!self::asciiId($providerId,255)){
            // Never derive an identity from text/time: repeated identical messages
            // must be allowed, retries of the same message must be deduplicated.
            return ['accepted'=>false,'reason'=>'missing_message_id'];
        }

        $from=self::address($message['from']??$message['chatId']??$message['chat']['id']??null);
        $chat=self::address($message['chatId']??$message['from']??$message['chat']['id']??null);
        $group=(bool)preg_match('/\A[0-9]{6,25}(?:-[0-9]{1,25})?@g\.us\z/D',$from)
            ||(bool)preg_match('/\A[0-9]{6,25}(?:-[0-9]{1,25})?@g\.us\z/D',$chat);
        $chatId=$group?(str_ends_with($from,'@g.us')?$from:$chat):$from;
        if(!self::validChat($chatId,$group))return ['accepted'=>false,'reason'=>'invalid_chat'];
        if($group){
            $sender=self::address($message['author']??$message['participant']??$message['sender']['id']??$message['key']['participant']??null);
            if(!self::validPerson($sender))return ['accepted'=>false,'reason'=>'missing_group_sender'];
        }else $sender=$from;
        if(!$group&&!self::validPerson($sender))return ['accepted'=>false,'reason'=>'invalid_sender'];

        $type=$message['type']??$message['mimetype']??'chat';
        if($message===$payload&&is_string($type)&&$type===$event)$type='chat';
        if(!is_string($type))$type='unknown';
        $type=strtolower(trim($type));
        if(!preg_match('/\A[a-z0-9_.-]{1,32}\z/D',$type))$type='unknown';
        $body=$message['body']??$message['text']??null;
        if(!is_string($body))$body=null;
        if($body!==null)$body=mb_substr($body,0,8000,'UTF-8');

        $stmt=DB::run(
            "INSERT INTO ai_whatsapp_inbox (session_name,provider_message_id,chat_id,sender_id,chat_type,message_type,body,status,received_at)
             VALUES (?,?,?,?,?,?,?,'received',?)
             ON DUPLICATE KEY UPDATE id=id",
            [$session,$providerId,$chatId,$sender,$group?'group':'private',$type,$body,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]
        );
        $stored=$stmt->rowCount()===1;
        if($stored){
            // The inbox is persisted before potentially slow group-member verification.
            // If the verification cannot complete, pending is NOT an authorization.
            $decision=AiWhatsAppAccess::decide($sender,$group?'group':'private',$chatId);
            DB::run('UPDATE ai_whatsapp_inbox SET auth_user_id=?,auth_status=?,auth_reason=?,auth_checked_at=? WHERE session_name=? AND provider_message_id=? AND auth_status=\'pending\'',
                [$decision['user_id'],$decision['status'],$decision['reason'],
                 (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                 $session,$providerId]);
        }
        return ['accepted'=>true,'stored'=>$stored,'duplicate'=>!$stored,
            'chat_type'=>$group?'group':'private'];
    }

    private static function address(mixed $value): string
    {
        if(is_array($value))$value=$value['_serialized']??$value['id']??null;
        return is_string($value)?trim($value):'';
    }
    private static function validChat(string $address,bool $group): bool
    {
        if($group)return (bool)preg_match('/\A[0-9]{6,25}(?:-[0-9]{1,25})?@g\.us\z/D',$address);
        return self::validPerson($address);
    }
    private static function validPerson(string $address): bool
    {
        return (bool)preg_match('/\A[0-9]{6,32}@(c\.us|s\.whatsapp\.net|lid)\z/D',$address);
    }
    private static function asciiId(string $value,int $max): bool
    {
        return strlen($value)>0&&strlen($value)<=$max
            &&(bool)preg_match('/\A[\x21-\x7E]+\z/D',$value);
    }
}
