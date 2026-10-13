<?php
declare(strict_types=1);

final class WppConnect
{
    private static ?string $token=null;

    public static function status(): array
    {
        Auth::admin();
        $cfg=self::settings();
        if(!$cfg['configured'])return [
            'configured'=>false,
            'provider_up'=>false,
            'connected'=>false,
            'session'=>$cfg['session'],
            'public_url'=>$cfg['public_url'],
            'message'=>'Configure o WPPConnect no config/config.php.',
        ];

        $health=self::request('GET','/healthz',null,false,true);
        if(!$health['ok'])return [
            'configured'=>true,
            'provider_up'=>false,
            'connected'=>false,
            'session'=>$cfg['session'],
            'public_url'=>$cfg['public_url'],
            'message'=>'WPPConnect indisponível no endpoint interno.',
            'http_status'=>$health['status'],
        ];

        try{
            $state=self::request('GET','/api/'.rawurlencode($cfg['session']).'/check-connection-session',null,true,true);
            $body=is_array($state['body'])?$state['body']:[];
            $connected=(bool)($body['status']??$body['connected']??false);
            $message=(string)($body['message']??($connected?'Conectado':'Sessão ainda não conectada.'));
            return [
                'configured'=>true,
                'provider_up'=>true,
                'connected'=>$connected,
                'session'=>$cfg['session'],
                'public_url'=>$cfg['public_url'],
                'provider'=>$body,
                'message'=>$message,
            ];
        }catch(Throwable $e){
            return [
                'configured'=>true,
                'provider_up'=>true,
                'connected'=>false,
                'session'=>$cfg['session'],
                'public_url'=>$cfg['public_url'],
                'message'=>'WPPConnect ativo, mas a sessão ainda não está pronta.',
            ];
        }
    }

    public static function start(): array
    {
        Auth::admin();$cfg=self::requireConfigured();
        $payload=['waitQrCode'=>false];
        if($cfg['webhook_url']!=='')$payload['webhook']=$cfg['webhook_url'];
        $response=self::request('POST','/api/'.rawurlencode($cfg['session']).'/start-session',$payload,true);
        return ['started'=>true,'session'=>$cfg['session'],'provider'=>$response['body']];
    }

    public static function qr(): array
    {
        Auth::admin();$cfg=self::requireConfigured();
        $response=self::request('GET','/api/'.rawurlencode($cfg['session']).'/qrcode-session',null,true);
        $body=is_array($response['body'])?$response['body']:[];
        return [
            'session'=>$cfg['session'],
            'status'=>$body['status']??null,
            'qrcode'=>$body['qrcode']??$body['qrCode']??null,
            'url_code'=>$body['urlCode']??$body['url_code']??null,
            'provider'=>$body,
        ];
    }

    public static function sendTest(array $p): array
    {
        Auth::admin();$cfg=self::requireConfigured();
        $phone=self::normalizePhone((string)($p['phone']??''));
        $message=trim((string)($p['message']??''));
        if($message==='')$message='TaskEsc · teste de integração WPPConnect';
        if(mb_strlen($message)>4000)fail('Mensagem de teste muito longa.',422);
        $response=self::request('POST','/api/'.rawurlencode($cfg['session']).'/send-message',[
            'phone'=>$phone,
            'message'=>$message,
        ],true);
        return ['sent'=>true,'phone'=>$phone,'provider'=>$response['body']];
    }

    public static function normalizePhone(string $value): string
    {
        $digits=preg_replace('/\D+/','',$value)??'';
        if(strlen($digits)<10||strlen($digits)>15)fail('Número de WhatsApp inválido. Use DDI + DDD + número.',422);
        return $digits;
    }

    private static function requireConfigured(): array
    {
        $cfg=self::settings();
        if(!$cfg['configured'])fail('WPPConnect ainda não foi configurado no servidor.',503);
        return $cfg;
    }

    private static function settings(): array
    {
        $raw=config('wppconnect',[]);
        if(!is_array($raw))$raw=[];
        $internal=rtrim((string)($raw['internal_url']??'http://127.0.0.1:21465'),'/');
        $public=rtrim((string)($raw['public_url']??'https://wppconnect.escotilhaprodutora.com'),'/');
        $session=trim((string)($raw['session']??'taskesc'));
        $secret=trim((string)($raw['secret']??''));
        $webhook=trim((string)($raw['webhook_url']??''));
        $validInternal=(bool)preg_match('#^https?://(?:127\.0\.0\.1|localhost)(?::\d{1,5})?$#i',$internal);
        return [
            'internal_url'=>$internal,
            'public_url'=>$public,
            'session'=>$session!==''?$session:'taskesc',
            'secret'=>$secret,
            'webhook_url'=>$webhook,
            'configured'=>$validInternal&&strlen($secret)>=32,
        ];
    }

    private static function token(): string
    {
        if(self::$token!==null)return self::$token;
        $cfg=self::requireConfigured();
        $path='/api/'.rawurlencode($cfg['session']).'/'.rawurlencode($cfg['secret']).'/generate-token';
        $response=self::request('POST',$path,[],false);
        $body=is_array($response['body'])?$response['body']:[];
        $token=trim((string)($body['token']??''));
        if($token==='')throw new RuntimeException('WPPConnect não retornou token de sessão.');
        return self::$token=$token;
    }

    private static function request(string $method,string $path,?array $payload=null,bool $auth=true,bool $allowError=false): array
    {
        $cfg=self::settings();
        $url=$cfg['internal_url'].'/'.ltrim($path,'/');
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Não foi possível iniciar a conexão com WPPConnect.');
        $headers=['Accept: application/json'];
        if($auth)$headers[]='Authorization: Bearer '.self::token();
        if($payload!==null){
            $body=json($payload);
            $headers[]='Content-Type: application/json; charset=utf-8';
            curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        }
        curl_setopt_array($ch,[
            CURLOPT_CUSTOMREQUEST=>strtoupper($method),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>$headers,
        ]);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($raw===false||$error!==''){
            if($allowError)return ['ok'=>false,'status'=>0,'body'=>null,'error'=>$error?:'connection_failed'];
            throw new RuntimeException('Falha ao conectar ao WPPConnect.');
        }
        $decoded=null;
        if(trim((string)$raw)!==''){
            try{$decoded=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);}
            catch(Throwable){$decoded=['raw'=>mb_substr((string)$raw,0,2000)];}
        }
        $ok=$status>=200&&$status<300;
        if(!$ok&&!$allowError){
            $message=is_array($decoded)?(string)($decoded['message']??$decoded['error']??''):'';
            throw new AppError($message!==''?$message:'WPPConnect respondeu com erro.',502,['provider_status'=>$status]);
        }
        return ['ok'=>$ok,'status'=>$status,'body'=>$decoded];
    }
}
