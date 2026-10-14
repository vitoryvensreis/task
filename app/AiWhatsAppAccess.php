<?php
declare(strict_types=1);

/**
 * Passo 2: classifica mensagens recebidas sem assumir a identidade de Auth::$user.
 * Esta decisão é apenas um snapshot para auditoria. Cada ação futura DEVE
 * revalidar usuário, permissões da ação e membros do grupo em tempo real.
 */
final class AiWhatsAppAccess
{
    public static function decide(string $sender,string $chatType,string $chatId,?callable $membersProvider=null): array
    {
        $user=self::resolveUser($sender);
        if($user===null)return self::deny('sender_unverified');

        if($chatType!=='private'&&$chatType!=='group')return self::deny('invalid_chat_type');
        if($user['role']!=='admin'&&(int)$user['ai_assistant_enabled']!==1)return self::deny('permission_disabled');
        $id=(int)$user['id'];

        if($chatType==='private')return ['status'=>'authorized','reason'=>'user_authorized','user_id'=>$id];

        // Do not trust group membership lists copied from the incoming webhook.
        // Check the provider live (or inject a provider in isolated CI tests).
        try{
            $members=$membersProvider? $membersProvider($chatId) : WppConnect::groupMemberIds($chatId);
        }catch(Throwable){
            return self::deny('group_members_unavailable');
        }
        if(!is_array($members)||!$members)return self::deny('group_members_unavailable');
        // Both sender and at least one active TaskEsc admin must be present.
        if(!in_array($sender,$members,true))return self::deny('sender_not_in_group');

        $admins=DB::all("SELECT whatsapp_phone FROM users WHERE active=1 AND role='admin' AND whatsapp_phone IS NOT NULL AND whatsapp_phone<>''");
        foreach($admins as $row){
            $digits=(string)$row['whatsapp_phone'];
            if(!preg_match('/\\A[0-9]{10,15}\\z/D',$digits))continue;
            if(!in_array($digits.'@c.us',$members,true)&&!in_array($digits.'@s.whatsapp.net',$members,true))continue;
            // Two user accounts with the same number must not establish admin presence.
            $matching=self::resolveUser($digits.'@c.us');
            if($matching!==null&&$matching['role']==='admin')return ['status'=>'authorized','reason'=>'group_admin_present','user_id'=>$id];
        }
        return self::deny('group_admin_absent');
    }

    /** Strict number-only matching; WhatsApp LID is NOT a phone number. */
    public static function resolveUser(string $jid): ?array
    {
        if(!preg_match('/\\A([0-9]{10,15})@(c\\.us|s\\.whatsapp\\.net)\\z/D',$jid,$m))return null;
        $matches=DB::all('SELECT id,role,active,ai_assistant_enabled FROM users WHERE whatsapp_phone=? LIMIT 2',[$m[1]]);
        if(count($matches)!==1||(int)$matches[0]['active']!==1)return null;
        if(!in_array($matches[0]['role'],['admin','staff','client'],true))return null;
        return $matches[0];
    }

    /** Never expose private group participants or user numbers to the webhook response. */
    private static function deny(string $reason): array
    {
        return ['status'=>'denied','reason'=>$reason,'user_id'=>null];
    }
}
