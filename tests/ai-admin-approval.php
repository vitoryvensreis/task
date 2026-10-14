<?php
declare(strict_types=1);
$root=dirname(__DIR__);$cfg=$root.'/config/config.php';
if(is_file($cfg))throw new RuntimeException('Run this test in an isolated checkout without private config.php');
file_put_contents($cfg,'<?php return '.var_export(['app_key'=>bin2hex(random_bytes(32)),'ai_whatsapp'=>['admin_control_enabled'=>true]],true).';');
require $root.'/app/bootstrap.php';
register_shutdown_function(static fn()=>@unlink($cfg));
function aa(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
Auth::$user=['id'=>1,'name'=>'Admin Teste','role'=>'admin','active'=>1];Auth::$actor=Auth::$user;Auth::$origin='whatsapp';
$public=AiWhatsAppAdminActions::catalog(true);
$names=array_column(array_column($public,'function'),'name');
aa(count($names)===10,'Admin must see 10 allowlisted tools');
foreach(['create_invoice','record_payment','create_expense','mark_expense_paid','cancel_invoice','create_project'] as $tool){
 aa(in_array($tool,$names,true),'Admin action missing: '.$tool);
 aa(AiWhatsAppAdminActions::sensitive($tool),'Financial or operational write must be sensitive');
}
foreach(['list_clients','list_projects','list_events','list_team'] as $tool){
 aa(in_array($tool,$names,true),'Missing platform read: '.$tool);
 aa(!AiWhatsAppAdminActions::sensitive($tool),'Read should not require execution confirmation');
}
aa(AiWhatsAppAdminActions::catalog(false)===[],'No admin tools in groups');
Auth::$user=['id'=>2,'role'=>'staff','active'=>1];Auth::$actor=Auth::$user;
aa(AiWhatsAppAdminActions::catalog(true)===[],'No admin tools for staff');
Auth::$user=['id'=>3,'role'=>'client','active'=>1];Auth::$actor=Auth::$user;
aa(AiWhatsAppAdminActions::catalog(true)===[],'No admin tools for clients');
$approve=AiWhatsAppApproval::command('CONFIRMAR A1B2C3D4E5F6');
aa($approve===['tool'=>'_approve_admin','arguments'=>['code'=>'A1B2C3D4E5F6']],'Explicit code recognizes approval');
$cancel=AiWhatsAppApproval::command('cancelar a1b2c3d4e5f6');
aa($cancel===['tool'=>'_cancel_admin','arguments'=>['code'=>'A1B2C3D4E5F6']],'Explicit code recognizes cancellation');
foreach(['CONFIRMAR','CONFIRMAR A1B2','CONFIRMAR A1B2C3D4E5F6 e cancele tudo','CONFIRMAR A1B2C3D4E5F6 123','CONFIRMO A1B2C3D4E5F6', 'CONFIRMAR A1B2C3D4E5F6;DROP'] as $input){
 aa(AiWhatsAppApproval::command($input)===null,'Rejected ambiguous approval command: '.$input);
}
$method=(new ReflectionClass(AiWhatsAppWorker::class))->getMethod('formatResult');
$formatted=$method->invoke(null,['kind'=>'approval_pending','summary'=>'Criar despesa pendente de R$ 100,00','code'=>'ABCDEF123456','minutes'=>10]);
aa(str_contains($formatted,'AINDA NÃO EXECUTADA')&&str_contains($formatted,'CONFIRMAR ABCDEF123456')&&str_contains($formatted,'CANCELAR ABCDEF123456'),'Approval response includes action summary and explicit instructions');
echo "TaskEsc admin AI approval: private-only tools, explicit code, cancellation, no unrestricted mutations OK\n";