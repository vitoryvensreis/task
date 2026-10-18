<?php
declare(strict_types=1);

final class Chat
{
    private const MESSAGE_LIMIT=80;
    private static function ws(string $alias=''): string {return WorkspaceContext::legacyCiFixture()?'':' AND '.($alias!==''?$alias.'.':'').'workspace_id='.(int)Auth::workspaceId();}
    private static function data(array $v): array {return WorkspaceContext::legacyCiFixture()?$v:($v+['workspace_id'=>Auth::workspaceId()]);}
    private const MESSAGE_MAX=4000;
    private const TASK_SUGGESTION_LIMIT=10;
    private const TASK_MENTION_LIMIT=8;

    public static function contacts(): array
    {
        $me=self::guard();
        $myId=(int)$me['id'];
        $rows=DB::all("SELECT u.id,u.name,u.role,u.last_login_at
          FROM users u
          WHERE u.active=1 AND u.role='admin' AND u.id<>?".self::ws('u')." AND u.id IN (SELECT user_id FROM workspace_members WHERE workspace_id=".(int)Auth::workspaceId().")
          ORDER BY u.name,u.id",[$myId]);

        $threads=DB::all("SELECT t.id,t.user_low_id,t.user_high_id,t.updated_at,m.id AS last_message_id,
          m.sender_id AS last_sender_id,m.body AS last_body,m.created_at AS last_at,
          (SELECT COUNT(*) FROM chat_messages x WHERE x.thread_id=t.id AND x.recipient_id=? AND x.read_at IS NULL) AS unread
          FROM chat_threads t
          LEFT JOIN chat_messages m ON m.id=t.last_message_id
          WHERE (t.user_low_id=? OR t.user_high_id=?)".self::ws('t')."",[$myId,$myId,$myId]);

        $byPeer=[];
        foreach($threads as $thread){
            $peer=(int)$thread['user_low_id']===$myId?(int)$thread['user_high_id']:(int)$thread['user_low_id'];
            $byPeer[$peer]=$thread;
        }

        $contacts=[];
        foreach($rows as $row){
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
          WHERE m.thread_id=?".self::ws('m').($before>0?' AND m.id<?':'')." ORDER BY m.id DESC LIMIT ".self::MESSAGE_LIMIT;
        $args=[(int)$thread['id']];if($before>0)$args[]=$before;
        $rows=array_reverse(DB::all($sql,$args));
        self::attachTaskRefs($rows);
        return ['peer'=>self::peerView($peer),'thread_id'=>(int)$thread['id'],'messages'=>$rows];
    }

    public static function tasks(array $p): array
    {
        self::guard();
        $q=trim((string)($p['q']??''));
        if(mb_strlen($q)>120)fail('Pesquisa de task muito longa.',422);
        $like='%'.$q.'%';$prefix=$q.'%';
        $rows=DB::all("SELECT d.id,d.title,d.due_at,d.updated_at,c.name AS client_name,s.name AS stage_name
          FROM demands d
          JOIN clients c ON c.id=d.client_id
          JOIN stages s ON s.id=d.stage_id
          WHERE d.deleted_at IS NULL".self::ws('d')." AND (?='' OR d.title LIKE ? OR c.name LIKE ?)
          ORDER BY CASE WHEN ?<>'' AND d.title LIKE ? THEN 0 ELSE 1 END,d.updated_at DESC,d.id DESC
          LIMIT ".self::TASK_SUGGESTION_LIMIT,[$q,$like,$like,$q,$prefix]);
        return ['tasks'=>array_map(static fn(array $row): array => [
            'id'=>(int)$row['id'],
            'title'=>(string)$row['title'],
            'client_name'=>(string)$row['client_name'],
            'stage_name'=>(string)$row['stage_name'],
            'due_at'=>$row['due_at']??null,
        ],$rows)];
    }

    public static function send(array $p): array
    {
        $me=self::guard();$peer=self::peer(integer($p['peer_id']??0,1),$me);
        $body=required($p,'body',self::MESSAGE_MAX);
        $nonce=strtolower(trim((string)($p['client_nonce']??'')));
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$nonce))fail('Identificador da mensagem inválido.',422);
        $mentions=self::validatedTaskMentions($p['task_mentions']??[],$body);

        $result=DB::tx(function() use($me,$peer,$body,$nonce,$mentions): array {
            $existing=DB::one('SELECT m.*,u.name AS sender_name FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.sender_id=? AND m.client_nonce=?".self::ws('m')." LIMIT 1',[(int)$me['id'],$nonce]);
            if($existing){
                if((int)$existing['recipient_id']!==(int)$peer['id'])fail('Identificador de mensagem já utilizado.',409);
                $rows=[$existing];self::attachTaskRefs($rows);
                return ['message'=>$rows[0],'duplicate'=>true,'push_sent'=>0];
            }

            $thread=self::threadFor((int)$me['id'],(int)$peer['id'],true);
            $created=self::microNow();
            $id=DB::insert('chat_messages',self::data([
                'thread_id'=>(int)$thread['id'],
                'sender_id'=>(int)$me['id'],
                'recipient_id'=>(int)$peer['id'],
                'client_nonce'=>$nonce,
                'body'=>$body,
                'created_at'=>$created,
                'read_at'=>null,
            ]));
            foreach($mentions as $mention)DB::insert('chat_message_tasks',self::data([
                'message_id'=>$id,
                'demand_id'=>(int)$mention['id'],
                'label'=>(string)$mention['label'],
                'created_at'=>$created,
            ]));
            DB::update('chat_threads',(int)$thread['id'],['last_message_id'=>$id,'updated_at'=>$created]);
            $message=[
                'id'=>$id,'thread_id'=>(int)$thread['id'],'sender_id'=>(int)$me['id'],'recipient_id'=>(int)$peer['id'],
                'body'=>$body,'created_at'=>$created,'read_at'=>null,'sender_name'=>(string)$me['name'],'task_refs'=>$mentions
            ];
            ChatRealtime::message($message);
            return ['message'=>$message,'duplicate'=>false,'push_sent'=>0];
        });

        if(empty($result['duplicate'])){
            try{
                $preview=preg_replace('/\s+/u',' ',trim($body))??trim($body);
                $unreadChat=(int)(DB::one('SELECT COUNT(*) AS n FROM chat_messages WHERE recipient_id=? AND read_at IS NULL',[(int)$peer['id']])['n']??0);
                $unreadNotifications=(int)(DB::one('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND seen=0',[(int)$peer['id']])['n']??0);
                $result['push_sent']=Push::notifyUser((int)$peer['id'],[
                    'title'=>'Mensagem de '.(string)$me['name'],
                    'body'=>mb_substr($preview,0,300),
                    'url'=>base('dashboard').'?chat='.(int)$me['id'],
                    'tag'=>'taskesc-chat-message-'.(int)$result['message']['id'],
                    'scope'=>'chat',
                    'chat_peer_id'=>(int)$me['id'],
                    'badge_count'=>$unreadChat+$unreadNotifications,
                ]);
            }catch(Throwable $e){
                $result['push_sent']=0;
                error_log('TaskEsc chat push: '.$e->getMessage());
            }
        }
        return $result;
    }

    public static function read(array $p): array
    {
        $me=self::guard();$peer=self::peer(integer($p['peer_id']??0,1),$me);
        return DB::tx(function() use($me,$peer): array {
            $thread=self::threadFor((int)$me['id'],(int)$peer['id'],false);
            if(!$thread)return ['read'=>0];
            $last=DB::one('SELECT MAX(id) AS id FROM chat_messages WHERE thread_id=? AND recipient_id=? AND sender_id=? AND read_at IS NULL".self::ws()."',[(int)$thread['id'],(int)$me['id'],(int)$peer['id']]);
            $lastId=(int)($last['id']??0);if($lastId<1)return ['read'=>0];
            $when=self::microNow();
            $q=DB::run('UPDATE chat_messages SET read_at=? WHERE thread_id=? AND recipient_id=? AND sender_id=? AND read_at IS NULL".self::ws()."',[$when,(int)$thread['id'],(int)$me['id'],(int)$peer['id']]);
            $count=$q->rowCount();
            if($count>0)ChatRealtime::read((int)$thread['id'],(int)$me['id'],(int)$peer['id'],$lastId);
            return ['read'=>$count,'last_read_id'=>$lastId,'read_at'=>$when];
        });
    }

    private static function validatedTaskMentions(mixed $input,string $body): array
    {
        if($input===null||$input==='')return [];
        if(!is_array($input))fail('Referências de task inválidas.',422);
        if(count($input)>self::TASK_MENTION_LIMIT)fail('Use no máximo '.self::TASK_MENTION_LIMIT.' tasks por mensagem.',422);
        $requested=[];$ids=[];
        foreach($input as $item){
            if(!is_array($item))fail('Referência de task inválida.',422);
            $id=filter_var($item['id']??null,FILTER_VALIDATE_INT);
            $label=trim((string)($item['label']??''));
            if($id===false||(int)$id<1||$label===''||mb_strlen($label)>255)fail('Referência de task inválida.',422);
            if(isset($requested[(int)$id]))continue;
            $requested[(int)$id]=$label;$ids[]=(int)$id;
        }
        if(!$ids)return [];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=DB::all("SELECT id,title FROM demands WHERE deleted_at IS NULL".self::ws()." AND id IN ($marks)",$ids);
        $byId=[];foreach($rows as $row)$byId[(int)$row['id']]=$row;
        $out=[];
        foreach($ids as $id){
            $row=$byId[$id]??null;if(!$row)fail('Uma das tasks linkadas não está mais disponível.',409);
            $title=(string)$row['title'];$label=$requested[$id];
            if($label!==$title)fail('Uma task linkada mudou de nome. Selecione-a novamente.',409);
            if(!str_contains($body,'@'.$title))fail('A mensagem não contém a task selecionada.',422);
            $out[]=['id'=>$id,'label'=>$title,'available'=>true];
        }
        return $out;
    }

    private static function attachTaskRefs(array &$messages): void
    {
        $ids=array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id']??0),$messages)));
        if(!$ids){foreach($messages as &$message)$message['task_refs']=[];unset($message);return;}
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $refs=DB::all("SELECT x.message_id,x.demand_id,x.label,d.deleted_at
          FROM chat_message_tasks x
          LEFT JOIN demands d ON d.id=x.demand_id
          WHERE x.message_id IN ($marks)".self::ws('x')."
          ORDER BY x.message_id,x.demand_id",$ids);
        $byMessage=[];
        foreach($refs as $ref)$byMessage[(int)$ref['message_id']][]=[
            'id'=>(int)$ref['demand_id'],
            'label'=>(string)$ref['label'],
            'available'=>$ref['deleted_at']===null,
        ];
        foreach($messages as &$message)$message['task_refs']=$byMessage[(int)$message['id']]??[];
        unset($message);
    }

    private static function guard(): array
    {
        Auth::load();Auth::requireAbility('chat.use');
        if(Auth::$origin!=='web')fail('O chat fica disponível somente na sessão web autenticada.',403);
        if(Auth::isPreview())fail('O chat não fica disponível no modo de visualização do cliente.',403);
        if((Auth::$user['role']??'')!=='admin')fail('O chat é exclusivo para administradores.',403);
        return Auth::$user;
    }

    private static function peer(int $id,array $me): array
    {
        if($id===(int)$me['id'])fail('Escolha outro usuário para conversar.',422);
        if(!WorkspaceContext::legacyCiFixture())WorkspaceCatalog::requireUser($id,true);$peer=DB::one("SELECT id,name,role,active,last_login_at FROM users WHERE id=? AND active=1 AND role='admin'",[$id]);
        if(!$peer)fail('Administrador não encontrado ou inativo.',404);
        return $peer;
    }

    private static function threadFor(int $a,int $b,bool $create): ?array
    {
        $low=min($a,$b);$high=max($a,$b);
        if($create){
            $now=self::microNow();
            if(WorkspaceContext::legacyCiFixture())DB::run('INSERT INTO chat_threads (user_low_id,user_high_id,created_at,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$low,$high,$now,$now]);else DB::run('INSERT INTO chat_threads (user_low_id,user_high_id,created_at,updated_at,workspace_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$low,$high,$now,$now,Auth::workspaceId()]);
            $id=(int)DB::conn()->lastInsertId();
            if($id>0)return DB::one('SELECT * FROM chat_threads WHERE id=?'.self::ws(),[$id]);
        }
        return DB::one('SELECT * FROM chat_threads WHERE user_low_id=? AND user_high_id=?'.self::ws().' LIMIT 1',[$low,$high]);
    }

    private static function peerView(array $peer): array
    {
        return ['id'=>(int)$peer['id'],'name'=>(string)$peer['name'],'role'=>(string)$peer['role'],'last_login_at'=>$peer['last_login_at']??null];
    }

    private static function microNow(): string
    {
        return now();
    }
}
