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
            $rawState=$body['status']??$body['connected']??false;
            $connected=is_bool($rawState)?$rawState:in_array(strtolower(trim((string)$rawState)),['true','connected','islogged','logged','open','inchat'],true);
            $message=(string)($body['message']??($connected?'Conectado':'Sessão ainda não conectada.'));
            $phone=null;
            if($connected){
                $number=self::request('GET','/api/'.rawurlencode($cfg['session']).'/get-phone-number',null,true,true);
                $numberBody=is_array($number['body'])?$number['body']:[];
                $candidate=$numberBody['response']??$numberBody['number']??$numberBody['phone']??$numberBody['id']??null;
                if(is_array($candidate))$candidate=$candidate['user']??$candidate['_serialized']??null;
                if(is_string($candidate)){
                    $digits=preg_replace('/\D+/','',$candidate)??'';
                    if(strlen($digits)>=10&&strlen($digits)<=15)$phone=$digits;
                }
            }
            return [
                'configured'=>true,
                'provider_up'=>true,
                'connected'=>$connected,
                'session'=>$cfg['session'],
                'public_url'=>$cfg['public_url'],
                'phone'=>$phone,
                'webhook_ready'=>true,
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
        Auth::admin();self::requirePost();$cfg=self::requireConfigured();
        $payload=['waitQrCode'=>false,'webhook'=>self::webhookUrl()];
        $response=self::request('POST','/api/'.rawurlencode($cfg['session']).'/start-session',$payload,true);
        return ['started'=>true,'session'=>$cfg['session'],'provider_status'=>(int)$response['status']];
    }

    public static function qr(): array
    {
        Auth::admin();$cfg=self::requireConfigured();
        $response=self::requestRaw('GET','/api/'.rawurlencode($cfg['session']).'/qrcode-session',true);
        $contentType=strtolower((string)($response['content_type']??''));
        if(str_starts_with($contentType,'image/png')){
            $raw=(string)$response['raw'];
            if($raw==='')fail('O WPPConnect retornou um QR Code vazio.',502);
            return [
                'session'=>$cfg['session'],
                'status'=>'qrcode',
                'ready'=>true,
                'mime'=>'image/png',
                'qrcode'=>'data:image/png;base64,'.base64_encode($raw),
                'url_code'=>null,
            ];
        }
        $body=[];
        if(trim((string)$response['raw'])!==''){
            try{$body=json_decode((string)$response['raw'],true,512,JSON_THROW_ON_ERROR);}
            catch(Throwable){$body=[];}
        }
        return [
            'session'=>$cfg['session'],
            'status'=>$body['status']??null,
            'ready'=>!empty($body['qrcode'])||!empty($body['qrCode'])||!empty($body['urlCode'])||!empty($body['url_code']),
            'mime'=>null,
            'qrcode'=>$body['qrcode']??$body['qrCode']??null,
            'url_code'=>$body['urlCode']??$body['url_code']??null,
            'provider'=>$body,
        ];
    }

    public static function sendTest(array $p): array
    {
        Auth::admin();self::requirePost();$cfg=self::requireConfigured();
        $phone=self::normalizePhone((string)($p['phone']??''));
        $message=trim((string)($p['message']??''));
        if($message==='')$message='TaskEsc · teste de integração WPPConnect';
        if(mb_strlen($message)>4000)fail('Mensagem de teste muito longa.',422);
        $response=self::request('POST','/api/'.rawurlencode($cfg['session']).'/send-message',[
            'phone'=>$phone,
            'message'=>$message,
            'isGroup'=>false,
            'isNewsletter'=>false,
        ],true);
        $body=is_array($response['body'])?$response['body']:[];
        $messageId=self::providerMessageId($body);
        return [
            'sent'=>true,
            'phone'=>$phone,
            'provider_status'=>(int)$response['status'],
            'message_id'=>$messageId,
        ];
    }

    public static function sendAutomationMessage(string $phone,string $message): array
    {
        $cfg=self::requireConfigured();
        $phone=self::normalizePhone($phone);
        $message=trim($message);
        if($message==='')throw new InvalidArgumentException('Mensagem de automação vazia.');
        if(mb_strlen($message)>4000)throw new InvalidArgumentException('Mensagem de automação acima de 4000 caracteres.');
        $response=self::request('POST','/api/'.rawurlencode($cfg['session']).'/send-message',[
            'phone'=>$phone,
            'message'=>$message,
            'isGroup'=>false,
            'isNewsletter'=>false,
        ],true);
        $body=is_array($response['body'])?$response['body']:[];
        return [
            'sent'=>true,
            'provider_status'=>(int)$response['status'],
            'message_id'=>self::providerMessageId($body),
        ];
    }

    public static function sessionName(): string
    {
        return self::settings()['session'];
    }

    public static function webhookUrl(): string
    {
        $cfg=self::requireConfigured();
        if($cfg['webhook_url']!=='')return $cfg['webhook_url'];
        $base=rtrim((string)config('base_url',''),'/');
        if($base===''||!preg_match('#^https://#i',$base))throw new RuntimeException('base_url HTTPS é obrigatório para o webhook do WPPConnect.');
        return $base.'/wppconnect-webhook.php?token='.self::webhookToken($cfg['secret']);
    }

    public static function verifyWebhookToken(string $token): bool
    {
        $cfg=self::settings();
        if(!$cfg['configured']||$token==='')return false;
        return hash_equals(self::webhookToken($cfg['secret']),$token);
    }

    private static function webhookToken(string $secret): string
    {
        return hash_hmac('sha256','taskesc-wppconnect-webhook-v1',$secret);
    }

    private static function providerMessageId(array $body): ?string
    {
        $candidates=[
            $body['messageId']??null,
            $body['id']??null,
            $body['response']['messageId']??null,
            $body['response']['id']['_serialized']??null,
            $body['response']['id']??null,
        ];
        foreach($candidates as $candidate){
            if(!is_scalar($candidate)||$candidate===null)continue;
            $value=trim((string)$candidate);
            if($value!==''&&mb_strlen($value)<=255)return $value;
        }
        return null;
    }

    public static function normalizePhone(string $value): string
    {
        $digits=preg_replace('/\D+/','',$value)??'';
        if(strlen($digits)<10||strlen($digits)>15)fail('Número de WhatsApp inválido. Use DDI + DDD + número.',422);
        return $digits;
    }

    private static function requirePost(): void
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')fail('Método não permitido.',405);
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

    private static function requestRaw(string $method,string $path,bool $auth=true): array
    {
        $cfg=self::settings();
        $url=$cfg['internal_url'].'/'.ltrim($path,'/');
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Não foi possível iniciar a conexão com WPPConnect.');
        $headers=['Accept: image/png, application/json'];
        if($auth)$headers[]='Authorization: Bearer '.self::token();
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
        $contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
        $error=curl_error($ch);
        curl_close($ch);
        if($raw===false||$error!=='')throw new RuntimeException('Falha ao conectar ao WPPConnect.');
        if($status<200||$status>=300)throw new AppError('WPPConnect respondeu com erro ao solicitar QR Code.',502,['provider_status'=>$status]);
        return ['status'=>$status,'content_type'=>$contentType,'raw'=>(string)$raw];
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
            catch(Throwable){$decoded=['provider_response'=>'non_json'];}
        }
        $ok=$status>=200&&$status<300;
        if(!$ok&&!$allowError){
            $message=is_array($decoded)?(string)($decoded['message']??$decoded['error']??''):'';
            throw new AppError($message!==''?$message:'WPPConnect respondeu com erro.',502,['provider_status'=>$status]);
        }
        return ['ok'=>$ok,'status'=>$status,'body'=>$decoded];
    }
}
