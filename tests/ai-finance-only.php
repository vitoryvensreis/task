<?php
declare(strict_types=1);
$root=dirname(__DIR__);$cfg=$root.'/config/config.php';
if(is_file($cfg))throw new RuntimeException('Finance-only test requires isolated checkout');
file_put_contents($cfg,'<?php return '.var_export([
 'app_key'=>bin2hex(random_bytes(32)),
 'timezone'=>'America/Fortaleza',
 'ai_whatsapp'=>['enabled'=>true,'admin_control_enabled'=>false,
  'financial_write_enabled'=>true,'agenda_control_enabled'=>false]
],true).';');
require $root.'/app/bootstrap.php';
register_shutdown_function(static fn()=>@unlink($cfg));
function fw(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
Auth::$user=['id'=>1,'role'=>'admin','active'=>1];Auth::$actor=Auth::$user;Auth::$origin='whatsapp';
fw(!AiWhatsAppAdminActions::enabled(),'Broad administrative flag must remain disabled');
fw(AiWhatsAppAdminActions::financialWriteEnabled(),'Financial write must be separately enabled');
$catalog=AiWhatsAppAdminActions::catalog(true);
$names=array_column(array_column($catalog,'function'),'name');
fw(count($names)===6,'Only six approved financial tools exposed with general admin disabled');
foreach(['create_expense','register_receipt','record_payment','create_invoice','mark_expense_paid','cancel_invoice'] as $tool)
 fw(in_array($tool,$names,true)&&AiWhatsAppAdminActions::sensitive($tool),'Missing protected financial tool '.$tool);
foreach(['create_project','list_clients','list_team','list_projects','list_events'] as $tool)
 fw(!in_array($tool,$names,true),'Unexpected broad admin tool '.$tool);
fw(AiWhatsAppAdminActions::catalog(false)===[],'No finance write catalog in group');
fw(AiWhatsAppFinancialWriteIntent::plan('Task, Adciona uma dispesa para mim de 22,60 uber',$catalog,true)['tool']==='create_expense',
 'User spelling mistakes in direct expense should be recognized');
fw(AiWhatsAppFinancialWriteIntent::plan('Task, Adiciona uma entrada de 22,60',$catalog,true)['text']!==null,
 'Incomplete income should ask for missing client and payment details');
Auth::$user=['id'=>2,'role'=>'staff','active'=>1];Auth::$actor=Auth::$user;
fw(AiWhatsAppAdminActions::catalog(true)===[],'Staff cannot write finance');
Auth::$user=['id'=>3,'role'=>'client','active'=>1];Auth::$actor=Auth::$user;
fw(AiWhatsAppAdminActions::catalog(true)===[],'Client cannot write finance');
echo "AI finance-only tools exposed for ADM with confirmation and separate flag OK\n";
