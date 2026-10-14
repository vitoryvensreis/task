<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$ai=config('ai_whatsapp',[]);
if(!is_array($ai))$ai=[];
echo json([
 'ai_enabled'=>!empty($ai['enabled']),
 'admin_control_enabled'=>AiWhatsAppAdminActions::enabled(),
 'gemini_configured'=>AiWhatsAppGemini::configured(),
 'groq_configured'=>strlen(trim((string)($ai['groq_api_key']??'')))>20,
 'schema_expected'=>29,
 'note'=>'Nenhuma chave/segredo é exibido. O status do banco requer o worker --health.'
])."\n";