<?php
declare(strict_types=1);

final class AutomationPermanentException extends RuntimeException {}

final class AutomationWorker
{
    public const MAX_ATTEMPTS=4;
    private static int $lastScheduleScanAt=0;

    private static function workspaceId(): int {
        if(WorkspaceContext::legacyCiFixture())return 0;
        $explicit=WorkspaceJobScope::currentId();
        if($explicit>0)return $explicit;
        if(WorkspaceTenantMode::enabled())throw new RuntimeException('Explicit workspace required for automation worker.');
        $ws=DB::one('SELECT id FROM workspaces WHERE slug=? AND status=?',
            [WorkspaceFoundation::SLUG,WorkspaceFoundation::STATUS]);
        if(!$ws||(int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n']??0)!==1)
            throw new RuntimeException('Automation worker only supports the staged single workspace.');
        return (int)$ws['id'];
    }
    private static function ws(string $alias=''): string {
        if(WorkspaceContext::legacyCiFixture())return '';
        return ' AND '.($alias!==''?$alias.'.':'').'workspace_id='.(int)self::workspaceId();
    }
    private static function entityWorkspace(string $type,int $id): void {
        if(WorkspaceContext::legacyCiFixture())return;
        $table=['demand'=>'demands','client'=>'clients','invoice'=>'invoices'][$type]??null;
        if($table===null)throw new RuntimeException('Unsupported automation entity.');
        if(!DB::one('SELECT id FROM `'.$table.'` WHERE id=?'.self::ws(),[$id]))
            throw new RuntimeException('Cross-workspace automation entity blocked.');
    }

    public static function tick(int $eventBatch=100,int $runBatch=20): array
    {
        self::workspaceId();
        self::recoverStale();
        $ingested=self::ingestOutbox(max(1,min(500,$eventBatch)));
        $scheduled=self::scheduleDueTriggers();
        $executed=self::executeReady(max(1,min(100,$runBatch)));
        self::heartbeat();
        return ['ingested'=>$ingested,'scheduled'=>$scheduled,'executed'=>$executed,'stats'=>self::stats()];
    }

    public static function ingestOutbox(int $limit=100): int
    {
        return DB::tx(function() use($limit): int {
            $state=DB::one('SELECT * FROM automation_worker_state WHERE id=1 FOR UPDATE');
            if(!$state){
                $max=(int)(DB::one('SELECT COALESCE(MAX(id),0) n FROM event_outbox')['n']??0);
                DB::insert('automation_worker_state',['id'=>1,'last_outbox_id'=>$max,'heartbeat_at'=>now(),'updated_at'=>now()]);
                return 0;
            }
            $last=(int)$state['last_outbox_id'];
            $rows=DB::all('SELECT id,event_hash,event_type,entity_type,entity_id,payload_json,created_at FROM event_outbox WHERE id>?'.self::ws().' ORDER BY id LIMIT '.(int)$limit,[$last]);
            if(!$rows){DB::update('automation_worker_state',1,['heartbeat_at'=>now(),'updated_at'=>now()]);return 0;}
            $supported=array_flip(Automation::triggerEvents());$automations=[];$created=0;$maxId=$last;
            foreach($rows as $event){
                $maxId=max($maxId,(int)$event['id']);
                $type=(string)$event['event_type'];$entityType=(string)$event['entity_type'];
                if(!isset($supported[$type])||in_array($type,Automation::scheduledTriggerEvents(),true))continue;
                if(str_starts_with($type,'demand.')&&$entityType!=='demand')continue;
                if(str_starts_with($type,'client.')&&$entityType!=='client')continue;
                if(str_starts_with($type,'invoice.')&&$entityType!=='invoice')continue;
                try{$payload=json_decode((string)$event['payload_json'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}
                if(!is_array($payload)||!empty($payload['automation_generated']))continue;
                $operationId=strtolower((string)($payload['operation_id']??''));
                if(!preg_match('/\A[a-f0-9]{32}\z/D',$operationId))$operationId=substr(hash('sha256',(string)$event['event_hash'].'|'.$type.'|'.$event['entity_id']),0,32);
                if(!array_key_exists($type,$automations))$automations[$type]=DB::all('SELECT id FROM automations WHERE active=1 AND trigger_event=?'.self::ws().' ORDER BY id',[$type]);
                if(!$automations[$type])continue;
                $context=[
                    'event'=>[
                        'type'=>$type,
                        'entity_type'=>$entityType,
                        'entity_id'=>(int)$event['entity_id'],
                        'operation_id'=>$operationId,
                        'change'=>$payload['change']??null,
                        'created_at'=>$event['created_at'],
                    ],
                    'payload'=>self::safeTriggerPayload($payload),
                ];
                foreach($automations[$type] as $automation){
                    $q=DB::run("INSERT IGNORE INTO automation_runs (automation_id,operation_id,trigger_event,entity_type,entity_id,status,context_json,attempts,available_at,created_at) VALUES (?,?,?,?,?,'pending',?,0,?,?)",[
                        (int)$automation['id'],$operationId,$type,$entityType,(int)$event['entity_id'],json($context),now(),now()
                    ]);
                    $created+=$q->rowCount()>0?1:0;
                }
            }
            DB::update('automation_worker_state',1,['last_outbox_id'=>$maxId,'heartbeat_at'=>now(),'updated_at'=>now()]);
            return $created;
        });
    }

    public static function scheduleDueTriggers(bool $force=false): int
    {
        $nowTs=time();
        if(!$force&&self::$lastScheduleScanAt>0&&($nowTs-self::$lastScheduleScanAt)<30)return 0;
        self::$lastScheduleScanAt=$nowTs;
        $scheduled=Automation::scheduledTriggerEvents();
        if(!$scheduled)return 0;
        $marks=implode(',',array_fill(0,count($scheduled),'?'));
        $automations=DB::all("SELECT id,trigger_event,definition_json FROM automations WHERE active=1 AND trigger_event IN ($marks)".self::ws()." ORDER BY id",$scheduled);
        if(!$automations)return 0;
        $created=0;$utcNow=now();$localTz=new DateTimeZone((string)config('timezone','America/Fortaleza'));
        $localToday=(new DateTimeImmutable('now',$localTz))->format('Y-m-d');
        foreach($automations as $automation){
            $automationId=(int)$automation['id'];$trigger=(string)$automation['trigger_event'];
            try{$definition=json_decode((string)$automation['definition_json'],true,64,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}
            if(!is_array($definition))continue;
            $triggerNode=null;foreach(($definition['nodes']??[]) as $node)if(($node['type']??'')==='trigger'){$triggerNode=$node;break;}
            $cfg=is_array($triggerNode['config']??null)?$triggerNode['config']:[];
            if($trigger==='demand.due_soon'){
                $hours=max(1,min(720,(int)($cfg['hours_before']??24)));
                $until=gmdate('Y-m-d H:i:s',$nowTs+$hours*3600);
                $rows=DB::all("SELECT d.id,d.due_at FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.deleted_at IS NULL AND d.draft=0 AND s.kind NOT IN ('completed','cancelled','archived') AND d.due_at>? AND d.due_at<=? ORDER BY d.due_at,d.id",[$utcNow,$until]);
                foreach($rows as $row)$created+=self::createScheduledRun($automationId,$trigger,'demand',(int)$row['id'],(string)$row['due_at'],['hours_before'=>$hours]);
            }elseif($trigger==='demand.overdue'){
                $rows=DB::all("SELECT d.id,d.due_at FROM demands d JOIN stages s ON s.id=d.stage_id WHERE d.deleted_at IS NULL AND d.draft=0 AND s.kind NOT IN ('completed','cancelled','archived') AND d.due_at<? ORDER BY d.due_at,d.id",[$utcNow]);
                foreach($rows as $row)$created+=self::createScheduledRun($automationId,$trigger,'demand',(int)$row['id'],(string)$row['due_at'],[]);
            }elseif($trigger==='invoice.due_soon'){
                $days=max(1,min(60,(int)($cfg['days_before']??3)));
                $until=(new DateTimeImmutable($localToday,$localTz))->modify('+'.$days.' days')->format('Y-m-d');
                $rows=DB::all("SELECT i.id,i.due_on FROM invoices i WHERE i.deleted_at IS NULL AND i.status<>'cancelled' AND i.due_on>? AND i.due_on<=? AND COALESCE((SELECT SUM(p.amount_cents+COALESCE(p.discount_cents,0)) FROM payments p WHERE p.invoice_id=i.id),0)<i.amount_cents ORDER BY i.due_on,i.id",[$localToday,$until]);
                foreach($rows as $row)$created+=self::createScheduledRun($automationId,$trigger,'invoice',(int)$row['id'],(string)$row['due_on'],['days_before'=>$days]);
            }elseif($trigger==='invoice.overdue'){
                $rows=DB::all("SELECT i.id,i.due_on FROM invoices i WHERE i.deleted_at IS NULL AND i.status<>'cancelled' AND i.due_on<? AND COALESCE((SELECT SUM(p.amount_cents+COALESCE(p.discount_cents,0)) FROM payments p WHERE p.invoice_id=i.id),0)<i.amount_cents ORDER BY i.due_on,i.id",[$localToday]);
                foreach($rows as $row)$created+=self::createScheduledRun($automationId,$trigger,'invoice',(int)$row['id'],(string)$row['due_on'],[]);
            }
        }
        return $created;
    }

    private static function createScheduledRun(int $automationId,string $trigger,string $entityType,int $entityId,string $dueValue,array $payload): int
    {
        $operationId=substr(hash('sha256','scheduled|'.$automationId.'|'.$trigger.'|'.$entityType.'|'.$entityId.'|'.$dueValue),0,32);
        $context=[
            'event'=>[
                'type'=>$trigger,
                'entity_type'=>$entityType,
                'entity_id'=>$entityId,
                'operation_id'=>$operationId,
                'change'=>'scheduled',
                'created_at'=>now(),
            ],
            'payload'=>$payload+['scheduled'=>1,'due_value'=>$dueValue],
        ];
        $q=DB::run("INSERT IGNORE INTO automation_runs (automation_id,operation_id,trigger_event,entity_type,entity_id,status,context_json,attempts,available_at,created_at) VALUES (?,?,?,?,?,'pending',?,0,?,?)",[
            $automationId,$operationId,$trigger,$entityType,$entityId,json($context),now(),now()
        ]);
        return $q->rowCount()>0?1:0;
    }

    public static function executeReady(int $limit=20): int
    {
        $rows=DB::all("SELECT id FROM automation_runs WHERE status IN ('pending','retry') AND available_at<=? AND attempts<?".self::ws()." ORDER BY available_at,id LIMIT ".(int)$limit,[now(),self::MAX_ATTEMPTS]);
        $done=0;foreach($rows as $row){self::executeRun((int)$row['id']);$done++;}
        return $done;
    }

    public static function executeRun(int $runId): void
    {
        $claimed=DB::tx(function() use($runId): ?array {
            $run=DB::one("SELECT r.*,a.active,a.definition_json,a.trigger_event automation_trigger,a.created_by automation_owner FROM automation_runs r JOIN automations a ON a.id=r.automation_id".(WorkspaceContext::legacyCiFixture()?'':' AND a.workspace_id=r.workspace_id')." WHERE r.id=?".self::ws('r')." FOR UPDATE",[$runId]);
            if(!$run||!in_array($run['status'],['pending','retry'],true))return null;
            if(!(int)$run['active']){
                DB::update('automation_runs',$runId,['status'=>'skipped','finished_at'=>now(),'last_error'=>'automation_inactive']);
                return null;
            }
            $attempts=(int)$run['attempts']+1;
            DB::update('automation_runs',$runId,['status'=>'running','attempts'=>$attempts,'started_at'=>$run['started_at']?:now(),'last_error'=>null]);
            $run['attempts']=$attempts;$run['status']='running';return $run;
        });
        if(!$claimed)return;
        try{
            self::entityWorkspace((string)$claimed['entity_type'],(int)$claimed['entity_id']);
            $definition=json_decode((string)$claimed['definition_json'],true,64,JSON_THROW_ON_ERROR);
            $definition=Automation::validateDefinition(is_array($definition)?$definition:[],(string)$claimed['automation_trigger']);
            $context=json_decode((string)$claimed['context_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($context))$context=[];
            $subject=self::subjectContext((string)$claimed['entity_type'],(int)$claimed['entity_id'],$context);
            self::executeGraph($claimed,$definition,$context,$subject);
            DB::update('automation_runs',$runId,['status'=>'success','finished_at'=>now(),'last_error'=>null]);
        }catch(AutomationPermanentException $e){
            DB::update('automation_runs',$runId,['status'=>'failed','finished_at'=>now(),'last_error'=>self::safeError($e)]);
        }catch(Throwable $e){
            $attempts=(int)$claimed['attempts'];
            if($attempts>=self::MAX_ATTEMPTS)DB::update('automation_runs',$runId,['status'=>'failed','finished_at'=>now(),'last_error'=>self::safeError($e)]);
            else{
                $delay=min(300,5*(2**max(0,$attempts-1)));
                DB::update('automation_runs',$runId,['status'=>'retry','available_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'last_error'=>self::safeError($e)]);
            }
        }
    }

    public static function health(int $freshSeconds=90): array
    {
        $issues=Migrations::automationIssues();
        $state=DB::one('SELECT * FROM automation_worker_state WHERE id=1');
        $heartbeat=$state['heartbeat_at']??null;$age=null;
        if($heartbeat)$age=max(0,time()-(strtotime((string)$heartbeat.' UTC')?:time()));
        $fresh=$age!==null&&$age<=$freshSeconds;
        $stats=self::stats();
        return [
            'status'=>!$issues&&$fresh?'ok':'degraded',
            'schema'=>!$issues?'ok':'invalid',
            'issues'=>$issues,
            'heartbeat_at'=>$heartbeat,
            'heartbeat_age_seconds'=>$age,
            'cursor'=>(int)($state['last_outbox_id']??0),
            'queue'=>$stats,
        ];
    }

    public static function stats(): array
    {
        $state=DB::one('SELECT last_outbox_id FROM automation_worker_state WHERE id=1');
        $max=(int)(DB::one('SELECT COALESCE(MAX(id),0) n FROM event_outbox')['n']??0);
        $last=(int)($state['last_outbox_id']??0);
        $runs=DB::one("SELECT
          SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
          SUM(CASE WHEN status='retry' THEN 1 ELSE 0 END) retrying,
          SUM(CASE WHEN status='running' THEN 1 ELSE 0 END) running,
          SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed
          FROM automation_runs")??[];
        return [
            'outbox_lag'=>max(0,$max-$last),
            'pending'=>(int)($runs['pending']??0),
            'retrying'=>(int)($runs['retrying']??0),
            'running'=>(int)($runs['running']??0),
            'failed'=>(int)($runs['failed']??0),
        ];
    }

    private static function executeGraph(array $run,array $definition,array $eventContext,array $demand): void
    {
        $nodes=[];foreach($definition['nodes'] as $node)$nodes[$node['id']]=$node;
        $edges=[];foreach($definition['edges'] as $edge)$edges[$edge['from']][]=$edge;
        $trigger=null;foreach($nodes as $node)if($node['type']==='trigger'){$trigger=$node;break;}
        if(!$trigger)throw new AutomationPermanentException('trigger_missing');
        $queue=[(string)$trigger['id']];$visited=[];$guard=0;
        while($queue){
            if(++$guard>200)throw new AutomationPermanentException('graph_execution_limit');
            $nodeId=array_shift($queue);if(isset($visited[$nodeId]))continue;$visited[$nodeId]=true;
            $node=$nodes[$nodeId]??null;if(!$node)throw new AutomationPermanentException('node_missing');
            $output=self::executeNode($run,$node,$eventContext,$demand);
            if($node['type']==='end')continue;
            $branch=$node['type']==='condition'?(($output['result']??false)?'yes':'no'):'next';
            foreach($edges[$nodeId]??[] as $edge)if(($edge['branch']??'next')===$branch)$queue[]=(string)$edge['to'];
        }
    }

    private static function executeNode(array $run,array $node,array $eventContext,array &$demand): array
    {
        $existing=DB::one('SELECT * FROM automation_run_steps WHERE run_id=? AND node_id=?',[(int)$run['id'],(string)$node['id']]);
        if($existing&&in_array($existing['status'],['success','skipped'],true)){
            try{$out=$existing['output_json']?json_decode((string)$existing['output_json'],true,16,JSON_THROW_ON_ERROR):[];return is_array($out)?$out:[];}catch(Throwable){return [];}
        }
        $input=['type'=>$node['type'],'config'=>$node['config']];
        $stepId=0;
        if($existing){
            $stepId=(int)$existing['id'];DB::update('automation_run_steps',$stepId,['status'=>'running','input_json'=>json($input),'attempts'=>(int)$existing['attempts']+1,'started_at'=>now(),'finished_at'=>null,'last_error'=>null]);
        }else $stepId=DB::insert('automation_run_steps',['run_id'=>(int)$run['id'],'node_id'=>(string)$node['id'],'node_type'=>(string)$node['type'],'status'=>'running','input_json'=>json($input),'attempts'=>1,'started_at'=>now()]);
        try{
            $output=match($node['type']){
                'trigger'=>['event'=>(string)$run['trigger_event']],
                'condition'=>['result'=>self::condition($node['config'],$eventContext,$demand)],
                'action.whatsapp'=>self::whatsapp($run,$node,$eventContext,$demand),
                'action.task'=>AutomationActions::task($run,$node,$demand,$stepId),
                'action.email'=>AutomationActions::email($run,$node,$demand,$eventContext,$stepId),
                'action.push'=>AutomationActions::push($run,$node,$demand,$eventContext,$stepId),
                'action.create_task'=>AutomationActions::createTask($run,$node,$demand,$eventContext,$stepId),
                'end'=>['ended'=>true],
                default=>throw new AutomationPermanentException('node_type_unsupported'),
            };
            $status=($output['skipped']??false)?'skipped':'success';
            DB::update('automation_run_steps',$stepId,['status'=>$status,'output_json'=>json($output),'finished_at'=>now(),'last_error'=>null]);
            return $output;
        }catch(Throwable $e){
            DB::update('automation_run_steps',$stepId,['status'=>'failed','finished_at'=>now(),'last_error'=>self::safeError($e)]);
            throw $e;
        }
    }

    private static function condition(array $config,array $eventContext,array $demand): bool
    {
        $field=(string)$config['field'];$operator=(string)$config['operator'];$expected=$config['value']??null;
        $actual=$field==='event.change'?($eventContext['payload']['change']??$eventContext['event']['change']??null):($demand[$field]??null);
        return match($operator){
            'eq'=>(string)$actual===(string)$expected,
            'neq'=>(string)$actual!==(string)$expected,
            'in'=>in_array((string)$actual,array_map('strval',is_array($expected)?$expected:[]),true),
            'not_in'=>!in_array((string)$actual,array_map('strval',is_array($expected)?$expected:[]),true),
            'is_empty'=>$actual===null||$actual==='',
            'not_empty'=>$actual!==null&&$actual!=='',
            default=>false,
        };
    }

    private static function whatsapp(array $run,array $node,array $eventContext,array $subject): array
    {
        if(($subject['entity_type']??'')==='demand'&&!empty($subject['draft']))return ['skipped'=>true,'reason'=>'task_draft'];
        $recipient=self::recipient($node,$subject);
        if($recipient===null)return ['skipped'=>true,'reason'=>'task_recipient_none'];
        $message=self::renderTemplate((string)$node['config']['message'],$subject,$eventContext);
        if(trim($message)==='')throw new AutomationPermanentException('whatsapp_message_empty');
        $existing=DB::one('SELECT * FROM whatsapp_messages WHERE run_id=? AND node_id=?',[(int)$run['id'],(string)$node['id']]);
        if($existing&&$existing['status']==='sent')return ['sent'=>true,'message_id'=>$existing['provider_message_id']??null];
        if($existing&&$existing['status']==='sending')throw new AutomationPermanentException('whatsapp_delivery_ambiguous');
        if($existing){
            $messageId=(int)$existing['id'];DB::update('whatsapp_messages',$messageId,['status'=>'sending','attempts'=>(int)$existing['attempts']+1,'last_error'=>null]);
        }else{
            $demandId=(int)($subject['demand_id']??0);
            $messageId=DB::insert('whatsapp_messages',[
                'run_id'=>(int)$run['id'],'demand_id'=>$demandId>0?$demandId:null,'node_id'=>(string)$node['id'],
                'recipient_type'=>$recipient['type'],'recipient_user_id'=>$recipient['user_id'],'recipient_client_id'=>$recipient['client_id'],
                'recipient_phone'=>$recipient['phone'],'body'=>$message,'status'=>'sending','attempts'=>1,'created_at'=>now()
            ]);
        }
        try{
            $sent=WppConnect::sendAutomationMessage($recipient['phone'],$message,$recipient['type']==='group');
            DB::update('whatsapp_messages',$messageId,['status'=>'sent','provider_message_id'=>$sent['message_id']??null,'sent_at'=>now(),'last_error'=>null]);
            return ['sent'=>true,'message_id'=>$sent['message_id']??null,'recipient_type'=>$recipient['type']];
        }catch(Throwable $e){
            DB::update('whatsapp_messages',$messageId,['status'=>'failed','last_error'=>self::safeError($e)]);
            throw $e;
        }
    }

    private static function recipient(array $node,array $subject): ?array
    {
        $mode=(string)($node['config']['recipient']??'task');
        if($mode==='task'){
            if(($subject['entity_type']??'')!=='demand')throw new AutomationPermanentException('task_recipient_unavailable');
            $mode=(string)($subject['whatsapp_recipient_mode']??'none');
            if($mode==='assigned_user')$mode='creator'; // compatibilidade com tasks salvas antes da correção
        }
        if($mode==='none')return null;
        if($mode==='group'){
            try{$groupId=WppConnect::normalizeGroupId((string)($node['config']['group_id']??''));}
            catch(Throwable){throw new AutomationPermanentException('whatsapp_group_invalid');}
            return ['type'=>'group','user_id'=>null,'client_id'=>null,'phone'=>$groupId];
        }
        if($mode==='custom'){
            $fromTask=(string)($node['config']['recipient']??'task')==='task';
            $raw=$fromTask?(string)($subject['whatsapp_custom_phone']??''):(string)($node['config']['custom_phone']??'');
            try{$phone=WppConnect::normalizePhone($raw);}
            catch(Throwable){throw new AutomationPermanentException($fromTask?'task_whatsapp_number_invalid':'custom_whatsapp_invalid');}
            return ['type'=>'custom','user_id'=>null,'client_id'=>null,'phone'=>$phone];
        }
        if($mode==='assigned_user'){
            $userId=(int)($subject['assigned_to']??0);if($userId<1)throw new AutomationPermanentException('assigned_user_missing');
            $row=DB::one('SELECT id,name,whatsapp_phone FROM users WHERE id=? AND active=1',[$userId]);
            if(!$row||empty($row['whatsapp_phone']))throw new AutomationPermanentException('assigned_user_whatsapp_missing');
            return ['type'=>'assigned_user','user_id'=>$userId,'client_id'=>null,'phone'=>WppConnect::normalizePhone((string)$row['whatsapp_phone'])];
        }
        if($mode==='creator'){
            $userId=(int)($subject['created_by']??0);if($userId<1)throw new AutomationPermanentException('creator_missing');
            $row=DB::one('SELECT id,name,whatsapp_phone FROM users WHERE id=? AND active=1',[$userId]);
            if(!$row||empty($row['whatsapp_phone']))throw new AutomationPermanentException('creator_whatsapp_missing');
            return ['type'=>'creator','user_id'=>$userId,'client_id'=>null,'phone'=>WppConnect::normalizePhone((string)$row['whatsapp_phone'])];
        }
        if($mode==='client'){
            $clientId=(int)($subject['client_id']??0);if($clientId<1)throw new AutomationPermanentException('client_missing');
            $row=DB::one('SELECT id,name,whatsapp_phone FROM clients WHERE id=? AND active=1',[$clientId]);
            if(!$row||empty($row['whatsapp_phone']))throw new AutomationPermanentException('client_whatsapp_missing');
            return ['type'=>'client','user_id'=>null,'client_id'=>$clientId,'phone'=>WppConnect::normalizePhone((string)$row['whatsapp_phone'])];
        }
        throw new AutomationPermanentException('whatsapp_recipient_invalid');
    }

    public static function renderTemplate(string $template,array $subject,array $eventContext=[]): string
    {
        $entityType=(string)($subject['entity_type']??'demand');
        $demandId=(int)($subject['demand_id']??($entityType==='demand'?($subject['id']??0):0));
        $taskTitle=(string)($subject['task_title']??($entityType==='demand'?($subject['title']??''):''));
        $invoiceAmount=(int)($subject['invoice_amount_cents']??($entityType==='invoice'?($subject['amount_cents']??0):0));
        // Resolve o mesmo link compartilhável da pasta "Entrega final" usado na task.
        // Não cria pastas nem expõe a pasta privada de trabalho em mensagens.
        $driveLink='';
        if(str_contains($template,'{{link do drive}}')){
            $driveLink=trim((string)($subject['final_delivery_link']??''));
            if($driveLink===''&&$demandId>0){
                $driveSubject=$entityType==='demand'?$subject:DB::one('SELECT id,client_id,drive_folder_id FROM demands WHERE id=?',[$demandId]);
                if(is_array($driveSubject)){
                    $finalFile=DB::one("SELECT drive_account_id,drive_file_id FROM files WHERE demand_id=? AND kind='final' AND storage_provider='drive' AND drive_file_id IS NOT NULL AND drive_file_id<>'' ORDER BY created_at DESC,id DESC LIMIT 1",[$demandId]);
                    if($finalFile||!empty($driveSubject['drive_folder_id'])){
                        $driveLink=trim((string)(Google::finalDeliveryShareLink($driveSubject,$finalFile)??''));
                    }
                }
            }
        }
        $vars=[
            '{{task.id}}'=>$demandId>0?(string)$demandId:'',
            '{{task.title}}'=>$taskTitle,
            '{{task.due_at}}'=>(string)($subject['task_due_at']??($entityType==='demand'?($subject['due_at']??'') : '')),
            '{{task.priority}}'=>(string)($subject['task_priority']??($entityType==='demand'?($subject['priority']??'') : '')),
            '{{task.url}}'=>$demandId>0?base('kanban/task/'.$demandId):'',
            '{{link do drive}}'=>$driveLink,
            '{{client.id}}'=>(string)($subject['client_id']??''),
            '{{client.name}}'=>(string)($subject['client_name']??''),
            '{{client.email}}'=>(string)($subject['client_email']??''),
            '{{client.whatsapp}}'=>(string)($subject['client_whatsapp']??''),
            '{{assigned.name}}'=>(string)($subject['assigned_name']??''),
            '{{creator.name}}'=>(string)($subject['creator_name']??''),
            '{{stage.name}}'=>(string)($subject['stage_name']??''),
            '{{invoice.id}}'=>(string)($subject['invoice_id']??''),
            '{{invoice.description}}'=>(string)($subject['invoice_description']??''),
            '{{invoice.due_on}}'=>(string)($subject['invoice_due_on']??''),
            '{{invoice.amount}}'=>$invoiceAmount>0?'R$ '.number_format($invoiceAmount/100,2,',','.'):'',
            '{{invoice.status}}'=>(string)($subject['invoice_status']??''),
            '{{team.name}}'=>(string)($subject['team_name']??''),
            '{{team.email}}'=>(string)($subject['team_email']??''),
        ];
        return mb_substr(strtr($template,$vars),0,4000);
    }

    private static function subjectContext(string $entityType,int $id,array $eventContext=[]): array
    {
        if($entityType==='demand'){
            $row=DB::one("SELECT d.*,d.id demand_id,d.title task_title,d.due_at task_due_at,d.priority task_priority,c.id client_id,c.name client_name,c.email client_email,c.whatsapp_phone client_whatsapp,u.name assigned_name,creator.name creator_name,s.name stage_name FROM demands d JOIN clients c ON c.id=d.client_id JOIN stages s ON s.id=d.stage_id LEFT JOIN users u ON u.id=d.assigned_to LEFT JOIN users creator ON creator.id=d.created_by WHERE d.id=?",[$id]);
            if(!$row)throw new AutomationPermanentException('demand_missing');
            if(($row['whatsapp_recipient_mode']??'')==='assigned_user')$row['whatsapp_recipient_mode']='creator';
            $row['entity_type']='demand';return $row;
        }
        if($entityType==='client'){
            $row=DB::one("SELECT c.*,c.id client_id,c.name client_name,c.email client_email,c.whatsapp_phone client_whatsapp,c.commercial_type commercial_type FROM clients c WHERE c.id=?",[$id]);
            if(!$row)throw new AutomationPermanentException('client_missing');
            $row['entity_type']='client';
            $teamId=(int)($eventContext['payload']['user_id']??0);
            if($teamId>0){
                $team=DB::one('SELECT id,name,email,role,whatsapp_phone FROM users WHERE id=?',[$teamId]);
                if($team){$row['team_user_id']=(int)$team['id'];$row['team_name']=$team['name'];$row['team_email']=$team['email'];$row['team_user_role']=$team['role'];$row['team_whatsapp']=$team['whatsapp_phone'];}
            }
            return $row;
        }
        if($entityType==='invoice'){
            $row=DB::one("SELECT i.*,i.id invoice_id,i.description invoice_description,i.due_on invoice_due_on,i.amount_cents invoice_amount_cents,i.status invoice_status,c.id client_id,c.name client_name,c.email client_email,c.whatsapp_phone client_whatsapp,c.commercial_type commercial_type,d.id demand_id,d.title task_title,d.due_at task_due_at,d.priority task_priority,d.assigned_to,d.created_by,u.name assigned_name,creator.name creator_name,s.name stage_name FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN demands d ON d.id=i.demand_id LEFT JOIN users u ON u.id=d.assigned_to LEFT JOIN users creator ON creator.id=d.created_by LEFT JOIN stages s ON s.id=d.stage_id WHERE i.id=?",[$id]);
            if(!$row)throw new AutomationPermanentException('invoice_missing');
            $row['entity_type']='invoice';return $row;
        }
        throw new AutomationPermanentException('automation_entity_unsupported');
    }

    private static function safeTriggerPayload(array $payload): array
    {
        $allowed=['operation_id','change','previous_project_id','previous_stage_id','comment_id','checklist_id','time_entry_id','change_request_id','file_id','version_no','approval','decision','internal','shared','client_id','user_id','role','scheduled','due_value','hours_before','days_before'];
        $safe=[];foreach($allowed as $key)if(array_key_exists($key,$payload)&&(is_scalar($payload[$key])||$payload[$key]===null))$safe[$key]=$payload[$key];
        return $safe;
    }

    private static function safeError(Throwable $e): string
    {
        $message=strtolower(trim($e->getMessage()));
        if(preg_match('/\A[a-z0-9_.:-]{1,80}\z/D',$message))return $message;
        return $e instanceof AutomationPermanentException?'automation_permanent_error':'automation_step_failed';
    }

    private static function heartbeat(): void
    {
        DB::run('UPDATE automation_worker_state SET heartbeat_at=?,updated_at=? WHERE id=1',[now(),now()]);
    }

    private static function recoverStale(): void
    {
        DB::run("UPDATE automation_runs SET status='retry',available_at=?,last_error='worker_recovered' WHERE status='running' AND started_at<?",[now(),gmdate('Y-m-d H:i:s',time()-300)]);
    }
}
