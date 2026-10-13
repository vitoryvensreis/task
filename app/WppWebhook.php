<?php
declare(strict_types=1);

final class WppWebhook
{
    public static function handle(array $payload): array
    {
        $source=$payload;
        if(empty($source['event'])&&isset($source['body'])&&is_array($source['body']))$source=$source['body'];

        $event=strtolower(trim((string)($source['event']??$source['type']??'')));
        $status=trim((string)($source['status']??$source['state']??$source['sessionStatus']??''));
        $needle=strtolower($event.' '.$status);

        // Only session lifecycle/QR events belong in the administration panel.
        // Message/contact payloads are intentionally ignored here.
        $lifecycle=$needle!==''&&(bool)preg_match('/(?:qr|status|state|session|stream|interface|connection|connect|logged|logout|close|pair|unlaunch|opening|inchat)/',$needle);
        if(!$lifecycle)return ['accepted'=>false,'reason'=>'event_ignored'];

        $qrValue=$source['qrcode']??$source['qrCode']??$source['urlCode']??$source['urlcode']??null;
        $qrAvailable=$qrValue!==null&&trim((string)$qrValue)!=='';
        if(str_contains($event,'qr'))$qrAvailable=true;

        $normalizedStatus=strtolower(trim($status));
        $connected=null;
        if($normalizedStatus!==''){
            if(in_array($normalizedStatus,['islogged','logged','connected','open','inchat'],true))$connected=true;
            elseif(in_array($normalizedStatus,['notlogged','disconnected','closed','close','unpaired','unlaunched','qr','pairing'],true))$connected=false;
        }

        // Include only a digest of QR/state input for idempotency. Raw provider
        // payloads never enter the Outbox or Centrifugo.
        $qrDigest=$qrValue===null?null:hash('sha256',(string)$qrValue);
        $fingerprint=hash('sha256',json_encode([
            'event'=>$event,
            'status'=>$normalizedStatus,
            'connected'=>$connected,
            'qr_digest'=>$qrDigest,
            'session'=>(string)($source['session']??WppConnect::sessionName()),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        EventOutbox::transaction(fn()=>WppRealtime::changed(
            $event!==''?$event:'provider',
            $normalizedStatus!==''?$normalizedStatus:null,
            $connected,
            $qrAvailable,
            $fingerprint
        ));

        return [
            'accepted'=>true,
            'event'=>$event!==''?$event:'provider',
            'status'=>$normalizedStatus!==''?$normalizedStatus:null,
            'connected'=>$connected,
            'qr_available'=>$qrAvailable,
        ];
    }
}
