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
        'demand.file_deleted'=>'Arquivo excluído',
        'demand.file_reviewed'=>'Versão aprovada/reprovada',
    ];

    private const CONDITION_FIELDS=[
        'client_id','project_id','category_id','stage_id','priority','assigned_to',
        'whatsapp_recipient_mode','draft','commercial_status','event.change'
    ];
    private const CONDITION_OPERATORS=['eq','neq','in','not_in','is_empty','not_empty'];

    public static function catalog(): array
    {
        Auth::admin();
        return [
            'triggers'=>array_map(fn($key,$label)=>['value'=>$key,'label'=>$label],array_keys(self::TRIGGERS),array_values(self::TRIGGERS)),
            'node_types'=>[
                ['value'=>'trigger','label'=>'Gatilho'],
                ['value'=>'condition','label'=>'Condição'],
                ['value'=>'action.whatsapp','label'=>'Enviar WhatsApp'],
                ['value'=>'end','label'=>'Fim'],
            ],
            'condition_fields'=>self::CONDITION_FIELDS,
            'condition_operators'=>self::CONDITION_OPERATORS,
            'variables'=>[
                '{{task.id}}','{{task.title}}','{{task.due_at}}','{{task.priority}}',
                '{{task.url}}','{{client.name}}','{{assigned.name}}','{{creator.name}}','{{stage.name}}'
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
        $row['runs']=DB::all('SELECT id,operation_id,trigger_event,entity_type,entity_id,status,attempts,started_at,finished_at,last_error,created_at FROM automation_runs WHERE automation_id=? ORDER BY id DESC LIMIT 30',[$id]);
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
        return DB::all("SELECT r.*,d.title demand_title FROM automation_runs r LEFT JOIN demands d ON d.id=r.entity_id AND r.entity_type='demand' WHERE r.automation_id=? ORDER BY r.id DESC LIMIT 100",[$id]);
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
            if(!in_array($type,['trigger','condition','action.whatsapp','end'],true)||!is_array($config))fail('Tipo de nó inválido.',422);
            if($type==='trigger'){
                $triggerCount++;if(($config['event']??'')!==$trigger)fail('O gatilho do fluxo não corresponde ao gatilho da automação.',422);
                $config=['event'=>$trigger];
            }elseif($type==='condition'){
                $field=(string)($config['field']??'');$operator=(string)($config['operator']??'eq');
                if(!in_array($field,self::CONDITION_FIELDS,true)||!in_array($operator,self::CONDITION_OPERATORS,true))fail('Condição inválida.',422);
                $value=$config['value']??null;if(in_array($operator,['in','not_in'],true)&&!is_array($value))fail('A condição IN precisa de uma lista.',422);
                $config=['field'=>$field,'operator'=>$operator,'value'=>$value];
            }elseif($type==='action.whatsapp'){
                $recipient=(string)($config['recipient']??'task');if($recipient!=='task')fail('Nesta versão o WhatsApp usa o destinatário definido na própria task.',422);
                $message=trim((string)($config['message']??''));if($message===''||mb_strlen($message)>4000)fail('Informe a mensagem do WhatsApp com até 4000 caracteres.',422);
                $config=['recipient'=>'task','message'=>$message];
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

    private static function decodeDefinition(string $json): array
    {
        try{$value=json_decode($json,true,64,JSON_THROW_ON_ERROR);return is_array($value)?$value:[];}catch(Throwable){return [];}
    }
}
