<?php
declare(strict_types=1);

/**
 * Read-only operational status and a scoped end-to-end WebSocket probe.
 * Never exposes Centrifugo secrets, access tokens, client identity lists,
 * worker logs or raw Outbox payloads to the browser.
 */
final class RealtimeDiagnostics {
 private static function guard(): array {
  Auth::load();Auth::admin();
  if(Auth::$origin!=='web'||Auth::isPreview()||MasterSupport::isSupport())
   fail('Diagnóstico disponível apenas ao administrador autenticado.',403);
  return ['user_id'=>(int)Auth::$actor['id'],'workspace_id'=>Auth::workspaceId()];
 }
 private static function channel(int $ws): string {
  return WorkspaceTenantMode::enabled()?'kanban/ws:'.$ws:'kanban/global';
 }
 public static function health(): array {
  self::guard();
  $realtime=(new Realtime())->health();
  $heartbeat=RealtimeWorkerHealth::read();
  // Operational metrics are tenant-scoped for ordinary workspace admins.
  // Do not reveal queue volume belonging to any other active company.
  if(WorkspaceTenantMode::enabled()){
   $ws=Auth::workspaceId();$maxAttempts=RealtimeOutboxWorker::DEFAULT_MAX_ATTEMPTS;
   $row=DB::one("SELECT COUNT(*) total,
    SUM(published_at IS NULL) unpublished,
    SUM(published_at IS NULL AND attempts<? AND available_at<=UTC_TIMESTAMP()) due,
    SUM(published_at IS NULL AND attempts>=?) exhausted
    FROM event_outbox WHERE workspace_id=?",[$maxAttempts,$maxAttempts,$ws])??[];
   $stats=['unpublished'=>(int)($row['unpublished']??0),
    'due'=>(int)($row['due']??0),'exhausted'=>(int)($row['exhausted']??0)];
  }else $stats=EventOutbox::stats(RealtimeOutboxWorker::DEFAULT_MAX_ATTEMPTS);
  $result=RealtimeWorkerHealth::evaluate($heartbeat,$realtime,$stats);
  return [
   'status'=>$result['status'],'enabled'=>$realtime['enabled'],
   'config'=>$realtime['config'],'redis'=>$realtime['redis'],
   'centrifugo'=>$realtime['centrifugo'],'worker'=>$heartbeat['status'],
   'worker_last_published_at'=>$heartbeat['last_published_at']??null,
   'queue_status'=>$result['queue_status'],'queue_due'=>$stats['due'],
   'queue_unpublished'=>$stats['unpublished'],'queue_exhausted'=>$stats['exhausted'],
   'checked_at'=>gmdate('c')
  ];
 }
 public static function probe(): array {
  $auth=self::guard();
  Auth::rate('realtime-diagnostic:'.$auth['user_id'],8,300);
  $realtime=new Realtime();
  if(!$realtime->enabled())fail('Realtime desativado na configuração da VPS.',503);
  $nonce=bin2hex(random_bytes(16));
  $channel=self::channel($auth['workspace_id']);
  $id=EventOutbox::transaction(static fn() => EventOutbox::enqueue(
   'realtime.browser_probe.'.$nonce,'realtime.diagnostic','system',1,
   $channel,['probe_id'=>$nonce,'kind'=>'kanban_delivery_test'],$auth['user_id']
  ));
  return ['id'=>$id,'probe_id'=>$nonce,'channel'=>$channel,'queued'=>true];
 }
 public static function probeStatus(array $p): array {
  $auth=self::guard();$id=integer($p['id']??0,1);
  $row=DB::one("SELECT published_at,attempts,last_error,created_at FROM event_outbox
   WHERE id=? AND event_type='realtime.diagnostic' AND entity_type='system' AND created_by=? AND channel=?
   AND created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)",
   [$id,$auth['user_id'],self::channel($auth['workspace_id'])]);
  if(!$row)fail('Teste realtime não encontrado ou expirado.',404);
  return ['published'=>$row['published_at']!==null,
   'attempts'=>(int)$row['attempts'],'last_error'=>(string)($row['last_error']??'')];
 }
}
