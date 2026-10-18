<?php
declare(strict_types=1);

/**
 * Schedules internal cron jobs independently per workspace.
 * Common SMTP/health maintenance remains platform-owned.
 */
final class WorkspaceJobRunner {
 public static function cron(): array {
  $result=['commercial_invoices'=>0,'cycles'=>0,'google'=>null,'mail'=>null,'ran_at'=>now()];
  if(!WorkspaceTenantMode::enabled()){
   $result['commercial_invoices']=Finance::syncCommercialInvoices();
   $result['cycles']=Finance::ensureCycles();
   $result['google']=Google::sync();
  }else{
   $result['google']=['synced'=>0,'errors'=>[],'per_workspace'=>[]];
   $workspaces=DB::all("SELECT id FROM workspaces WHERE status IN ('staged','active') ORDER BY id");
   if(!$workspaces)throw new RuntimeException('No operational workspaces for cron.');
   foreach($workspaces as $workspace){
    $id=(int)$workspace['id'];
    $one=WorkspaceJobScope::forWorkspace($id,static function(): array {
     return ['commercial_invoices'=>Finance::syncCommercialInvoices(),
       'cycles'=>Finance::ensureCycles(),'google'=>Google::sync()];
    });
    $result['commercial_invoices']+=$one['commercial_invoices'];
    $result['cycles']+=$one['cycles'];
    $result['google']['synced']+=(int)($one['google']['synced']??0);
    foreach($one['google']['errors']??[] as $error)
     $result['google']['errors'][]=['workspace_id'=>$id,'code'=>'calendar_sync_failed',
       'calendar_id'=>(int)($error['calendar_id']??0)];
    $result['google']['per_workspace'][]=['workspace_id'=>$id,
     'synced'=>(int)($one['google']['synced']??0)];
   }
  }
  $result['mail']=Notify::flush();
  return $result;
 }
}
