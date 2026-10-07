<?php
declare(strict_types=1);

final class Chat
{
    private const MESSAGE_LIMIT=80;
    private const MESSAGE_MAX=4000;

    public static function contacts(): array
    {
        $me=self::guard();
        $myId=(int)$me['id'];
        $rows=DB::all("SELECT u.id,u.name,u.role,u.last_login_at,
          EXISTS(
            SELECT 1 FROM user_clients mine
            JOIN user_clients theirs ON theirs.client_id=mine.client_id
            WHERE mine.user_id=? AND theirs.user_id=u.id
          ) AS shared_client
          FROM users u
          WHERE u.active=1 AND u.id<>?
          ORDER BY u.name,u.id",[$myId,$myId]);

        $threads=DB::all("SELECT t.id,t.user_low_id,t.user_high_id,t.updated_at,m.id AS last_message_id,
          m.sender_id AS last_sender_id,m.body AS last_body,m.created_at AS last_at,
          (SELECT COUNT(*) FROM chat_messages x WHERE x.thread_id=t.id AND x.recipient_id=? AND x.read_at IS NULL) AS unread
          FROM chat_threads t
          LEFT JOIN chat_messages m ON m.id=t.last_message_id
          WHERE t.user_low_id=? OR t.user_high_id=?",[$myId,$myId,$myId]);

        $byPeer=[];
        foreach($threads as $thread){
            $peer=(int)$thread['user_low_id']===$myId?(int)$thread['user_high_id']:(int)$thread['user_low_id'];
            $byPeer[$peer]=$thread;
        }

        $contacts=[];
        foreach($rows as $row){
            if(!self::canPeer($me,$row))continue;
            $id=(int)$row['id'];$thread=$byPeer[$id]??null;
            $contacts[]=[
                'id'=>$id,
                'name'=>(string)$row['name'],
                'role'=>(string)$row['role'],
                'last_login_at'=>$row['last_login_at']??null,
                'thread_id'=>$thread?(int)$thread['id']:null,
                'last_message_id'=>$thread?(int)($thread['last_message_id']??0):null,
                'last_sender_id'=>$thread?(int)($thread['last_sender_id']??0):null,
                'last_body'=>$thread?mb_substr((string)($thread['last_body']??''),0,180):'',
                'last_at'=>$thread['last_at']??null,
                'unread'=>$thread?(int)($thread['unread']??0):0,
            ];
        }

        usort($contacts,static function(array $a,array $b): int {
            $at=(string)($a['last_at']??'');$bt=(string)($b['last_at']??'');
            if($at!==$bt)return $bt<=>$at;
            if((int)$a['unread']!==(int)$b['unread'])return (int)$b['unread']<=>(int)$a['unread'];
            return strcasecmp((string)$a['name'],(string)$b['name']);
        });
        return ['contacts'=>$contacts,'unread_total'=>array_sum(array_column($contacts,'unread'))];
    }

    public static function messages(array $p): array
    {
        $me=self::guard();$peer=self::peer(integer($p['peer_id']??0,1),$me);
        $thread=self::threadFor((int)$me['id'],(int)$peer['id'],false);
        if(!$thread)return ['peer'=>self::peerView($peer),'thread_id'=>null,'messages'=>[]];
        $before=(int)($p['before_id']??0);
        $sql="SELECT m.id,m.thread_id,m.sender_id,m.recipient_id,m.body,m.created_at,m.read_at,u.name AS sender_name
          FROM chat_messages m JOIN users u ON u.id=m.sender_id
          WHERE m.thread_id=?".($before>0?' AND m.id<?':'')." ORDER BY m.id DESC LIMIT ".self::MESSAGE_LIMIT;
        $args=[(int)$thread['id']];if($before>0)$args[]=$before;
        $rows=array_reverse(DB::all($sql,$args));
        return ['peer'=>self::peerView($peer),'thread_id'=>(int)$thread['id'],'messages'=>$rows];
    }

    public static function send(array $p): array
    {
        $me=self::guard();$peer=self::peer(integer($p['peer_id']??0,1),$me);
        $body=required($p,'body',self::MESSAGE_MAX);
        $nonce=strtolower(trim((string)($p['client_nonce']??'')));
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$nonce))fail('Identificador da mensagem inválido.',422);

        return DB::tx(function() use($me,$peer,$body,$nonce): array {
            $existing=DB::one('SELECT m.*,u.name AS sender_name FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.sender_id=? AND m.client_nonce=? LIMIT 1',[(int)$me['id'],$nonce]);
            if($existing)return ['message'=>$existing,'duplicate'=>true];

            $thread=self::threadFor((int)$me['id'],(int)$peer['id'],true);
            $created=self::microNow();
            $id=DB::insert('chat_messages',[
                'thread_id'=>(int)$thread['id'],
                'sender_id'=>(int)$me['id'],
                'recipient_id'=>(int)$peer['id'],
                'client_nonce'=>$nonce,
                'body'=>$body,
                'created_at'=>$created,
                'read_at'=>null,
            ]);
            DB::update('chat_threads',(int)$thread['id'],['last_message_id'=>$id,'updated_at'=>$created]);
            $message=[
                'id'=>$id,'thread_id'=>(int)$thread['id'],'sender_id'=>(int)$me['id'],'recipient_id'=>(int)$peer['id'],
                'body'=>$body,'created_at'=>$created,'read_at'=>null,'sender_name'=>(string)$me['name']
            ];
            ChatRealtime::message($message);
            return ['message'=>$message,'duplicate'=>false];
        });
    }

