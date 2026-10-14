<?php
declare(strict_types=1);

/**
 * Optional, conservative local NLU for read-only WhatsApp requests.
 *
 * No model, cached user data or SQL is involved in routing. Any ambiguous
 * message falls through to Gemini/Groq. The worker still authenticates and
 * revalidates the sender/group before executing a selected tool or replying.
 */
final class AiWhatsAppFastPath
{
    public static function plan(string $message,bool $financialPrivate=false,bool $agendaAdmin=false,bool $agendaPrivate=false): ?array
    {
        if(mb_strlen($message,'UTF-8')>180)return null;
        $text=mb_strtolower(trim($message),'UTF-8');
        $text=strtr($text,[
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
        ]);
        $text=preg_replace('/\s+/u',' ',$text)??'';
        $text=trim($text);
        // Remove only a leading vocative, never a reference to a task in the sentence.
        $text=preg_replace('/\A@?(?:taskesc|task)[,:.!?\s]+(?=\S)/u','',$text)??$text;
        // Only remove an explicit, harmless greeting at the start.
        $text=preg_replace('/\A(?:ola|oi)[,!.\s]+(?:taskesc[,!.\s]+)?(?=(?:liste|listar|mostre|mostrar|consulte|consultar|ver|ajuda)\b)/u','',$text)??$text;
        $text=trim($text," \t\n\r\0\x0B.!?");

        if(preg_match('/\A(?:oi|ola|bom dia|boa tarde|boa noite)(?:[,\s]+taskesc)?\z/u',$text)){
            return ['text'=>'Olá! Sou o assistente do TaskEsc. Para começar, envie "Liste minhas tarefas". Também posso ajudar com operações autorizadas.'];
        }
        if(preg_match('/\A(?:ajuda|menu|o que voce faz|o que voce pode fazer|como voce pode me ajudar)\z/u',$text)){
            return ['text'=>'Posso listar suas tarefas ou ajudar a criar, editar e mover demandas, conforme suas permissões. Experimente "Liste minhas tarefas".'];
        }
        // Calendar inventory is private information: only verified ADM in private chat.
        if($agendaPrivate && preg_match('/\A(?:liste|listar|mostre|mostrar|ver|quais sao)\s+(?:as\s+|os\s+)?(?:minhas\s+|meus\s+)?(?:agendas|calendarios)(?:\s+disponiveis)?\z/u',$text)){
            return ['tool'=>'agenda_calendars','arguments'=>[]];
        }
        // Fast path: simple agenda question without Gemini/Groq, only for verified ADM.
        if($agendaAdmin && preg_match('/\A(?:liste|mostre|ver|qual e|quais sao)\s+(?:os\s+)?(?:meus\s+)?(?:compromissos|eventos|agenda)\s+(?:de\s+)?hoje\z/u',$text)){
            $today=(new DateTimeImmutable('today',new DateTimeZone((string)config('timezone','America/Fortaleza'))))->format('Y-m-d');
            return ['tool'=>'agenda_list','arguments'=>['from'=>$today,'to'=>$today]];
        }
        // Exact financial read intents for ADM/private only, with no model request.
        if($financialPrivate && preg_match('/\A(?:(?:me\s+)?(?:fala|diga|diz)\s+)?(?:quanto|o\s+que)\s+(?:ainda\s+)?(?:eu\s+)?(?:tenho\s+)?a\s+receber\s+(?:esse|este)\s+mes\z/u',$text)){
            return ['tool'=>'finance_receivables','arguments'=>['scope'=>'month']];
        }
        if($financialPrivate && preg_match('/\A(?:liste|mostre|ver|quais\s+sao)\s+(?:as\s+)?(?:minhas\s+)?(?:contas|cobrancas)\s+a\s+receber\s+(?:esse|este)\s+mes\z/u',$text)){
            return ['tool'=>'finance_receivables','arguments'=>['scope'=>'month']];
        }
        // Other read-only admin queries also avoid the model, but NEVER bypass
        // the opt-in flag, private-chat restriction, identity or tool allowlist.
        if($financialPrivate && AiWhatsAppAdminActions::enabled()){
            $adminCommands=[
                'liste clientes'=>'list_clients','liste os clientes'=>'list_clients','mostre os clientes'=>'list_clients',
                'liste projetos'=>'list_projects','liste os projetos'=>'list_projects',
                'liste usuarios'=>'list_team','liste a equipe'=>'list_team','liste equipe'=>'list_team',
                'liste agenda'=>'list_events','liste proximos eventos'=>'list_events'
            ];
            if(isset($adminCommands[$text]))return ['tool'=>$adminCommands[$text],'arguments'=>[]];
        }
        // Explicit and complete phrases only. Never accept negations, additions
        // like "e exclua", or unspecified client/project filters in this path.
        if(preg_match('/\A(?:liste|listar|mostre|mostrar|consulte|consultar|ver)\s+(?:as\s+)?(?:minhas\s+)?(?:ultimas\s+)?(?:(\d{1,2})\s+)?(?:tarefas|tasks)\z/u',$text,$m)
            || preg_match('/\A(?:liste|listar|mostre|mostrar|consulte|consultar|ver)\s+(?:as\s+)?(?:minhas\s+)?(?:tarefas|tasks)\s+(?:ultimas\s+)?(\d{1,2})\z/u',$text,$m)){
            $limit=isset($m[1])&&$m[1]!==''?(int)$m[1]:10;
            if($limit<1||$limit>10)return null;
            return ['tool'=>'list_tasks','arguments'=>['limit'=>$limit]];
        }
        return null;
    }
}
