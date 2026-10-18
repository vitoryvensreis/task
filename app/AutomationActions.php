<?php
declare(strict_types=1);

/** Execução das ações administrativas com efeitos de negócio idempotentes por nó. */
final class AutomationActions
{
    private static function scope(string $alias=''): string {
        if(!WorkspaceTenantMode::enabled())return '';
        $id=WorkspaceJobScope::currentId();
        if($id<1)throw new LogicException('Automation requires an explicit company.');
        return ' AND '.($alias!==''?$alias.'.':'').'workspace_id='.$id;
    }

    private static function stored(int $runId,string $nodeId): ?array
    {
        $step=DB::one('SELECT status,output_json FROM automation_run_steps WHERE run_id=? AND node_id=?'.self::scope(),[$runId,$nodeId]);
        if(!$step||!in_array($step['status'],['success','skipped'],true))return null;
        return $step['output_json']?decode((string)$step['output_json'],[]):[];
    }

    private static function record(int $stepId,array $result): void
    {
        DB::update('automation_run_steps',$stepId,[
            'status'=>!empty($result['skipped'])?'skipped':'success',
            'output_json'=>json($result),'finished_at'=>now(),'last_error'=>null
        ]);
    }

    private static function log(int $id,string $action,array $before,array $after): void
    {
        DB::insert('audit_log',[
            'user_id'=>null,'entity'=>'demand','entity_id'=>$id,'action'=>$action,
            'before_json'=>json($before),'after_json'=>json($after),'origin'=>'automation','created_at'=>now()
        ]);
    }

    public static function task(array $run,array $node,array &$subject,int $stepId): array
    {
        if(($subject['entity_type']??'')!=='demand')throw new AutomationPermanentException('task_action_requires_demand');
        return DB::tx(function() use($run,$node,&$subject,$stepId): array {
            $previous=self::stored((int)$run['id'],(string)$node['id']);if($previous!==null)return $previous;
            $id=(int)$subject['demand_id'];
            $d=DB::one('SELECT * FROM demands WHERE id=? AND deleted_at IS NULL'.self::scope().' FOR UPDATE',[$id]);
            if(!$d||!empty($d['draft']))throw new AutomationPermanentException('task_unavailable_or_draft');
            $operation=(string)$node['config']['operation'];$value=(string)$node['config']['value'];
            $field=match($operation){'stage'=>'stage_id','priority'=>'priority','assign'=>'assigned_to',default=>throw new AutomationPermanentException('task_operation_invalid')};
            if($operation==='stage'){
                $stage=DB::one('SELECT id,kind,name FROM stages WHERE id=? AND active=1'.self::scope(),[(int)$value]);
                if(!$stage)throw new AutomationPermanentException('target_stage_not_available');
                $value=(string)$stage['id'];
            }elseif($operation==='assign'){
                if((int)$value>0){
                    $user=DB::one("SELECT id,role FROM users WHERE id=? AND active=1 AND role IN ('admin','staff')".
                        (WorkspaceTenantMode::enabled()?' AND id IN (SELECT user_id FROM workspace_members WHERE workspace_id='.WorkspaceJobScope::currentId().')':''),[(int)$value]);
                    if(!$user)throw new AutomationPermanentException('assignee_not_available');
                    if($user['role']==='staff'&&!DB::one('SELECT 1 FROM user_clients WHERE user_id=? AND client_id=?'.self::scope(),[(int)$value,(int)$d['client_id']]))throw new AutomationPermanentException('assignee_not_linked_to_client');
                }
            }
            if((string)($d[$field]??'0')===$value){$out=['skipped'=>true,'reason'=>'task_already_has_value','id'=>$id];self::record($stepId,$out);return $out;}
            $row=[$field=>$operation==='priority'?$value:($operation==='assign'&&(int)$value===0?null:(int)$value),'version'=>(int)$d['version']+1,'updated_at'=>now()];
            if($operation==='stage'){
                $row['completed_at']=$stage['kind']==='completed'?($d['completed_at']??now()):null;
                if($stage['kind']==='completed')$row['first_completed_at']=$d['first_completed_at']??now();
                if(in_array($stage['kind'],['planned','production','approval','completed'],true))$row['accepted_at']=$d['accepted_at']??now();
            }
            DB::update('demands',$id,$row);
            if($operation==='stage')DB::insert('transitions',['demand_id'=>$id,'from_stage'=>(int)$d['stage_id'],'to_stage'=>(int)$value,'actor_id'=>(int)$run['automation_owner'],'happened_at'=>now()]);
            self::log($id,'automation.'.$operation,[$field=>$d[$field]],[$field=>$row[$field],'automation_id'=>(int)$run['automation_id']]);
            $subject=array_merge($subject,$row);
            if($operation==='stage')$subject['stage_name']=$stage['name'];
            $event=$operation==='stage'?'demand.stage_changed':'demand.updated';
            DemandRealtime::emit($event,$subject,['change'=>'automation.'.$operation,'automation_generated'=>1]+($operation==='stage'?['previous_stage_id'=>(int)$d['stage_id']]:[]),null);
            $out=['updated'=>true,'id'=>$id,'field'=>$field,'value'=>$row[$field]];self::record($stepId,$out);return $out;
        });
    }