    public static function read(array $p): array
    {
        $me=self::guard();$peer=self::peer(integer($p['peer_id']??0,1),$me);
        return DB::tx(function() use($me,$peer): array {
            $thread=self::threadFor((int)$me['id'],(int)$peer['id'],false);
            if(!$thread)return ['read'=>0];
            $last=DB::one('SELECT MAX(id) AS id FROM chat_messages WHERE thread_id=? AND recipient_id=? AND sender_id=? AND read_at IS NULL',[(int)$thread['id'],(int)$me['id'],(int)$peer['id']]);
            $lastId=(int)($last['id']??0);if($lastId<1)return ['read'=>0];
            $when=self::microNow();
            $q=DB::run('UPDATE chat_messages SET read_at=? WHERE thread_id=? AND recipient_id=? AND sender_id=? AND read_at IS NULL',[$when,(int)$thread['id'],(int)$me['id'],(int)$peer['id']]);
            $count=$q->rowCount();
            if($count>0)ChatRealtime::read((int)$thread['id'],(int)$me['id'],(int)$peer['id'],$lastId);
            return ['read'=>$count,'last_read_id'=>$lastId,'read_at'=>$when];
        });
    }

    private static function guard(): array
    {
        Auth::load();Auth::requireAbility('chat.use');
        if(Auth::$origin!=='web')fail('O chat fica disponível somente na sessão web autenticada.',403);
        if(Auth::isPreview())fail('O chat não fica disponível no modo de visualização do cliente.',403);
        return Auth::$user;
    }

    private static function peer(int $id,array $me): array
    {
        if($id===(int)$me['id'])fail('Escolha outro usuário para conversar.',422);
        $peer=DB::one('SELECT id,name,role,active,last_login_at FROM users WHERE id=? AND active=1',[$id]);
        if(!$peer)fail('Usuário não encontrado ou inativo.',404);
        $shared=(bool)DB::one('SELECT 1 ok FROM user_clients a JOIN user_clients b ON b.client_id=a.client_id WHERE a.user_id=? AND b.user_id=? LIMIT 1',[(int)$me['id'],$id]);
        $peer['shared_client']=$shared?1:0;
        if(!self::canPeer($me,$peer))fail('Você não possui acesso para conversar com este usuário.',403);
        return $peer;
    }

    private static function canPeer(array $me,array $peer): bool
    {
        $myRole=(string)($me['role']??'');$peerRole=(string)($peer['role']??'');
        if($myRole==='admin'||$peerRole==='admin')return true;
        if($myRole==='staff'&&$peerRole==='staff')return true;
        return !empty($peer['shared_client']);
    }

    private static function threadFor(int $a,int $b,bool $create): ?array
    {
        $low=min($a,$b);$high=max($a,$b);
        if($create){
            $now=self::microNow();
            DB::run('INSERT INTO chat_threads (user_low_id,user_high_id,created_at,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$low,$high,$now,$now]);
            $id=(int)DB::conn()->lastInsertId();
            if($id>0)return DB::one('SELECT * FROM chat_threads WHERE id=?',[$id]);
        }
        return DB::one('SELECT * FROM chat_threads WHERE user_low_id=? AND user_high_id=? LIMIT 1',[$low,$high]);
    }

    private static function peerView(array $peer): array
    {
        return ['id'=>(int)$peer['id'],'name'=>(string)$peer['name'],'role'=>(string)$peer['role'],'last_login_at'=>$peer['last_login_at']??null];
    }

    private static function microNow(): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
