<?php
declare(strict_types=1);

/** Agenda privada compartilhada entre administradores. */
final class WhatsappContacts {
 public static function list(array $p=[]): array {
  Auth::admin();
  $q=trim((string)($p['q']??''));
  if(mb_strlen($q)>190)fail('Busca de contato muito longa.',422);
  $ci=WorkspaceContext::legacyCiFixture();
  $where=$ci?'':' WHERE workspace_id=?';$args=$ci?[]:[Auth::workspaceId()];
  if($q!==''){
   $digits=preg_replace('/\D+/','',$q);
   $where.=$ci?' WHERE ':' AND ';
   $where.='(name LIKE ?';
   $args[]='%'.$q.'%';
   if($digits!==''){$where.=' OR phone LIKE ?';$args[]='%'.$digits.'%';}
   $where.=')';
  }
  if(!empty($p['paginate'])){
   $page=integer($p['page']??1,1,1000000);$perPage=integer($p['per_page']??20,1,100);
   $total=(int)(DB::one('SELECT COUNT(*) AS n FROM whatsapp_contacts'.$where,$args)['n']??0);
   $pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
   $offset=($page-1)*$perPage;
   $rows=DB::all('SELECT id,name,phone FROM whatsapp_contacts'.$where.' ORDER BY name,id LIMIT '.$perPage.' OFFSET '.$offset,$args);
   return ['rows'=>$rows,'pagination'=>['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'total'=>$total]];
  }
  return DB::all('SELECT id,name,phone FROM whatsapp_contacts'.$where.' ORDER BY name,id LIMIT 100',$args);
 }

 /** Manual creation/editing of the directory, independent from task delivery settings. */
 public static function save(array $p): array {
  Auth::admin();
  return DB::tx(static function() use($p): array {
   $id=integer($p['id']??0,0);$name=required($p,'name',190);
   $phone=WppConnect::normalizePhone((string)($p['phone']??''));
   $ci=WorkspaceContext::legacyCiFixture();$ws=$ci?0:Auth::workspaceId();
   $old=$id?DB::one('SELECT id,name,phone FROM whatsapp_contacts WHERE id=?'.($ci?'':' AND workspace_id=?').' FOR UPDATE',$ci?[$id]:[$id,$ws]):null;
   if($id&&!$old)fail('Contato não encontrado.',404);
   if(DB::one('SELECT id FROM whatsapp_contacts WHERE phone=? AND id<>?'.($ci?'':' AND workspace_id=?').' LIMIT 1',$ci?[$phone,$id]:[$phone,$id,$ws]))fail('Este WhatsApp já está cadastrado na agenda.',409);
   $now=now();
   try {
    if($id)DB::update('whatsapp_contacts',$id,['name'=>$name,'phone'=>$phone,'updated_at'=>$now]);
    else $id=DB::insert('whatsapp_contacts',['name'=>$name,'phone'=>$phone,'created_by'=>(int)Auth::$user['id'],'created_at'=>$now,'updated_at'=>$now]);
   } catch(PDOException $e) {
    if((string)$e->getCode()==='23000')fail('Este WhatsApp já está cadastrado na agenda.',409);
    throw $e;
   }
   audit('whatsapp_contact',$id,$old?'updated':'created',$old,['name'=>$name,'phone'=>$phone]);
   return ['saved'=>true,'contact'=>['id'=>$id,'name'=>$name,'phone'=>$phone]];
  });
 }
 /** Deleting a directory entry NEVER removes or changes stored numbers on demands. */
 public static function delete(array $p): array {
  Auth::admin();
  return DB::tx(static function() use($p): array {
   $id=integer($p['id']??0,1);
   $ci=WorkspaceContext::legacyCiFixture();
   $old=DB::one('SELECT id,name,phone FROM whatsapp_contacts WHERE id=?'.($ci?'':' AND workspace_id=?').' FOR UPDATE',$ci?[$id]:[$id,Auth::workspaceId()]);
   if(!$old)fail('Contato não encontrado.',404);
   DB::run('DELETE FROM whatsapp_contacts WHERE id=?'.($ci?'':' AND workspace_id=?'),$ci?[$id]:[$id,Auth::workspaceId()]);
   audit('whatsapp_contact',$id,'deleted',$old,null);
   return ['deleted'=>true,'id'=>$id];
  });
 }

 /** Called only inside the same transaction used to save a demand. */
 public static function remember(string $name,string $phone): array {
  Auth::admin();
  if(!DB::conn()->inTransaction())throw new LogicException('Agenda deve ser gravada na transação da task.');
  $name=trim($name);
  if($name===''||mb_strlen($name)>190)fail('Informe o nome do contato de WhatsApp (até 190 caracteres).',422);
  $phone=WppConnect::normalizePhone($phone);
  $now=now();
  $ci=WorkspaceContext::legacyCiFixture();$ws=$ci?0:Auth::workspaceId();
  $columns=$ci?'name,phone,created_by,created_at,updated_at':'name,phone,created_by,created_at,updated_at,workspace_id';
  $values=$ci?'?,?,?,?,?':'?,?,?,?,?,?';
  DB::run('INSERT INTO whatsapp_contacts ('.$columns.') VALUES ('.$values.') ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=VALUES(updated_at)',
   $ci?[$name,$phone,(int)Auth::$user['id'],$now,$now]:[$name,$phone,(int)Auth::$user['id'],$now,$now,$ws]);
  $contact=DB::one('SELECT id,name,phone FROM whatsapp_contacts WHERE phone=?'.($ci?'':' AND workspace_id=?'),$ci?[$phone]:[$phone,$ws]);
  if(!$contact)throw new RuntimeException('Não foi possível salvar o contato.');
  return $contact;
 }
}
