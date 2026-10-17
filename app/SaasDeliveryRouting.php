<?php
declare(strict_types=1);

/**
 * B1.2c-2b.2b.2b.1 — internal recipient routing, NO SEND.
 *
 * A resolved recipient is not a delivered notification: real provider
 * credentials and outbound side effects remain completely disabled.
 * Only the verified internal account may be inspected at this stage.
 */
final class SaasDeliveryRouting {
 public const CHANNEL='internal_event';

 public static function recipients(int $accountId,int $jobId): array {
  if($accountId<1||$jobId<1)throw new InvalidArgumentException('Invalid tenant delivery identity.');
  SaasEventConsumer::assertInternalReady($accountId);
  if((int)(DB::one('SELECT value FROM settings WHERE `key`=?',
    [SaasDeliveryMigration::MARKER])['value']??0)!==SaasDeliveryMigration::VERSION)
   throw new RuntimeException('saas_delivery_migration_missing');

  $row=DB::one('SELECT j.id,j.tenant_account_id,j.receipt_id,j.channel,j.destination_scope,'.
   ' j.event_hash AS job_hash,j.status AS job_status,'.
   ' r.status AS receipt_status,r.event_hash AS receipt_hash,'.
   ' e.*,d.assigned_to,d.client_id AS task_client_id'.
   ' FROM saas_delivery_jobs j'.
   ' INNER JOIN saas_event_receipts r ON r.id=j.receipt_id AND r.tenant_account_id=j.tenant_account_id'.
   ' INNER JOIN saas_event_outbox e ON e.id=r.outbox_id AND e.tenant_account_id=j.tenant_account_id'.
   ' INNER JOIN demands d ON d.id=e.entity_id AND d.tenant_account_id=j.tenant_account_id'.
   ' WHERE j.id=? AND j.tenant_account_id=? LIMIT 1',
   [$jobId,$accountId]);
  if(!$row)throw new RuntimeException('saas_delivery_outside_account');
  if($row['channel']!==self::CHANNEL||
   $row['destination_scope']!=='saas.account.'.$accountId.'.events'||
   !in_array($row['job_status'],['held','retry'],true)||
   $row['receipt_status']!=='held'||
   $row['published_at']!==null||
   !hash_equals((string)$row['job_hash'],(string)$row['event_hash'])||
   !hash_equals((string)$row['receipt_hash'],(string)$row['event_hash']))
   throw new RuntimeException('saas_delivery_routing_integrity');

  // Confirm event hash, tenant, current task and owned parents once more.
  SaasEventConsumer::verifyEvent($row,$accountId);

  // No external phones, e-mails, device tokens or unscoped channel names.
  // Membership is read afresh on every resolution: inactive/suspended
  // members cannot retain delivery rights through an old receipt or job.
  $members=DB::all(
   "SELECT DISTINCT u.id
    FROM account_members m JOIN users u ON u.id=m.user_id
    WHERE m.account_id=? AND m.status='active' AND u.active=1
      AND (
       (m.membership_role='owner' AND u.role='admin')
       OR (
        u.id=? AND u.role IN ('admin','staff')
        AND (
          u.role='admin' OR EXISTS (
           SELECT 1 FROM user_clients uc
           WHERE uc.user_id=u.id AND uc.client_id=?
          )
        )
       )
      )
    ORDER BY u.id",[$accountId,$row['assigned_to'],$row['task_client_id']]);
  $ids=array_map(static fn(array $v):int=>(int)$v['id'],$members);
  if(!$ids)throw new RuntimeException('saas_delivery_no_authorized_recipients');
  return [
   'account_id'=>$accountId,'job_id'=>$jobId,
   'channel'=>self::CHANNEL,
   'destination_scope'=>'saas.account.'.$accountId.'.events',
   'recipient_user_ids'=>$ids,
   'recipient_count'=>count($ids),
   'external_delivery_enabled'=>false,
   'published'=>false
  ];
 }
}
