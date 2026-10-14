<?php
declare(strict_types=1);

/** Provider routing is fail-closed and never performs business mutations. */
final class AiWhatsAppProvider
{
    public static function enabled(): bool
    {
        $cfg=config('ai_whatsapp',[]);
        if(!is_array($cfg)||empty($cfg['enabled']))return false;
        return self::available((string)($cfg['provider']??'gemini'))
            || self::available((string)($cfg['fallback_provider']??'groq'));
    }

    private static function available(string $provider): bool
    {
        return match($provider){
            'gemini'=>AiWhatsAppGemini::configured(),
            'groq'=>self::groqConfigured(),
            default=>false,
        };
    }

    private static function groqConfigured(): bool
    {
        $cfg=config('ai_whatsapp',[]);
        return is_array($cfg)&&strlen(trim((string)($cfg['groq_api_key']??'')))>20;
    }

    public static function status(): array
    {
        $cfg=config('ai_whatsapp',[]);
        return ['enabled'=>self::enabled(), 'primary'=>(string)($cfg['provider']??'gemini'),
            'fallback'=>(string)($cfg['fallback_provider']??'groq'),
            'gemini_configured'=>AiWhatsAppGemini::configured(),
            'groq_configured'=>self::groqConfigured()];
    }

    public static function plan(string $message,array $context,array $tools): array
    {
        if(!self::enabled())throw new RuntimeException('ai_not_configured');
        $cfg=config('ai_whatsapp',[]);
        $primary=(string)($cfg['provider']??'gemini');
        $fallback=(string)($cfg['fallback_provider']??'groq');
        $first=null;
        foreach(array_unique([$primary,$fallback]) as $provider){
            if(!self::available($provider))continue;
            try{
                return match($provider){
                    'gemini'=>AiWhatsAppGemini::plan($message,$context,$tools),
                    'groq'=>AiWhatsAppGroq::plan($message,$context,$tools),
                    default=>throw new RuntimeException('ai_provider_invalid'),
                };
            }catch(RuntimeException $e){
                // Fallback only on transport/provider outage. A malformed or unsafe tool
                // response fails closed instead of being retried against another model.
                if(!in_array($e->getMessage(),['ai_provider_unavailable','ai_connection_failed'],true))throw $e;
                $first??=$e;
            }
        }
        throw $first??new RuntimeException('ai_not_configured');
    }
}
