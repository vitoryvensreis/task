<?php
declare(strict_types=1);

final class ChatRealtime
{
    public static function message(array $message): array
    {
        $id=(int)($message['id']??0);
        $threadId=(int)($message['thread_id']??0);
        $senderId=(int)($message['sender_id']??0);
        $recipientId=(int)($message['recipient_id']??0);
        if($id<1||$threadId<1||$senderId<1||$recipientId<1)throw new InvalidArgumentException('Invalid chat realtime identity.');

        $payload=[
            'message_id'=>$id,
            'thread_id'=>$threadId,
            'sender_id'=>$senderId,
            'recipient_id'=>$recipientId,
            'created_at'=>(string)($message['created_at']??now()),
        ];
        $operation='chat:message:'.$id;
        $ids=[];
        foreach(array_values(array_unique([$senderId,$recipientId])) as $userId){
            $channel='user:'.$userId;
            $ids[$channel]=EventOutbox::enqueue($operation.':'.$channel,'chat.message','chat_message',$id,$channel,$payload,$senderId);
        }
        return $ids;
    }

    public static function read(int $threadId,int $readerId,int $peerId,int $lastReadId): array
    {
        if($threadId<1||$readerId<1||$peerId<1||$lastReadId<1)throw new InvalidArgumentException('Invalid chat read identity.');
        $payload=[
            'thread_id'=>$threadId,
            'reader_id'=>$readerId,
            'peer_id'=>$peerId,
            'last_read_id'=>$lastReadId,
            'read_at'=>now(),
        ];
        $operation='chat:read:'.$threadId.':'.$readerId.':'.$lastReadId;
        $ids=[];
        foreach(array_values(array_unique([$readerId,$peerId])) as $userId){
            $channel='user:'.$userId;
            $ids[$channel]=EventOutbox::enqueue($operation.':'.$channel,'chat.read','chat_thread',$threadId,$channel,$payload,$readerId);
        }
        return $ids;
    }
}
