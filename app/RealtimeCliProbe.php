<?php
declare(strict_types=1);

/**
 * One harmless system event for a CLI-only publishing smoke test.
 *
 * A synthetic diagnostic is scoped to an existing active/staged workspace
 * when tenant isolation is on. It never creates a tenant or changes tasks.
 */
final class RealtimeCliProbe {
 public static function enqueue(): int {
  $key='diagnostic:'.bin2hex(random_bytes(12));
  $publish=static fn(string $channel):int => EventOutbox::transaction(
   static fn():int => EventOutbox::enqueue(
    $key,'realtime.diagnostic','system',1,$channel,
    ['kind'=>'worker_publish_test','created_at'=>gmdate('c')],null
   )
  );
  if(!WorkspaceTenantMode::enabled())return $publish('diagnostic');
  $row=DB::one("SELECT id FROM workspaces WHERE status IN ('staged','active') ORDER BY id LIMIT 1");
  $ws=(int)($row['id']??0);
  if($ws<1)throw new RuntimeException('diagnostic_workspace_missing');
  // This is a synthetic transport probe: deterministic workspace selection
  // does not become a fallback scope for any business mutation.
  return WorkspaceJobScope::forWorkspace($ws,static fn():int => $publish('diagnostic/ws:'.$ws));
 }
}
