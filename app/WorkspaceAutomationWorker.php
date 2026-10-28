<?php
declare(strict_types=1);
/** Runs automations in the explicitly selected company context. */
final class WorkspaceAutomationWorker {
 public static function tick(int $events=100,int $runs=20): array {
  if(!WorkspaceTenantMode::enabled())return AutomationWorker::tick($events,$runs);
  $rows=DB::all("SELECT id FROM workspaces WHERE status IN ('staged','active') ORDER BY id");
  if(!$rows)throw new RuntimeException('No operational workspaces.');
  $out=[];
  foreach($rows as $row){
   $id=(int)$row['id'];
   if(!PlatformBillingAccess::state($id)['write_allowed']){$out[$id]=['status'=>'paused_by_billing'];continue;}
   $out[$id]=WorkspaceJobScope::forWorkspace($id,static fn()=>AutomationWorker::tick($events,$runs));
  }
  return ['mode'=>'per_workspace','results'=>$out];
 }
 public static function health(): array {
  if(!WorkspaceTenantMode::enabled())return AutomationWorker::health();
  $out=[];$healthy=true;
  foreach(DB::all("SELECT id FROM workspaces WHERE status IN ('staged','active') ORDER BY id") as $row){
   $id=(int)$row['id'];
   if(!PlatformBillingAccess::state($id)['write_allowed']){$out[$id]=['status'=>'paused_by_billing'];continue;}
   $out[$id]=WorkspaceJobScope::forWorkspace($id,static fn()=>AutomationWorker::health());
   if(($out[$id]['status']??'')!=='ok')$healthy=false;
  }
  return ['status'=>$healthy?'ok':'degraded','workspaces'=>$out];
 }
}
