<?php
declare(strict_types=1);
define('ROOT',dirname(__DIR__));
$port=getenv('OUTBOX_TEST_PORT');if(!$port)throw new RuntimeException('Only disposable CI database allowed.');
$configPath=ROOT.'/config/config.php';@unlink($configPath);
$config=['base_url'=>'https://task.escotilhaprodutora.com','db'=>['host'=>'127.0.0.1','port'=>(int)$port,'name'=>'taskesc_test','user'=>'root','password'=>'taskesc-ci-only'],
 'app_key'=>bin2hex(random_bytes(32)),'wppconnect'=>['internal_url'=>'http://127.0.0.1:21465','session'=>'taskesc','secret'=>str_repeat('a',64),'webhook_url'=>'']];
file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
@mkdir(ROOT.'/storage/sessions',0700,true);touch(ROOT.'/storage/installed.lock');
require ROOT.'/app/bootstrap.php';
function ac(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function user(string $phone,string $role,bool $enabled=true,bool $active=true): int {
 return DB::insert('users',['name'=>'Fixture '.$role.' '.$phone,'email'=>'ai-'.$role.'-'.$phone.'@ci.invalid',
 'whatsapp_phone'=>$phone,'password_hash'=>password_hash('TestPassword123!',PASSWORD_DEFAULT),
 'role'=>$role,'active'=>(int)$active,'ai_assistant_enabled'=>(int)$enabled,'created_at'=>now()]);
}
try{
 $db=DB::conn();$db->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach(DB::all('SHOW TABLES') as $row){$table=(string)array_values($row)[0];if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$table))throw new RuntimeException('Unexpected DB table.');$db->exec('DROP TABLE `'.$table.'`');}
 $db->exec('SET FOREIGN_KEY_CHECKS=1');$db->exec(file_get_contents(ROOT.'/database/schema.sql'));
 ac((string)DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value']==='28','Fresh schema is 28');
 ac(Migrations::assistantAccessIssues()===[],'Fresh access columns/indexes valid');
 ac(Migrations::assistantAccess()['changed']===false,'Schema 28 migration idempotent');

 // Simulate interrupted migration from 27, preserve old inbox content and previous tables.
 DB::run("INSERT INTO ai_whatsapp_inbox (session_name,provider_message_id,chat_id,sender_id,chat_type,message_type,body,status,received_at) VALUES ('taskesc','before-access','5585999991111@c.us','5585999991111@c.us','private','chat','historical','received',NOW(6))");
 $db->exec('ALTER TABLE ai_whatsapp_inbox DROP INDEX idx_ai_inbox_auth, DROP INDEX idx_ai_inbox_auth_user');
 foreach(['auth_user_id','auth_status','auth_reason','auth_checked_at'] as $column)$db->exec('ALTER TABLE ai_whatsapp_inbox DROP COLUMN '.$column);
 $db->exec('ALTER TABLE users DROP COLUMN ai_assistant_enabled');
 DB::run("UPDATE settings SET value='27' WHERE `key`='schema_version'");
 $r=Migrations::assistantAccess();
 ac($r['previous']===27&&$r['current']===28&&$r['changed'],'Upgrade 27->28');
 ac(Migrations::assistantAccessIssues()===[]&&Migrations::assistantAccess()['changed']===false,'Re-running migration is safe');
 $historical=DB::one("SELECT body,auth_status,auth_reason FROM ai_whatsapp_inbox WHERE provider_message_id='before-access'");
 ac($historical['body']==='historical'&&$historical['auth_status']==='pending','Prior messages preserved, never implicitly authorized');
 ac(Migrations::assistantInbox()['changed']===false&&Migrations::automation()['changed']===false&&Migrations::chat()['changed']===false&&Migrations::outbox()['changed']===false,'All previous migration stages must accept 28');

 $admin=user('5585999990001','admin',false);
 $staff=user('5585999992000','staff',false);
 $client=user('5585999993000','client',true);
 $inactive=user('5585999994000','admin',false,false);
 ac(AiWhatsAppAccess::decide('5585999990001@c.us','private','5585999990001@c.us')['status']==='authorized','Admins are implicitly authorized');
 ac(AiWhatsAppAccess::decide('5585999992000@c.us','private','5585999992000@c.us')['reason']==='permission_disabled','Staff disabled by default');
 ac(AiWhatsAppAccess::decide('5585999993000@s.whatsapp.net','private','5585999993000@s.whatsapp.net')['status']==='authorized','Explicit client permission works');
 ac(AiWhatsAppAccess::decide('5585999994000@c.us','private','5585999994000@c.us')['status']==='denied','Inactive admin must never access');
 ac(AiWhatsAppAccess::decide('5585999990009@c.us','private','5585999990009@c.us')['status']==='denied','Unknown sender must be denied');
 ac(AiWhatsAppAccess::decide('5585999993000@lid','private','5585999993000@lid',null,static fn()=>null)['status']==='denied','Unresolved LID must never be treated as a phone');
 ac(AiWhatsAppAccess::decide('5585999993000@g.us','private','5585999993000@g.us')['status']==='denied','Group jid must not resolve to phone');

 $members=['5585999990001@c.us','5585999993000@c.us'];
 $gid='120363012345678901@g.us';$provider=static fn(string $v)=>$v===$gid?$members:[];
 ac(AiWhatsAppAccess::decide('5585999993000@c.us','group',$gid,$provider)['status']==='authorized','Group with active TaskEsc admin grants permitted sender');
 ac(AiWhatsAppAccess::decide('5585999993000@c.us','group',$gid,static fn()=>['5585999993000@c.us'])['reason']==='group_admin_absent','Cannot grant access without a TaskEsc admin member');
 ac(AiWhatsAppAccess::decide('5585999993000@c.us','group',$gid,static fn()=>['5585999990001@c.us'])['reason']==='sender_not_in_group','Sender must be a current member');
 ac(AiWhatsAppAccess::decide('5585999993000@c.us','group',$gid,static function(){throw new RuntimeException('Provider down');})['reason']==='group_members_unavailable','Provider failure must deny');
 ac(AiWhatsAppAccess::decide('5585999992000@c.us','group',$gid,$provider)['reason']==='permission_disabled','Disabled staff in group must be denied');
 ac(AiWhatsAppAccess::decide('5585999993000@lid','group',$gid,$provider,static fn()=>null)['status']==='denied','Unresolved group sender LID must be denied');


 // Authoritative WPPConnect PN-LID mapping: never infer phone digits from @lid.
 $lid='125987654321012@lid';
 $adminLid='125987654321099@lid';
 $mapping=static function(string $jid) use($lid,$adminLid): ?array {
     return match($jid){
       $lid,'5585999993000@c.us'=>['lid'=>$lid,'phone'=>'5585999993000@c.us'],
       $adminLid,'5585999990001@c.us'=>['lid'=>$adminLid,'phone'=>'5585999990001@c.us'],
       default=>null
     };
 };
 ac(AiWhatsAppAccess::resolveUser($lid,$mapping)['id']===$client,'Verified LID maps to exactly one active client');
 ac(AiWhatsAppAccess::decide($lid,'private',$lid,null,$mapping)['status']==='authorized','Verified private LID may be authorized');
 $lidMembers=static fn(string $group)=>$group===$gid?[$lid,$adminLid]:[];
 ac(AiWhatsAppAccess::decide($lid,'group',$gid,$lidMembers,$mapping)['status']==='authorized','Both sender and TaskEsc ADM may be LID participants');
 ac(AiWhatsAppAccess::decide('5585999993000@c.us','group',$gid,$lidMembers,$mapping)['status']==='authorized','Phone sender may match verified member LID');
 ac(AiWhatsAppAccess::decide($lid,'group',$gid,static fn()=>[$lid],$mapping)['reason']==='group_admin_absent','Group without TaskEsc ADM must stay denied');
 ac(AiWhatsAppAccess::decide($lid,'group',$gid,static fn()=>[$adminLid],$mapping)['reason']==='sender_not_in_group','LID sender must be an actual participant');
 ac(AiWhatsAppAccess::resolveUser($lid,static fn($jid)=>['lid'=>'999999999@lid','phone'=>'5585999993000@c.us'])===null,'Mismatch LID cannot authorize any account');
 ac(AiWhatsAppAccess::resolveUser($lid,static fn($jid)=>['lid'=>$jid,'phone'=>'5585999999999@lid'])===null,'Provider must return a real phone JID, never another LID');
 ac(AiWhatsAppAccess::resolveUser($lid,static function(){throw new RuntimeException('provider offline');})===null,'Provider outage must fail closed');

 $wire=['status'=>'success','response'=>['lid'=>['_serialized'=>$lid],'phoneNumber'=>['_serialized'=>'5585999993000@c.us']]];
 ac(WppConnect::normalizePnLidMapping($wire,$lid)['phone']==='5585999993000@c.us','Wrapped provider PN-LID data normalized');
 ac(WppConnect::normalizePnLidMapping($wire,'5585999993000@c.us')['lid']===$lid,'Provider PN lookup must agree with sender');
 ac(WppConnect::normalizePnLidMapping($wire,'5585999999000@c.us')===null,'Unrelated PN-LID mapping rejected');
 ac(WppConnect::normalizePnLidMapping(['status'=>'error','response'=>$wire['response']],$lid)===null,'Error status cannot grant identity');
 ac(WppConnect::normalizePnLidMapping(['lid'=>['_serialized'=>$lid],'phoneNumber'=>['_serialized'=>$lid]],$lid)===null,'Invalid mapping phone type rejected');

 $parsed=WppConnect::normalizeMemberIds(['status'=>'success','response'=>[['id'=>['_serialized'=>'5585999990001@c.us'],'isAdmin'=>false],['id'=>'5585999993000@c.us'],['id'=>'not-a-jid']]]);
 ac($parsed===['5585999990001@c.us','5585999993000@c.us'],'Normalize group members and ignore WhatsApp admin status');
 ac(WppConnect::normalizeMemberIds(['status'=>'error','response'=>['5585999990001@c.us']])===[],'WPP errors cannot grant access');
 ac(WppConnect::normalizeMemberIds(['status'=>'success','response'=>['group'=>'not-member-data']])===[],'Unknown group payload fails closed');

 Auth::$user=DB::one('SELECT * FROM users WHERE id=?',[$admin]);Auth::$actor=Auth::$user;Auth::$origin='web';Auth::$previewClientId=null;
 ac(count(Catalog::users())===4,'Admin sees users');
 $u=Catalog::userSave(['id'=>$staff,'name'=>'Editor','email'=>'ai-staff-5585999992000@ci.invalid','whatsapp_phone'=>'5585999992000','role'=>'staff','active'=>1,'ai_assistant_enabled'=>1,'clients'=>[]]);
 ac((int)DB::one('SELECT ai_assistant_enabled FROM users WHERE id=?',[$staff])['ai_assistant_enabled']===1,'Admin can enable AI permission');
 ac(AiWhatsAppAccess::decide('5585999992000@c.us','private','5585999992000@c.us')['status']==='authorized','Enabled staff can use private assistant');
 $failed=false;try{Catalog::userSave(['id'=>$staff,'name'=>'Editor','email'=>'ai-staff-5585999992000@ci.invalid','whatsapp_phone'=>'5585999990001','role'=>'staff','active'=>1,'clients'=>[]]);}catch(AppError $e){$failed=$e->status===409;}
 ac($failed,'Duplicate WhatsApp assignment must be rejected');
 // Historic duplicate records are already possible: do not guess which account owns them.
 $db->exec("UPDATE users SET whatsapp_phone='5585999990001' WHERE id=".$staff);
 ac(AiWhatsAppAccess::resolveUser('5585999990001@c.us')===null,'Historic duplicate user numbers must fail closed');
 $db->exec("UPDATE users SET whatsapp_phone='5585999992000' WHERE id=".$staff);
 // The webhook remains passive: authorization is metadata only.
 $evt=['event'=>'onmessage','session'=>'taskesc','body'=>['id'=>'false_5585999992000@c.us_AUTH','from'=>'5585999992000@c.us','body'=>'status tasks','fromMe'=>false]];
 ac(WppWebhook::handle($evt)['stored']===true,'Incoming authorized message must still be persisted');
 $rec=DB::one("SELECT auth_user_id,auth_status,auth_reason FROM ai_whatsapp_inbox WHERE provider_message_id='false_5585999992000@c.us_AUTH'");
 ac((int)$rec['auth_user_id']===$staff&&$rec['auth_status']==='authorized','Authorization decision must be recorded with user id');
 ac((int)(DB::one('SELECT COUNT(*) n FROM automation_runs')['n']??0)===0,'No automation may run');
 ac((int)(DB::one('SELECT COUNT(*) n FROM whatsapp_messages')['n']??0)===0,'No outbound response may be sent');

 echo "TaskEsc AI step 2: schema 28, permission management, strict phone identity, live group guards, denial, passive inbox OK\n";
}finally{@unlink($configPath);@unlink(ROOT.'/storage/installed.lock');}
