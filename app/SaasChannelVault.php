<?php
declare(strict_types=1);

/**
 * Staged per-tenant credential vault. No delivery or provider is activated.
 * All secret mutation requires an active owner web session and internal account.
 * AES-256-GCM AAD binds each secret to the tenant, channel, provider and revision.
 * Credentials are NEVER returned by API/metadata or written to audit logs.
 */
final class SaasChannelVault {
 private const PROVIDERS=[
  'whatsapp'=>'wppconnect',
  'email'=>'smtp',
  'push'=>'webpush',
  'realtime'=>'centrifugo'
 ];

 private static function owner(): array {
  $membership=SaasRequestScope::owner();
  $version=(int)(DB::one('SELECT value FROM settings WHERE `key`=?',
   [SaasChannelMigration::MARKER])['value']??0);
  if($version!==SaasChannelMigration::VERSION)fail('Cofre SaaS ainda não migrado.',503);
  return $membership;
 }

 private static function provider(string $channel): string {
  if(!isset(self::PROVIDERS[$channel]))fail('Canal de entrega não suportado.',422);
  return self::PROVIDERS[$channel];
 }

 private static function key(): string {
  $raw=(string)config('app_key','');
  if(!preg_match('/\A[a-f0-9]{64}\z/iD',$raw))
   throw new RuntimeException('APP_KEY ausente ou inválida; credenciais não foram armazenadas.');
  $key=hex2bin($raw);
  if($key===false)throw new RuntimeException('APP_KEY inválida.');
  return hash_hkdf('sha256',$key,32,'taskesc-saas-credentials:v1');
 }

 private static function aad(int $account,string $channel,string $provider,int $revision): string {
  return 'taskesc:v1:credentials:'.$account.':'.$channel.':'.$provider.':'.$revision;
 }

 private static function seal(string $secret,int $account,string $channel,string $provider,int $revision): array {
  $key=self::key();
  $iv=random_bytes(12);$tag='';
  $cipher=openssl_encrypt($secret,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,
   self::aad($account,$channel,$provider,$revision),16);
  if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Falha ao proteger a credencial.');
  return ['envelope'=>'v1:'.base64_encode($iv.$tag.$cipher),
   'fingerprint'=>hash_hmac('sha256',$secret,$key)];
 }

 /** Proof of ciphertext integrity; does not expose plaintext. */
 public static function verify(string $channel): bool {
  $membership=self::owner();
  $account=(int)$membership['account_id'];$provider=self::provider($channel);
  $r=DB::one('SELECT secret_envelope,fingerprint,provider,revision FROM saas_channel_secrets'.
   ' WHERE tenant_account_id=? AND channel=?',[$account,$channel]);
  if(!$r)return false;
  if((string)$r['provider']!==$provider)return false;
  $raw=(string)$r['secret_envelope'];
  if(!str_starts_with($raw,'v1:'))return false;
  $decoded=base64_decode(substr($raw,3),true);
  if($decoded===false||strlen($decoded)<29)return false;
  $key=self::key();
  $plain=openssl_decrypt(substr($decoded,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,
   substr($decoded,0,12),substr($decoded,12,16),
   self::aad($account,$channel,$provider,(int)$r['revision']));
  if($plain===false)return false;
  return hash_equals((string)$r['fingerprint'],hash_hmac('sha256',$plain,$key));
 }

 /** Store/rotate an opaque API secret, while retaining DISABLED state. */
 public static function stage(string $channel,string $secret): array {
  $membership=self::owner();$account=(int)$membership['account_id'];
  $provider=self::provider($channel);
  if(strlen($secret)<24||strlen($secret)>8192||trim($secret)!==$secret)
   fail('A credencial precisa ter de 24 a 8192 caracteres sem espaços nas extremidades.',422);
  $actor=(int)(Auth::$actor['id']??0);
  if($actor<1)fail('Sessão de administrador inválida.',403);
  return DB::tx(static function()use($account,$channel,$provider,$secret,$actor):array {
   $old=DB::one('SELECT id,revision FROM saas_channel_secrets'.
    ' WHERE tenant_account_id=? AND channel=? FOR UPDATE',[$account,$channel]);
   $revision=$old?(int)$old['revision']+1:1;
   if($revision>4294967294)throw new RuntimeException('Limite de rotação de credenciais excedido.');
   $encrypted=self::seal($secret,$account,$channel,$provider,$revision);
   $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
   if($old){
    DB::run('UPDATE saas_channel_secrets'.
     ' SET provider=?,secret_envelope=?,fingerprint=?,revision=?,status=?,updated_by=?,updated_at=?'.
     ' WHERE id=? AND tenant_account_id=?',
     [$provider,$encrypted['envelope'],$encrypted['fingerprint'],$revision,'disabled',$actor,$now,$old['id'],$account]);
   }else{
    DB::insert('saas_channel_secrets',[
     'tenant_account_id'=>$account,'channel'=>$channel,'provider'=>$provider,
     'secret_envelope'=>$encrypted['envelope'],'fingerprint'=>$encrypted['fingerprint'],
     'revision'=>$revision,'status'=>'disabled','updated_by'=>$actor,'created_at'=>$now,'updated_at'=>$now
    ]);
   }
   // Never log or return credential material, ciphertext or fingerprints.
   return ['channel'=>$channel,'provider'=>$provider,'revision'=>$revision,
    'status'=>'disabled','external_delivery_enabled'=>false];
  });
 }

 /** Non-secret status of staged integrations, for verified internal owners. */
 public static function metadata(): array {
  $member=self::owner();$account=(int)$member['account_id'];
  return DB::all('SELECT channel,provider,revision,status,updated_at'.
   ' FROM saas_channel_secrets WHERE tenant_account_id=? ORDER BY channel',[$account]);
 }
}
