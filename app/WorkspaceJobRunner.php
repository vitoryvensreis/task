<?php
declare(strict_types=1);

/**
 * Schedules internal cron jobs independently per workspace.
 * Common SMTP/health maintenance remains platform-owned.
 */
final class WorkspaceJobRunner {
 public static function cron(): array {
  $result=['commercial_invoices'=>0,'cycles'=>0,'mail'=>null,'ran_at'=>now()];
  if(!WorkspaceTenantMode::enabled()){
   $result['commercial_invoices']=Finance::syncCommercialInvoices();
   $result['cycles']=Finance::ensureCycles();
  }else{
   $workspaces=DB::all("SELECT id FROM workspaces WHERE status IN ('staged','active') ORDER BY id");
   if(!$workspaces)throw new RuntimeException('No operational workspaces for cron.');
   foreach($workspaces as $workspace){
    $id=(int)$workspace['id'];
    $one=WorkspaceJobScope::forWorkspace($id,static function(): array {
     return ['commercial_invoices'=>Finance::syncCommercialInvoices(),
       'cycles'=>Finance::ensureCycles()];
    });
    $result['commercial_invoices']+=$one['commercial_invoices'];
    $result['cycles']+=$one['cycles'];
   }
  }
  $result['mail']=Notify::flush();
  return $result;
 }
}