    private static function emailRecipient(array $config,array $subject): string
    {
        $mode=(string)$config['recipient'];
        if($mode==='custom')return (string)($config['custom_email']??'');
        if($mode==='client')return (string)($subject['client_email']??'');
        $id=(int)($subject[$mode==='creator'?'created_by':'assigned_to']??0);
        if($id<1)return '';
        $user=DB::one('SELECT u.email FROM users u WHERE u.id=? AND u.active=1'.
           (WorkspaceTenantMode::enabled()?' AND EXISTS (SELECT 1 FROM workspace_members wm
              WHERE wm.user_id=u.id AND wm.workspace_id='.WorkspaceJobScope::currentId()." AND wm.status='active')":''),
           [$id]);
        return (string)($user['email']??'');
    }

    public static function email(array $run,array $node,array $subject,array $eventContext,int $stepId): array
    {
        return DB::tx(function() use($run,$node,$subject,$eventContext,$stepId): array {
            $previous=self::stored((int)$run['id'],(string)$node['id']);if($previous!==null)return $previous;
            $recipient=strtolower(trim(self::emailRecipient($node['config'],$subject)));
            if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)){$out=['skipped'=>true,'reason'=>'recipient_email_missing'];self::record($stepId,$out);return $out;}
            $title=trim(AutomationWorker::renderTemplate((string)$node['config']['subject'],$subject,$eventContext));
            $body=trim(AutomationWorker::renderTemplate((string)$node['config']['message'],$subject,$eventContext));
            if($title===''||$body==='')throw new AutomationPermanentException('email_template_empty');
            Notify::mail($recipient,$title,$body);
            $out=['queued'=>true,'recipient'=>$recipient];self::record($stepId,$out);return $out;
        });
    }

    private static function pushUsers(array $config,array $subject): array
    {
        $mode=(string)$config['recipient'];$ids=[];
        if($mode==='specific')$ids=[(int)($config['user_id']??0)];
        elseif($mode==='creator')$ids=[(int)($subject['created_by']??0)];
        elseif($mode==='assigned_user')$ids=[(int)($subject['assigned_to']??0)];
        elseif($mode==='client_users'){
            $cid=(int)($subject['client_id']??0);
            if($cid>0)$ids=array_map('intval',array_column(DB::all("SELECT u.id FROM users u JOIN user_clients uc ON uc.user_id=u.id WHERE uc.client_id=? AND u.active=1 AND u.role='client'".self::scope('uc'),[$cid]),'id'));
        }
        $ids=array_values(array_unique(array_filter($ids,fn($n)=>$n>0)));
        if(!$ids)return [];
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        if(WorkspaceTenantMode::enabled())
            return array_map('intval',array_column(DB::all("SELECT u.id FROM users u
                 JOIN workspace_members wm ON wm.user_id=u.id AND wm.status='active'
                 WHERE wm.workspace_id=? AND u.active=1 AND u.id IN ($placeholders)",
                 [WorkspaceJobScope::currentId(),...$ids]),'id'));
        return array_map('intval',array_column(DB::all("SELECT id FROM users WHERE active=1 AND id IN ($placeholders)",$ids),'id'));
    }

    public static function push(array $run,array $node,array $subject,array $eventContext,int $stepId): array
    {
        $config=$node['config'];$title=AutomationWorker::renderTemplate((string)$config['title'],$subject,$eventContext);
        $body=AutomationWorker::renderTemplate((string)$config['message'],$subject,$eventContext);
        if(trim($title)===''||trim($body)==='')throw new AutomationPermanentException('push_template_empty');
        $users=self::pushUsers($config,$subject);$demandId=(int)($subject['demand_id']??0);
        $newUsers=DB::tx(function() use($run,$node,$stepId,$title,$body,$users,$demandId): array {
            if(self::stored((int)$run['id'],(string)$node['id'])!==null)return [];
            $new=[];
            foreach($users as $uid){
                $key='auto-push:'.$run['id'].':'.$node['id'].':'.$uid;
                $args=[$uid,mb_substr($title,0,190),$body,$demandId?:null,$key,now()];
                if(WorkspaceTenantMode::enabled())
                    $q=DB::run('INSERT IGNORE INTO notifications (workspace_id,user_id,title,body,demand_id,dedupe_key,created_at) VALUES (?,?,?,?,?,?,?)',
                      [WorkspaceJobScope::currentId(),...$args]);
                else $q=DB::run('INSERT IGNORE INTO notifications (user_id,title,body,demand_id,dedupe_key,created_at) VALUES (?,?,?,?,?,?)',$args);
                if($q->rowCount())$new[]=$uid;
            }
            self::record($stepId,$users?['notified'=>count($new),'users'=>count($users)]:['skipped'=>true,'reason'=>'no_push_recipients']);return $new;
        });
        foreach($newUsers as $uid){
            try{Push::notifyUser($uid,['title'=>$title,'body'=>$body,'demand_id'=>$demandId?:null,'url'=>base($demandId?'kanban/task/'.$demandId:'dashboard'),'tag'=>'automation-'.$run['id'].'-'.$node['id']]);}
            catch(Throwable $e){error_log('TaskEsc automation push: '.$e->getMessage());}
        }
        return $users?['notified'=>count($newUsers),'users'=>count($users)]:['skipped'=>true,'reason'=>'no_push_recipients'];
    }

    public static function createTask(array $run,array $node,array $subject,array $eventContext,int $stepId): array
    {
        return DB::tx(function() use($run,$node,$subject,$eventContext,$stepId): array {
            $previous=self::stored((int)$run['id'],(string)$node['id']);if($previous!==null)return $previous;
            $cfg=$node['config'];$cid=($cfg['client_mode']==='specific')?(int)$cfg['client_id']:(int)($subject['client_id']??0);
            $client=DB::one('SELECT id,commercial_type FROM clients WHERE id=? AND active=1'.self::scope(),[$cid]);
            $category=DB::one('SELECT id FROM categories WHERE id=? AND active=1'.self::scope(),[(int)$cfg['category_id']]);
            $stage=DB::one('SELECT id FROM stages WHERE active=1'.self::scope().' ORDER BY position,id LIMIT 1');
            if(!$client||!$category||!$stage)throw new AutomationPermanentException('task_creation_reference_missing');
            $owner=(int)($run['automation_owner']??0);
            if(!DB::one('SELECT u.id FROM users u WHERE u.id=?'.
              (WorkspaceTenantMode::enabled()?' AND EXISTS (SELECT 1 FROM workspace_members wm
                WHERE wm.user_id=u.id AND wm.workspace_id='.WorkspaceJobScope::currentId()." AND wm.status='active')":''),
              [$owner]))throw new AutomationPermanentException('automation_owner_missing');
            $title=trim(AutomationWorker::renderTemplate((string)$cfg['title'],$subject,$eventContext));
            $brief=trim(AutomationWorker::renderTemplate((string)$cfg['brief'],$subject,$eventContext));
            if($title===''||$brief==='')throw new AutomationPermanentException('task_creation_template_empty');
            $due=businessDeadline(max(minDeliveryHours(),(int)$cfg['hours_due']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $assigned=null;
            if((int)($subject['client_id']??0)===$cid){
                $candidate=(int)($subject['assigned_to']??0);
                if($candidate>0&&DB::one("SELECT u.id FROM users u
                    LEFT JOIN user_clients uc ON uc.user_id=u.id AND uc.client_id=?".self::scope('uc')."
                    WHERE u.id=? AND u.active=1 AND (u.role='admin' OR (u.role='staff' AND uc.client_id IS NOT NULL))".
                    (WorkspaceTenantMode::enabled()?' AND EXISTS (SELECT 1 FROM workspace_members wm
                      WHERE wm.user_id=u.id AND wm.workspace_id='.WorkspaceJobScope::currentId()." AND wm.status='active')":''),
                    [$cid,$candidate]))$assigned=$candidate;
            }
            $id=DB::insert('demands',[
                'client_id'=>$cid,'project_id'=>null,'category_id'=>(int)$category['id'],'stage_id'=>(int)$stage['id'],
                'title'=>mb_substr($title,0,190),'brief'=>mb_substr($brief,0,20000),'details_json'=>'[]',
                'due_at'=>$due,'priority'=>$cfg['priority'],'assigned_to'=>$assigned,'whatsapp_recipient_mode'=>'creator',
                'billing_mode'=>$client['commercial_type']==='monthly'?'included':'one_off','amount_cents'=>0,
                'commercial_status'=>'pending','draft'=>0,'version'=>1,'created_by'=>$owner,'created_at'=>now(),'updated_at'=>now()
            ]);
            DB::insert('transitions',['demand_id'=>$id,'from_stage'=>null,'to_stage'=>(int)$stage['id'],'actor_id'=>(int)$run['automation_owner'],'happened_at'=>now()]);
            self::log($id,'automation.created',[],['automation_id'=>(int)$run['automation_id'],'source_entity_id'=>(int)$run['entity_id']]);
            $d=DB::one('SELECT * FROM demands WHERE id=?'.self::scope(),[$id]);
            DemandRealtime::emit('demand.created',$d,['change'=>'automation.created','automation_generated'=>1],null);
            $out=['created'=>true,'id'=>$id,'title'=>$title];self::record($stepId,$out);return $out;
        });
    }
}
