<?php
declare(strict_types=1);
/**
 * Read-only, aggregate-only usage report. CLI:
 *   php bin/ai-usage-report.php [1..30]
 * No WhatsApp data, prompts or credentials are returned.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$input=$argv[1]??'7';
if(!ctype_digit((string)$input)||(int)$input<1||(int)$input>30||count($argv)>2){
 fwrite(STDERR,"Usage: php bin/ai-usage-report.php [days: 1..30]\n");exit(2);
}
echo json(AiWhatsAppUsageMetrics::summarizeFiles((int)$input)).PHP_EOL;
