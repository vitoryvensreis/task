<?php
declare(strict_types=1);

/** Agenda privada compartilhada entre administradores. */
final class WhatsappContacts {
 public static function list(array $p=[]): array {
  Auth::admin();
  $q=trim((string)($p['q']??''));
  if(mb_strlen($q)>190)fail('Busca de contato muito longa.',422);
  $where='';$args=[];
  if($q!==''){
   $digits=preg_replace('/\D+/','',$q);
   $where=' WHERE name LIKE ?';
   $args=['%'.$q.'%'];
   if($digits!==''){$where.=' OR phone LIKE ?';$args[]='%'.$digits.'%';}
  }
  return DB::all('SELECT id,name,phone FROM whatsapp_contacts'.$where.' ORDER BY name,id LIMIT 100',$args);
 }
 /** Called only inside the same transaction used to save a demand. */
 public static function remember(string $name,string $phone): array {
  Auth::admin();
  if(!DB::conn()->inTransaction())throw new LogicException('Agenda deve ser gravada na transação da task.');
  $name=trim($name);
  if($name===''||mb_strlen($name)>190)fail('Informe o nome do contato de WhatsApp (até 190 caracteres).',422);
  $phone=WppConnect::normalizePhone($phone);
  $now=now();
  DB::run('INSERT INTO whatsapp_contacts (name,phone,created_by,created_at,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=VALUES(updated_at)',[
   $name,$phone,(int)Auth::$user['id'],$now,$now
  ]);
  $contact=DB::one('SELECT id,name,phone FROM whatsapp_contacts WHERE phone=?',[$phone]);
  if(!$contact)throw new RuntimeException('Não foi possível salvar o contato.');
  return $contact;
 }
}
