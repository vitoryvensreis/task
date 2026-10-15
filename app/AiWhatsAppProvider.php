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

    /** Fallback only for transport, quota, or unavailable model, never for unsafe responses/auth failures. */
    public static function fallbackEligible(string $code): bool
    {
        return in_array($code,['ai_provider_unavailable','ai_connection_failed','ai_provider_rate_limit','ai_provider_model_unavailable','ai_provider_bad_request','ai_usage_daily_limit'],true);
    }

    public static function plan(string $message,array $context,array $tools,?string $preference=null): array
    {
        if(!self::enabled())throw new RuntimeException('ai_not_configured');
        $cfg=config('ai_whatsapp',[]);
        $primary=(string)($cfg['provider']??'gemini');
        $fallback=(string)($cfg['fallback_provider']??'groq');
        $first=null;
        $order=array_values(array_unique([$primary,$fallback]));
        if($preference!==null&&in_array($preference,$order,true)&&self::available($preference)){
            $order=array_values(array_unique([$preference,...$order]));
        }
        foreach($order as $provider){
            if(!self::available($provider))continue;
            try{
                AiWhatsAppUsagePolicy::reserve($provider);
                return match($provider){
                    'gemini'=>AiWhatsAppGemini::plan($message,$context,$tools),
                    'groq'=>AiWhatsAppGroq::plan($message,$context,$tools),
                    default=>throw new RuntimeException('ai_provider_invalid'),
                };
            }catch(RuntimeException $e){
                // Categorical failures only: no prompt, response, API key or chat identifier.
                error_log('TaskEsc AI route provider='.$provider.' failure='.$e->getMessage());
                // Never retry unsafe tool/model responses against another provider.
                if(!self::fallbackEligible($e->getMessage()))throw $e;
                $first??=$e;
            }
        }
        throw $first??new RuntimeException('ai_not_configured');
    }
}
