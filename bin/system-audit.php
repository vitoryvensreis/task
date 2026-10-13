<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$cleanup=in_array('--cleanup-saas',$argv,true);
$strict=in_array('--strict',$argv,true);
$offline=in_array('--offline',$argv,true);
$expectedSchema=(string)Migrations::CURRENT_VERSION;
foreach($argv as $arg)if(str_starts_with($arg,'--expect-schema='))$expectedSchema=substr($arg,16);
$critical=[];$warnings=[];$info=[];

$add=function(array &$bucket,string $key,string $message,mixed $meta=null): void {
 $bucket[]=['key'=>$key,'message'=>$message,'meta'=>$meta];
};
$tableExists=function(string $table): bool {
 return (bool)DB::one("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1",[$table]);
};
$count=function(string $sql,array $args=[]): int {
 return (int)(DB::one($sql,$args)['n']??0);
};

try{
 DB::one('SELECT 1 AS ok');
 $schema=setting('schema_version','unknown');
 $info[]=['key'=>'schema','message'=>'Schema atual: '.$schema,'meta'=>$schema];

 if(!in_array($expectedSchema,['19','21','22','23','24'],true)||$schema!==$expectedSchema)$add($critical,'schema_mismatch','Schema diferente da versão esperada.',['actual'=>$schema,'expected'=>$expectedSchema]);
 if(in_array($schema,['21','22','23','24'],true)){
  $outboxIssues=Migrations::outboxIssues();
  foreach($outboxIssues as $issue)$add($critical,'outbox_'.$issue,'Estrutura Outbox incompatível: '.$issue);
  if(!$outboxIssues)$info[]=['key'=>'event_outbox','message'=>'Outbox InnoDB, JSON e índices validados.','meta'=>['valid'=>true]];
 }

 $saasTables=['workspace_settings','workspace_members','workspaces'];
 $saasFlags=['saas_enabled','workspace_context_enabled','workspace_permissions_enabled','workspace_settings_enabled','signup_enabled','billing_enabled'];

 $presentTables=array_values(array_filter($saasTables,$tableExists));
 $marks=implode(',',array_fill(0,count($saasFlags),'?'));
 $presentFlags=DB::all("SELECT `key`,value FROM settings WHERE `key` IN ($marks) ORDER BY `key`",$saasFlags);

 if($cleanup){
  if($presentFlags)DB::run("DELETE FROM settings WHERE `key` IN ($marks)",$saasFlags);
  if($presentTables){
   DB::run('SET FOREIGN_KEY_CHECKS=0');
   try{
    foreach($saasTables as $table)if($tableExists($table))DB::run("DROP TABLE `$table`");
   }finally{DB::run('SET FOREIGN_KEY_CHECKS=1');}
  }
  $presentTables=array_values(array_filter($saasTables,$tableExists));
  $presentFlags=DB::all("SELECT `key`,value FROM settings WHERE `key` IN ($marks) ORDER BY `key`",$saasFlags);
  $info[]=['key'=>'saas_cleanup','message'=>'Limpeza SaaS executada.','meta'=>['remaining_tables'=>$presentTables,'remaining_flags'=>$presentFlags]];
 }

 if($presentTables)$add($warnings,'saas_tables','Ainda existem tabelas da fundação SaaS.',array_values($presentTables));
 if($presentFlags)$add($warnings,'saas_flags','Ainda existem feature flags da fundação SaaS.',$presentFlags);

 $workspaceColumns=DB::all("SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id' ORDER BY TABLE_NAME");
 if($workspaceColumns)$add($warnings,'workspace_columns','Foram encontradas colunas workspace_id. Elas não são usadas pelo TaskEsc v3.25 e não foram removidas automaticamente.',$workspaceColumns);
 if(in_array($schema,['21','22','23','24'],true)&&($presentTables||$presentFlags||$workspaceColumns))$add($critical,'removed_saas_present','A Outbox não admite estruturas do SaaS removido.');

 $essential=['users','clients','stages','categories','projects','demands','files','comments','invoices','payments','google_accounts','calendars','events','settings'];
 if(in_array($schema,['22','23','24'],true))array_push($essential,'chat_threads','chat_messages');
 if(in_array($schema,['23','24'],true))$essential[]='chat_message_tasks';
 if($schema==='24')array_push($essential,'automations','automation_runs','automation_run_steps','automation_worker_state','whatsapp_messages');
 foreach($essential as $table)if(!$tableExists($table))$add($critical,'missing_table_'.$table,'Tabela essencial ausente: '.$table);
 if(in_array($schema,['23','24'],true)){
  $chatIssues=Migrations::chatIssues();
  foreach($chatIssues as $issue)$add($critical,'chat_'.$issue,'Estrutura do chat incompatível: '.$issue);
  if(!$chatIssues)$info[]=['key'=>'chat_schema','message'=>'Chat administrativo e referências de tasks validados.','meta'=>['valid'=>true]];
 }
 if($schema==='24'){
  $automationIssues=Migrations::automationIssues();
  foreach($automationIssues as $issue)$add($critical,'automation_'.$issue,'Estrutura de automações incompatível: '.$issue);
  if(!$automationIssues)$info[]=['key'=>'automation_schema','message'=>'Automações, WhatsApp e worker schema validados.','meta'=>['valid'=>true]];
 }

 $relations=[
  ['projects_client',"SELECT COUNT(*) n FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE c.id IS NULL"],
  ['user_clients_user',"SELECT COUNT(*) n FROM user_clients x LEFT JOIN users u ON u.id=x.user_id WHERE u.id IS NULL"],
  ['user_clients_client',"SELECT COUNT(*) n FROM user_clients x LEFT JOIN clients c ON c.id=x.client_id WHERE c.id IS NULL"],
  ['demands_client',"SELECT COUNT(*) n FROM demands d LEFT JOIN clients c ON c.id=d.client_id WHERE c.id IS NULL"],
  ['demands_stage',"SELECT COUNT(*) n FROM demands d LEFT JOIN stages s ON s.id=d.stage_id WHERE s.id IS NULL"],
  ['demands_category',"SELECT COUNT(*) n FROM demands d LEFT JOIN categories c ON c.id=d.category_id WHERE c.id IS NULL"],
  ['files_demand',"SELECT COUNT(*) n FROM files f LEFT JOIN demands d ON d.id=f.demand_id WHERE d.id IS NULL"],
  ['comments_demand',"SELECT COUNT(*) n FROM comments c LEFT JOIN demands d ON d.id=c.demand_id WHERE d.id IS NULL"],
  ['checklists_demand',"SELECT COUNT(*) n FROM checklists c LEFT JOIN demands d ON d.id=c.demand_id WHERE d.id IS NULL"],
  ['time_entries_demand',"SELECT COUNT(*) n FROM time_entries t LEFT JOIN demands d ON d.id=t.demand_id WHERE d.id IS NULL"],
  ['costs_demand',"SELECT COUNT(*) n FROM costs c LEFT JOIN demands d ON d.id=c.demand_id WHERE d.id IS NULL"],
  ['invoices_client',"SELECT COUNT(*) n FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE c.id IS NULL"],
  ['payments_invoice',"SELECT COUNT(*) n FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id WHERE i.id IS NULL"],
  ['calendars_account',"SELECT COUNT(*) n FROM calendars c LEFT JOIN google_accounts g ON g.id=c.account_id WHERE c.account_id IS NOT NULL AND g.id IS NULL"],
  ['events_calendar',"SELECT COUNT(*) n FROM events e LEFT JOIN calendars c ON c.id=e.calendar_id WHERE c.id IS NULL"],
  ['notifications_user',"SELECT COUNT(*) n FROM notifications n LEFT JOIN users u ON u.id=n.user_id WHERE u.id IS NULL"]
 ];
 if($schema==='23')$relations[]=['chat_message_tasks_demand',"SELECT COUNT(*) n FROM chat_message_tasks x LEFT JOIN demands d ON d.id=x.demand_id WHERE d.id IS NULL"];
 foreach($relations as [$key,$sql]){
  try{$n=$count($sql);if($n>0)$add($warnings,'orphan_'.$key,$n.' registro(s) órfão(s) em '.$key,$n);}catch(Throwable $e){$add($warnings,'check_'.$key,'Não foi possível validar '.$key.': '.$e->getMessage());}
 }

 $drive=DB::one("SELECT COUNT(*) total,
  SUM(storage_provider='drive') drive_total,
  SUM(storage_provider='drive' AND (drive_file_id IS NULL OR drive_file_id='')) missing_file_id,
  SUM(storage_provider='drive' AND (drive_account_id IS NULL OR drive_account_id=0)) missing_account_id,
  SUM(storage_provider='drive' AND (drive_web_view_url IS NULL OR drive_web_view_url='')) missing_web_view
  FROM files")??[];
 $info[]=['key'=>'drive_files','message'=>'Integridade dos vínculos do Drive.','meta'=>$drive];
 if((int)($drive['missing_file_id']??0)>0)$add($warnings,'drive_missing_file_id','Há arquivos do Drive sem drive_file_id.',$drive['missing_file_id']);
 if((int)($drive['missing_account_id']??0)>0)$add($warnings,'drive_missing_account_id','Há arquivos do Drive sem drive_account_id.',$drive['missing_account_id']);
 if((int)($drive['missing_web_view']??0)>0)$add($warnings,'drive_missing_web_view','Há arquivos do Drive sem webViewLink salvo.',$drive['missing_web_view']);

 $orphanDrive=$count("SELECT COUNT(*) n FROM files f LEFT JOIN google_accounts g ON g.id=f.drive_account_id WHERE f.storage_provider='drive' AND f.drive_account_id IS NOT NULL AND g.id IS NULL");
 if($orphanDrive>0)$add($warnings,'drive_orphan_account','Há arquivos apontando para conta Google inexistente.',$orphanDrive);

 $accounts=DB::all("SELECT g.id,g.email,g.status,g.drive_enabled,g.scopes,g.last_error,
  (SELECT COUNT(*) FROM files f WHERE f.storage_provider='drive' AND f.drive_account_id=g.id) file_count
  FROM google_accounts g ORDER BY g.id");
 foreach($accounts as $a){
  if((int)$a['file_count']<1)continue;
  if($a['status']!=='connected'){$add($warnings,'google_account_'.$a['id'],'Conta com arquivos não está conectada.',['email'=>$a['email'],'status'=>$a['status']]);continue;}
  if(!(bool)$a['drive_enabled']||!str_contains((string)$a['scopes'],'https://www.googleapis.com/auth/drive.file'))$add($warnings,'google_scope_'.$a['id'],'Conta com arquivos não declara o escopo drive.file.',['email'=>$a['email'],'scopes'=>$a['scopes']]);
  if($offline)continue; // Auditoria estrutural sem chamadas externas ou renovação de tokens.

  $sample=DB::one("SELECT id,drive_file_id,original_name FROM files WHERE storage_provider='drive' AND drive_account_id=? AND drive_file_id IS NOT NULL AND drive_file_id<>'' ORDER BY id LIMIT 1",[$a['id']]);
  if(!$sample)continue;
  try{
   $token=Google::token((int)$a['id']);
   $url='https://www.googleapis.com/drive/v3/files/'.rawurlencode((string)$sample['drive_file_id']).'?fields=id,name,mimeType,trashed';
   $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json']]);
   $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
   if($code!==200)$add($warnings,'google_drive_test_'.$a['id'],'Teste real do Drive falhou.',['email'=>$a['email'],'http'=>$code,'error'=>$err,'body'=>mb_substr((string)$body,0,300)]);
   else $info[]=['key'=>'google_drive_'.$a['id'],'message'=>'Google Drive acessível para '.$a['email'],'meta'=>['http'=>200,'sample_file_id'=>$sample['id']]];
  }catch(Throwable $e){$add($warnings,'google_token_'.$a['id'],'Falha ao testar token do Google Drive.',['email'=>$a['email'],'error'=>$e->getMessage()]);}
 }

 $defaultDrive=(int)setting('drive_account_id','0');
 if($defaultDrive>0&&!DB::one('SELECT id FROM google_accounts WHERE id=?',[$defaultDrive]))$add($warnings,'default_drive_missing','drive_account_id padrão aponta para uma conta inexistente.',$defaultDrive);

 foreach(['storage','storage/logs','storage/sessions'] as $dir){
  $path=ROOT.'/'.$dir;
  if(!is_dir($path)||!is_writable($path))$add($critical,'storage_'.$dir,'Diretório necessário ausente ou sem escrita: '.$dir);
 }

 $result=['ok'=>!$critical,'cleanup_saas'=>$cleanup,'offline'=>$offline,'schema'=>$schema,'critical'=>$critical,'warnings'=>$warnings,'info'=>$info,'checked_at'=>gmdate('c')];
 echo json($result).PHP_EOL;
 if($strict&&$critical)exit(1);
 exit(0);
}catch(Throwable $e){
 fwrite(STDERR,'System audit failed: '.$e->getMessage().PHP_EOL);
 exit(1);
}
