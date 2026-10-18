<?php
declare(strict_types=1);

/**
 * Platform read-only integration inventory by operational workspace.
 * No OAuth tokens, Google credentials, WPP secrets or tenant content exposed.
 * An unprovisioned tenant cannot be represented as a live integration.
 */
final class WorkspaceIntegrationStatus {
 public static function forMaster(int $workspaceId): array {
  Auth::load();
  if($workspaceId<1||Auth::$origin!=='web'||!MasterSupport::isActor()||MasterSupport::isSupport())
   fail('Consulta reservada ao ADM Master.',403);
  if(!DB::one('SELECT id FROM workspaces WHERE id=?',[$workspaceId]))
   fail('Workspace não encontrado.',404);

  $accounts=DB::all('SELECT id,email,status,calendar_enabled,drive_enabled,scopes FROM google_accounts WHERE workspace_id=? ORDER BY id',[$workspaceId]);
  $settings=DB::one("SELECT value FROM workspace_settings WHERE workspace_id=? AND `key`='drive_account_id'",[$workspaceId]);
  $selectedDrive=(int)($settings['value']??0);
  $driveReady=false;$calCount=0;
  $public=[];
  foreach($accounts as $account){
   $connected=$account['status']==='connected';
   if($connected&&(int)$account['calendar_enabled']===1)$calCount++;
   if($connected&&(int)$account['drive_enabled']===1&&$selectedDrive===(int)$account['id']
      &&str_contains((string)($account['scopes']??''),'https://www.googleapis.com/auth/drive.file'))$driveReady=true;
   $public[]=['id'=>(int)$account['id'],'email'=>$account['email'],'status'=>$account['status'],
    'drive_enabled'=>(bool)$account['drive_enabled'],'calendar_enabled'=>(bool)$account['calendar_enabled']];
  }
  $selectedCalendars=(int)(DB::one("SELECT COUNT(*) n FROM calendars c
    JOIN google_accounts a ON a.id=c.account_id
    WHERE a.workspace_id=? AND a.status='connected' AND a.calendar_enabled=1 AND c.selected=1",[$workspaceId])['n']??0);
  $fileCount=(int)(DB::one('SELECT COUNT(*) n FROM files WHERE workspace_id=?',[$workspaceId])['n']??0);
  $automations=(int)(DB::one('SELECT COUNT(*) n FROM automations WHERE workspace_id=?',[$workspaceId])['n']??0);
  $notifications=(int)(DB::one('SELECT COUNT(*) n FROM notifications WHERE workspace_id=?',[$workspaceId])['n']??0);
  return ['google_accounts'=>$public,'drive_ready'=>$driveReady,'calendar_accounts'=>$calCount,
   'selected_calendars'=>$selectedCalendars,'file_count'=>$fileCount,
   'automation_count'=>$automations,'notification_count'=>$notifications,
   'whatsapp_independent'=>false,'global_integrations_master_managed'=>true];
 }
}
