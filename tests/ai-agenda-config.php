<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('Run only in disposable CI environment.');
define('ROOT',dirname(__DIR__));
define('TASKESC_LEGACY_AI_CALENDAR_FIXTURE',true);
$p=ROOT.'/config/config.php';if(is_file($p))throw new RuntimeException('Refusing to replace config.php');
try{
 file_put_contents($p,"<?php\nreturn ".var_export(['ai_whatsapp'=>['enabled'=>true,'admin_control_enabled'=>false,'financial_write_enabled'=>false,'agenda_control_enabled'=>true]],true).";\n");
 require ROOT.'/app/bootstrap.php';
 Auth::$user=['role'=>'admin','id'=>1,'active'=>1];Auth::$actor=Auth::$user;
 if(!AiWhatsAppAgendaTools::writeEnabled())throw new RuntimeException('Independent agenda opt-in ignored');
 if(AiWhatsAppAdminActions::enabled())throw new RuntimeException('Financial/admin flag activated unexpectedly');
 $names=array_column(array_column(AiWhatsAppAgendaTools::catalog(true),'function'),'name');
 if(!in_array('agenda_create',$names,true)||!in_array('agenda_cancel',$names,true))throw new RuntimeException('Agenda writes not exposed');
 if(AiWhatsAppAdminActions::catalog(true)!==[])throw new RuntimeException('Financial admin tools leaked into agenda-only mode');
 if(AiWhatsAppAgendaTools::catalog(false)!==[['type'=>'function','function'=>['name'=>'agenda_list','description'=>'Listar SOMENTE eventos NÃO PRIVADOS em grupos.','parameters'=>['type'=>'object','properties'=>['from'=>['type'=>'string','description'=>'YYYY-MM-DD opcional'],'to'=>['type'=>'string','description'=>'YYYY-MM-DD opcional'],'limit'=>['type'=>'integer']],'required'=>[],'additionalProperties'=>false]]]])throw new RuntimeException('Group catalog must remain read-only');
 echo "TaskEsc independent agenda privilege: OK\n";
}finally{@unlink($p);}
