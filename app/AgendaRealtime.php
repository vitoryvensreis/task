<?php
declare(strict_types=1);

/**
 * Minimal realtime invalidation events for the unified Agenda.
 *
 * Event payloads intentionally omit title, description, attendees, location and
 * other calendar content. Browsers always re-fetch through Agenda HTTP ACLs.
 */
final class AgendaRealtime
{
    private const EVENT_TYPES = [
        'agenda.event_created',
        'agenda.event_updated',
        'agenda.event_cancelled',
        'agenda.event_resolved',
        'agenda.event_synced',
        'agenda.google_changed',
    ];

    private const CALENDAR_TYPES = [
        'agenda.calendar_changed',
        'agenda.calendar_refreshed',
    ];

    public static function event(string $eventType,array $event,string $change,?int $actorId=null): int
    {
        if(!in_array($eventType,self::EVENT_TYPES,true))throw new InvalidArgumentException('Unsupported agenda realtime event.');
        $id=(int)($event['id']??0);$calendarId=(int)($event['calendar_id']??0);
        if($id<1||$calendarId<1||($actorId!==null&&$actorId<1))throw new InvalidArgumentException('Invalid agenda realtime identity.');
        $operationId=bin2hex(random_bytes(16));
        return EventOutbox::enqueue(
            'agenda:event:'.$id.':'.$operationId.':agenda/global',
            $eventType,
            'calendar_event',
            $id,
            'agenda/global',
            [
                'operation_id'=>$operationId,
                'event_id'=>$id,
                'calendar_id'=>$calendarId,
                'demand_id'=>!empty($event['demand_id'])?(int)$event['demand_id']:null,
                'version'=>max(0,(int)($event['version']??0)),
                'status'=>(string)($event['status']??''),
                'change'=>$change,
                'updated_at'=>(string)($event['updated_at']??now()),
            ],
            $actorId
        );
    }

    public static function calendar(string $eventType,int $calendarId,string $change,?int $actorId=null,int $changedCount=0): int
    {
        if(!in_array($eventType,self::CALENDAR_TYPES,true))throw new InvalidArgumentException('Unsupported calendar realtime event.');
        if($calendarId<1||($actorId!==null&&$actorId<1))throw new InvalidArgumentException('Invalid calendar realtime identity.');
        $operationId=bin2hex(random_bytes(16));
        return EventOutbox::enqueue(
            'agenda:calendar:'.$calendarId.':'.$operationId.':agenda/global',
            $eventType,
            'calendar',
            $calendarId,
            'agenda/global',
            [
                'operation_id'=>$operationId,
                'calendar_id'=>$calendarId,
                'change'=>$change,
                'changed_count'=>max(0,$changedCount),
                'updated_at'=>now(),
            ],
            $actorId
        );
    }
}
