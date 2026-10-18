<?php
declare(strict_types=1);

/** Make phone uniqueness tenant-local while preserving existing records. */
final class WorkspaceContactsIndex {
 public const MARKER='workspace_contacts_index_version';
 private static function hasIndex(string $name): bool {
  return (bool)DB::one("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_contacts' AND INDEX_NAME=? LIMIT 1",[$name]);
 }
 public static function apply(): array {
  if(DB::conn()->inTransaction())throw new LogicException('Workspace contacts index requires no transaction.');
  $lock='taskesc-wscontacts-'.(string)(DB::one('SELECT SHA1(DATABASE()) h')['h']??'');
  if((int)(DB::one('SELECT GET_LOCK(?,10) acquired',[$lock])['acquired']??0)!==1)
   throw new RuntimeException('Contacts migration busy.');
  try{
   $column=DB::one("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_contacts' AND COLUMN_NAME='workspace_id'");
   if(!$column||!WorkspaceFoundationGuard::valid())
    throw new RuntimeException('Workspace foundation required for contact index.');
   if(!self::hasIndex('uq_ws_contact_phone')){
    DB::run('ALTER TABLE whatsapp_contacts ADD UNIQUE KEY uq_ws_contact_phone (workspace_id,phone)');
   }
   if(self::hasIndex('uq_whatsapp_contacts_phone')){
    DB::run('ALTER TABLE whatsapp_contacts DROP INDEX uq_whatsapp_contacts_phone');
   }
   DB::run("INSERT INTO settings (`key`,value) VALUES (?,?)
     ON DUPLICATE KEY UPDATE value=VALUES(value)",[self::MARKER,'1']);
   return ['applied'=>true,'index'=>'uq_ws_contact_phone','legacy_global_phone_unique'=>false];
  }finally{DB::run('SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
