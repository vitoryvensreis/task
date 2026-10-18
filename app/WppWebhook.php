<?php
declare(strict_types=1);

final class WppWebhook
{
    public static function handle(array $payload): array
    {
        // Until WPPConnect sessions are individually keyed and authenticated by
        // workspace, reject global inbound delivery in multi-tenant mode.
        if(WorkspaceTenantMode::enabled())return ['accepted'=>false,'reason'=>'workspace_session_not_configured'];
        $source=$payload;
        if(empty($source['event'])&&isset($source['body'])&&is_array($source['body']))$source=$source['body'];

        $event=strtolower(trim((string)($source['event']??$source['type']??'')));
        // Step 1: authenticated inbound events go to a passive, deduplicated inbox.
        // No AI, task changes, outbound messages or realtime publication here.
        if(in_array($event,['onmessage','on-message','message','message.received'],true))return AiWhatsAppInbox::receive($source);
        $status=trim((string)($source['status']??$source['state']??$source['sessionStatus']??''));
        $needle=strtolower($event.' '.$status);

        // Only session lifecycle/QR events belong in the administration panel.
        // Unknown/contact/delivery events remain ignored (inbound messages are handled above).
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
        $providerIdentity=$source['timestamp']??$source['id']??$source['eventId']??null;
        if($providerIdentity===null||is_array($providerIdentity)||is_object($providerIdentity)){
            // WPPConnect status-find historically has no event/session id. A short
            // bucket deduplicates immediate retries without suppressing a later reconnect.
            $providerIdentity=$qrDigest??(string)floor(time()/5);
        }
        $fingerprint=hash('sha256',json_encode([
            'event'=>$event,
            'status'=>$normalizedStatus,
            'connected'=>$connected,
            'qr_digest'=>$qrDigest,
            'provider_identity'=>(string)$providerIdentity,
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
