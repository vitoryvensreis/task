<?php
declare(strict_types=1);

/**
 * Realtime invalidation for the single TaskEsc WhatsApp session.
 * Never publishes QR content, provider tokens, message bodies or phone secrets.
 */
final class WppRealtime
{
    public static function changed(
        string $kind,
        ?string $status,
        ?bool $connected,
        bool $qrAvailable,
        string $fingerprint
    ): int {
        $kind=self::label($kind,'provider');
        $status=$status===null?null:self::label($status,'unknown');
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new InvalidArgumentException('Invalid WPPConnect event fingerprint.');
        $operationId=substr($fingerprint,0,32);
        return EventOutbox::enqueue(
            'wpp:'.$fingerprint.':wpp/global',
            'wpp.changed',
            'wpp_session',
            1,
            'wpp/global',
            [
                'operation_id'=>$operationId,
                'kind'=>$kind,
                'status'=>$status,
                'connected'=>$connected,
                'qr_available'=>$qrAvailable,
                'received_at'=>now(),
            ],
            null
        );
    }

    private static function label(string $value,string $fallback): string
    {
        $value=strtolower(trim($value));
        $value=preg_replace('/[^a-z0-9_.:-]+/','-',$value)??'';
        $value=trim($value,'-');
        if($value==='')$value=$fallback;
        return substr($value,0,80);
    }
}
