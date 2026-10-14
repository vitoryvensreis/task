<?php
declare(strict_types=1);

/**
 * Authorization snapshot for the passive TaskEsc WhatsApp AI inbox.
 * Every future action MUST recheck identity, group members and TaskEsc abilities.
 */
final class AiWhatsAppAccess
{
    public static function decide(
        string $sender,
        string $chatType,
        string $chatId,
        ?callable $membersProvider=null,
        ?callable $mappingProvider=null
    ): array {
        if($chatType!=='private'&&$chatType!=='group')return self::deny('invalid_chat_type');
        $user=self::resolveUser($sender,$mappingProvider);
        if($user===null)return self::deny('sender_unverified');
        if($user['role']!=='admin'&&(int)$user['ai_assistant_enabled']!==1)return self::deny('permission_disabled');
        $id=(int)$user['id'];
        if($chatType==='private')return ['status'=>'authorized','reason'=>'user_authorized','user_id'=>$id];

        // Participants must come from a fresh authenticated WPPConnect request,
        // never the untrusted participant list of an inbound webhook event.
        try{
            $members=$membersProvider? $membersProvider($chatId) : WppConnect::groupMemberIds($chatId);
        }catch(Throwable){return self::deny('group_members_unavailable');}
        if(!is_array($members)||!$members)return self::deny('group_members_unavailable');
        if(!self::memberVerified($sender,$members,$mappingProvider))return self::deny('sender_not_in_group');

        $admins=DB::all("SELECT whatsapp_phone FROM users WHERE active=1 AND role='admin' AND whatsapp_phone IS NOT NULL AND whatsapp_phone<>''");
        foreach($admins as $row){
            $digits=(string)$row['whatsapp_phone'];
            if(!preg_match('/\\A[0-9]{10,15}\\z/D',$digits))continue;
            // Historical duplicate numbers must never establish admin presence.
            $matched=self::resolveUser($digits.'@c.us');
            if($matched===null||$matched['role']!=='admin')continue;
            if(self::memberVerified($digits.'@c.us',$members,$mappingProvider)){
                return ['status'=>'authorized','reason'=>'group_admin_present','user_id'=>$id];
            }
        }
        return self::deny('group_admin_absent');
    }

    /**
     * A LID is never parsed as a phone. Only use provider-confirmed PN/LID
     * mapping, and only if exactly one active TaskEsc user owns that phone.
     */
    public static function resolveUser(string $jid,?callable $mappingProvider=null): ?array
    {
        if(preg_match('/\\A[0-9]{6,32}@lid\\z/D',$jid)){
            $mapping=self::verifiedMapping($jid,$mappingProvider);
            if($mapping===null)return null;
            $jid=$mapping['phone'];
        }
        if(!preg_match('/\\A([0-9]{10,15})@(c\\.us|s\\.whatsapp\\.net)\\z/D',$jid,$m))return null;
        $rows=DB::all('SELECT id,role,active,ai_assistant_enabled FROM users WHERE whatsapp_phone=? LIMIT 2',[$m[1]]);
        if(count($rows)!==1||(int)$rows[0]['active']!==1)return null;
        if(!in_array($rows[0]['role'],['admin','staff','client'],true))return null;
        return $rows[0];
    }

    private static function memberVerified(string $jid,array $members,?callable $mappingProvider): bool
    {
        // For normal PN identifiers, WhatsApp uses either @c.us or @s.whatsapp.net.
        if(in_array($jid,$members,true))return true;
        if(preg_match('/\\A([0-9]{10,15})@(c\\.us|s\\.whatsapp\\.net)\\z/D',$jid,$m)){
            if(in_array($m[1].'@c.us',$members,true)||in_array($m[1].'@s.whatsapp.net',$members,true))return true;
            $jid=$m[1].'@c.us';
        }
        $mapping=self::verifiedMapping($jid,$mappingProvider);
        if($mapping===null)return false;
        $number=substr($mapping['phone'],0,-5); // strips the literal '@c.us'
        return in_array($mapping['lid'],$members,true)
            ||in_array($mapping['phone'],$members,true)
            ||in_array($number.'@s.whatsapp.net',$members,true);
    }

    /** Both sides must be internally consistent and match the queried JID. */
    private static function verifiedMapping(string $jid,?callable $mappingProvider): ?array
    {
        try{
            $result=$mappingProvider? $mappingProvider($jid):WppConnect::pnLidMapping($jid);
        }catch(Throwable){return null;}
        if(!is_array($result))return null;
        $lid=$result['lid']??null;
        $phone=$result['phone']??null;
        if(!is_string($lid)||!is_string($phone))return null;
        return WppConnect::normalizePnLidMapping([
            'lid'=>['_serialized'=>$lid],
            'phoneNumber'=>['_serialized'=>$phone]
        ],$jid);
    }

    private static function deny(string $reason): array
    {
        return ['status'=>'denied','reason'=>$reason,'user_id'=>null];
    }
}
