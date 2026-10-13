<?php
declare(strict_types=1);

final class Automation
{
    public const TRIGGERS=[
        'demand.created'=>'Task criada',
        'demand.updated'=>'Task editada',
        'demand.deleted'=>'Task excluída',
        'demand.stage_changed'=>'Task mudou de coluna',
        'demand.reordered'=>'Task reordenada',
        'demand.comment_created'=>'Comentário adicionado',
        'demand.checklist_changed'=>'Checklist alterado',
        'demand.time_logged'=>'Horas lançadas',
        'demand.change_changed'=>'Alteração de escopo',
        'demand.file_added'=>'Arquivo enviado',
        'demand.final_delivered'=>'Envio de entrega final',
        'demand.file_deleted'=>'Arquivo excluído',
        'demand.file_reviewed'=>'Versão aprovada/reprovada',
        'demand.due_soon'=>'Task próxima da data de entrega',
        'demand.overdue'=>'Task atrasada',
        'client.created'=>'Cliente criado',
        'client.team_member_added'=>'Conta da equipe criada para cliente',
        'invoice.due_soon'=>'Cobrança próxima do vencimento',
        'invoice.overdue'=>'Cobrança atrasada',
    ];

    private const TRIGGER_GROUPS=[
        'demand.created'=>'Tasks','demand.updated'=>'Tasks','demand.deleted'=>'Tasks','demand.stage_changed'=>'Tasks',
        'demand.reordered'=>'Tasks','demand.comment_created'=>'Tasks','demand.checklist_changed'=>'Tasks',
        'demand.time_logged'=>'Tasks','demand.change_changed'=>'Tasks','demand.file_added'=>'Tasks',
        'demand.file_deleted'=>'Tasks','demand.file_reviewed'=>'Tasks','demand.final_delivered'=>'Tasks','demand.due_soon'=>'Tasks','demand.overdue'=>'Tasks',
        'client.created'=>'Clientes','client.team_member_added'=>'Clientes',
        'invoice.due_soon'=>'Financeiro','invoice.overdue'=>'Financeiro',
    ];

    private const SCHEDULED_TRIGGERS=['demand.due_soon','demand.overdue','invoice.due_soon','invoice.overdue'];

    private const CONDITION_FIELDS=[
        'client_id','project_id','category_id','stage_id','priority','assigned_to',
        'whatsapp_recipient_mode','draft','commercial_status','commercial_type',
        'invoice_status','due_on','team_user_role','event.change'
    ];
    private const CONDITION_OPERATORS=['eq','neq','in','not_in','is_empty','not_empty'];

    public static function catalog(): array
    {
        Auth::admin();
        return [
            'triggers'=>array_map(fn($key,$label)=>[
                'value'=>$key,
                'label'=>$label,
                'group'=>self::TRIGGER_GROUPS[$key]??'Outros',
                'scheduled'=>in_array($key,self::SCHEDULED_TRIGGERS,true),
            ],array_keys(self::TRIGGERS),array_values(self::TRIGGERS)),
            'node_types'=>[
                ['value'=>'trigger','label'=>'Gatilho'],
                ['value'=>'condition','label'=>'Condição'],
                ['value'=>'action.whatsapp','label'=>'Enviar WhatsApp'],
                ['value'=>'action.task','label'=>'Alterar task'],
                ['value'=>'action.email','label'=>'Enviar e-mail'],
                ['value'=>'action.push','label'=>'Notificação push'],
                ['value'=>'action.create_task','label'=>'Criar task'],
                ['value'=>'end','label'=>'Fim'],
            ],
            'condition_fields'=>self::CONDITION_FIELDS,
            'condition_operators'=>self::CONDITION_OPERATORS,
            'variables'=>[
                '{{task.id}}','{{task.title}}','{{task.due_at}}','{{task.priority}}',
                '{{task.url}}','{{link do drive}}','{{client.id}}','{{client.name}}','{{client.email}}','{{client.whatsapp}}',
                '{{assigned.name}}','{{creator.name}}','{{stage.name}}',
                '{{invoice.id}}','{{invoice.description}}','{{invoice.due_on}}','{{invoice.amount}}','{{invoice.status}}',
                '{{team.name}}','{{team.email}}'
            ],
        ];
    }

