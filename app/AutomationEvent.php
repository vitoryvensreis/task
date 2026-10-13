<?php
declare(strict_types=1);

/**
 * Minimal business events used by the automation engine outside the demand domain.
 * Events are written to the same transactional Outbox as the business mutation.
 */
final class AutomationEvent
{
    private const TYPES=[
        'client.created',
        'client.team_member_added',
    ];

    public static function emit(string $eventType,string $entityType,int $entityId,array $payload=[],?int $actorId=null): int
    {
        if(!in_array($eventType,self::TYPES,true))throw new InvalidArgumentException('Unsupported automation business event.');
        if(!in_array($entityType,['client'],true)||$entityId<1||($actorId!==null&&$actorId<1))throw new InvalidArgumentException('Invalid automation business event identity.');
        $operationId=bin2hex(random_bytes(16));
        $safe=['operation_id'=>$operationId];
        foreach($payload as $key=>$value){
            if(!preg_match('/\A[a-z0-9_]{1,64}\z/D',(string)$key))throw new InvalidArgumentException('Invalid automation payload key.');
            if($value!==null&&!is_scalar($value))throw new InvalidArgumentException('Automation payload values must be scalar.');
            $safe[(string)$key]=$value;
        }
        return EventOutbox::enqueue(
            'automation:'.$eventType.':'.$entityType.':'.$entityId.':'.$operationId,
            $eventType,
            $entityType,
            $entityId,
            'staff/global',
            $safe,
            $actorId
        );
    }
}