    public static function list(array $p=[]): array
    {
        Auth::admin();
        $q=trim((string)($p['q']??''));
        $params=[];$where='';
        if($q!==''){$where=' WHERE a.name LIKE ?';$params[]='%'.$q.'%';}
        $rows=DB::all("SELECT a.*,u.name created_by_name,
          (SELECT COUNT(*) FROM automation_runs r WHERE r.automation_id=a.id) run_count,
          (SELECT MAX(r.created_at) FROM automation_runs r WHERE r.automation_id=a.id) last_run_at,
          (SELECT r.status FROM automation_runs r WHERE r.automation_id=a.id ORDER BY r.id DESC LIMIT 1) last_run_status
          FROM automations a JOIN users u ON u.id=a.created_by".$where." ORDER BY a.updated_at DESC,a.id DESC",$params);
        foreach($rows as &$row){unset($row['definition_json']);$row['active']=(int)$row['active'];$row['version']=(int)$row['version'];}
        return $rows;
    }

    public static function get(array $p): array
    {
        Auth::admin();$id=integer($p['id']??0,1);
        $row=DB::one('SELECT a.*,u.name created_by_name FROM automations a JOIN users u ON u.id=a.created_by WHERE a.id=?',[$id]);
        if(!$row)fail('Automação não encontrada.',404);
        $row['definition']=self::decodeDefinition((string)$row['definition_json']);
        unset($row['definition_json']);
        $row['runs']=DB::all("SELECT r.id,r.operation_id,r.trigger_event,r.entity_type,r.entity_id,r.status,r.attempts,r.started_at,r.finished_at,r.last_error,r.created_at,
          CASE WHEN r.entity_type='demand' THEN d.title WHEN r.entity_type='client' THEN c.name WHEN r.entity_type='invoice' THEN i.description ELSE NULL END subject_label
          FROM automation_runs r
          LEFT JOIN demands d ON d.id=r.entity_id AND r.entity_type='demand'
          LEFT JOIN clients c ON c.id=r.entity_id AND r.entity_type='client'
          LEFT JOIN invoices i ON i.id=r.entity_id AND r.entity_type='invoice'
          WHERE r.automation_id=? ORDER BY r.id DESC LIMIT 30",[$id]);
        return $row;
    }

    public static function save(array $p): array
    {
        Auth::admin();
        $id=(int)($p['id']??0);$old=$id?DB::one('SELECT * FROM automations WHERE id=?',[$id]):null;
        if($id&&!$old)fail('Automação não encontrada.',404);
        if($old&&integer($p['version']??0,1)!==(int)$old['version'])fail('A automação foi alterada em outro acesso. Atualize antes de salvar.',409);
        $trigger=enumv((string)($p['trigger_event']??($old['trigger_event']??'')),array_keys(self::TRIGGERS),'gatilho');
        $name=required($p,'name',190);
        $definition=$p['definition']??null;
        if(is_string($definition)){
            try{$definition=json_decode($definition,true,64,JSON_THROW_ON_ERROR);}catch(Throwable){fail('Fluxo da automação inválido.',422);}
        }
        if(!is_array($definition)){
            $definition=[
                'version'=>1,
                'nodes'=>[
                    ['id'=>'trigger','type'=>'trigger','config'=>['event'=>$trigger]],
                    ['id'=>'end','type'=>'end','config'=>[]],
                ],
                'edges'=>[['from'=>'trigger','to'=>'end','branch'=>'next']],
            ];
        }
        $definition=self::validateDefinition($definition,$trigger);
        $active=(int)(bool)($p['active']??($old['active']??0));
        $now=now();
        $row=['name'=>$name,'trigger_event'=>$trigger,'definition_json'=>json($definition),'active'=>$active,'updated_at'=>$now];
        if($old){$row['version']=(int)$old['version']+1;DB::update('automations',$id,$row);}
        else{$row+=['version'=>1,'created_by'=>(int)Auth::$user['id'],'created_at'=>$now];$id=DB::insert('automations',$row);}
        audit('automation',$id,$old?'updated':'created',$old?['name'=>$old['name'],'trigger_event'=>$old['trigger_event'],'active'=>$old['active'],'version'=>$old['version']]:null,['name'=>$name,'trigger_event'=>$trigger,'active'=>$active,'version'=>$row['version']]);
        return ['id'=>$id,'version'=>(int)$row['version'],'active'=>$active];
    }

    public static function toggle(array $p): array
    {
        Auth::admin();$id=integer($p['id']??0,1);
        $row=DB::one('SELECT id,active,version FROM automations WHERE id=?',[$id]);if(!$row)fail('Automação não encontrada.',404);
        $active=(int)(bool)($p['active']??0);
        DB::update('automations',$id,['active'=>$active,'version'=>(int)$row['version']+1,'updated_at'=>now()]);
        audit('automation',$id,$active?'activated':'paused',['active'=>(int)$row['active']],['active'=>$active]);
        return ['saved'=>true,'active'=>$active,'version'=>(int)$row['version']+1];
    }

    public static function runs(array $p): array
    {
        Auth::admin();$id=integer($p['id']??0,1);
        if(!DB::one('SELECT id FROM automations WHERE id=?',[$id]))fail('Automação não encontrada.',404);
        return DB::all("SELECT r.*,
          CASE WHEN r.entity_type='demand' THEN d.title WHEN r.entity_type='client' THEN c.name WHEN r.entity_type='invoice' THEN i.description ELSE NULL END subject_label
          FROM automation_runs r
          LEFT JOIN demands d ON d.id=r.entity_id AND r.entity_type='demand'
          LEFT JOIN clients c ON c.id=r.entity_id AND r.entity_type='client'
          LEFT JOIN invoices i ON i.id=r.entity_id AND r.entity_type='invoice'
          WHERE r.automation_id=? ORDER BY r.id DESC LIMIT 100",[$id]);
    }

    public static function validateDefinition(array $definition,string $trigger): array
    {
        $nodes=$definition['nodes']??null;$edges=$definition['edges']??null;
        if(!is_array($nodes)||!is_array($edges)||!$nodes||count($nodes)>100||count($edges)>200)fail('Fluxo da automação inválido.',422);
        $cleanNodes=[];$ids=[];$triggerCount=0;
        foreach($nodes as $node){
            if(!is_array($node))fail('Nó de automação inválido.',422);
            $id=(string)($node['id']??'');$type=(string)($node['type']??'');$config=$node['config']??[];
            if(!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$id)||isset($ids[$id]))fail('Identificador de nó inválido ou duplicado.',422);
            if(!in_array($type,['trigger','condition','action.whatsapp','action.task','action.email','action.push','action.create_task','end'],true)||!is_array($config))fail('Tipo de nó inválido.',422);
            if($type==='trigger'){
                $triggerCount++;if(($config['event']??'')!==$trigger)fail('O gatilho do fluxo não corresponde ao gatilho da automação.',422);
                $cleanTrigger=['event'=>$trigger];
                if($trigger==='demand.due_soon')$cleanTrigger['hours_before']=integer($config['hours_before']??24,1,720);
                if($trigger==='invoice.due_soon')$cleanTrigger['days_before']=integer($config['days_before']??3,1,60);
                $config=$cleanTrigger;
            }elseif($type==='condition'){
                $field=(string)($config['field']??'');$operator=(string)($config['operator']??'eq');
                if(!in_array($field,self::CONDITION_FIELDS,true)||!in_array($operator,self::CONDITION_OPERATORS,true))fail('Condição inválida.',422);
                $value=$config['value']??null;if(in_array($operator,['in','not_in'],true)&&!is_array($value))fail('A condição IN precisa de uma lista.',422);
                $config=['field'=>$field,'operator'=>$operator,'value'=>$value];
            }elseif($type==='action.whatsapp'){
                $recipient=(string)($config['recipient']??'task');
                if(!in_array($recipient,['task','custom','assigned_user','creator','client','group'],true))fail('Destinatário do WhatsApp inválido.',422);
                if(!str_starts_with($trigger,'demand.')&&in_array($recipient,['task','assigned_user','creator'],true))fail('Este gatilho não possui uma task para usar esse destinatário.',422);
                $message=trim((string)($config['message']??''));if($message===''||mb_strlen($message)>4000)fail('Informe a mensagem do WhatsApp com até 4000 caracteres.',422);
                $clean=['recipient'=>$recipient,'message'=>$message];
                if($recipient==='custom'){
                    try{$clean['custom_phone']=WppConnect::normalizePhone((string)($config['custom_phone']??''));}
                    catch(Throwable){fail('Informe um número específico de WhatsApp válido com DDI + DDD + número.',422);}
                }
                if($recipient==='group'){
                    $clean['group_id']=WppConnect::normalizeGroupId((string)($config['group_id']??''));
                }
                $config=$clean;
            }elseif($type==='action.task'){
                if(!str_starts_with($trigger,'demand.')||$trigger==='demand.deleted')fail('Alterar task exige um gatilho de task existente.',422);
                $operation=enumv((string)($config['operation']??''),['stage','priority','assign'],'tipo de alteração');
                $value=(string)($config['value']??'');
                if($operation==='priority')$value=enumv($value,['low','normal','high','urgent'],'prioridade');
                else $value=(string)integer($value,$operation==='assign'?0:1);
                $config=['operation'=>$operation,'value'=>$value];
            }elseif($type==='action.email'){
                $recipient=enumv((string)($config['recipient']??''),['client','creator','assigned_user','custom'],'destinatário do e-mail');
                if(!str_starts_with($trigger,'demand.')&&in_array($recipient,['creator','assigned_user'],true))fail('Este gatilho não possui criador/responsável da task.',422);
                $subject=required($config,'subject',190);$body=required($config,'message',4000);
                $config=['recipient'=>$recipient,'subject'=>$subject,'message'=>$body];
                if($recipient==='custom'){
                    $email=strtolower(trim((string)($node['config']['custom_email']??'')));
                    if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Informe um e-mail válido.',422);
                    $config['custom_email']=$email;
                }
            }elseif($type==='action.push'){
                $recipient=enumv((string)($config['recipient']??''),['creator','assigned_user','client_users','specific'],'destinatário push');
                if(!str_starts_with($trigger,'demand.')&&in_array($recipient,['creator','assigned_user'],true))fail('Este gatilho não possui criador/responsável da task.',422);
                $config=['recipient'=>$recipient,'title'=>required($config,'title',120),'message'=>required($config,'message',360)];
                if($recipient==='specific')$config['user_id']=integer($node['config']['user_id']??0,1);
            }elseif($type==='action.create_task'){
                $mode=enumv((string)($config['client_mode']??'related'),['related','specific'],'cliente de destino');
                $config=[
                    'client_mode'=>$mode,'category_id'=>integer($config['category_id']??0,1),
                    'title'=>required($config,'title',190),'brief'=>required($config,'brief',20000),
                    'hours_due'=>integer($config['hours_due']??24,1,720),
                    'priority'=>enumv((string)($config['priority']??'normal'),['low','normal','high','urgent'],'prioridade')
                ];
                if($mode==='specific')$config['client_id']=integer($node['config']['client_id']??0,1);
            }else $config=[];
            $ids[$id]=true;$cleanNodes[]=['id'=>$id,'type'=>$type,'config'=>$config];
        }
        if($triggerCount!==1)fail('O fluxo precisa ter exatamente um gatilho.',422);
        $cleanEdges=[];$adj=[];
        foreach($edges as $edge){
            if(!is_array($edge))fail('Conexão de automação inválida.',422);
            $from=(string)($edge['from']??'');$to=(string)($edge['to']??'');$branch=(string)($edge['branch']??'next');
            if(!isset($ids[$from],$ids[$to])||$from===$to||!in_array($branch,['next','yes','no'],true))fail('Conexão de automação inválida.',422);
            $cleanEdges[]=['from'=>$from,'to'=>$to,'branch'=>$branch];$adj[$from][]=$to;
        }
        $visiting=[];$visited=[];
        $visit=function(string $id) use (&$visit,&$visiting,&$visited,$adj): void {
            if(isset($visiting[$id]))fail('O fluxo não pode conter ciclos nesta versão.',422);
            if(isset($visited[$id]))return;$visiting[$id]=true;
            foreach($adj[$id]??[] as $next)$visit($next);
            unset($visiting[$id]);$visited[$id]=true;
        };
        foreach(array_keys($ids) as $id)$visit($id);
        $triggerNode=array_values(array_filter($cleanNodes,fn($n)=>$n['type']==='trigger'))[0];
        if(!isset($adj[$triggerNode['id']]))fail('O gatilho precisa estar conectado a pelo menos um nó.',422);
        return ['version'=>1,'nodes'=>$cleanNodes,'edges'=>$cleanEdges];
    }

    public static function triggerEvents(): array {return array_keys(self::TRIGGERS);}
    public static function scheduledTriggerEvents(): array {return self::SCHEDULED_TRIGGERS;}
    public static function triggerGroup(string $event): string {return self::TRIGGER_GROUPS[$event]??'Outros';}

    private static function decodeDefinition(string $json): array
    {
        try{$value=json_decode($json,true,64,JSON_THROW_ON_ERROR);return is_array($value)?$value:[];}catch(Throwable){return [];}
    }
}
