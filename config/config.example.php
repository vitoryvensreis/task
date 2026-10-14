<?php
// Gerado automaticamente pelo instalador. Use um banco exclusivo para o sistema.
return [
 'base_url' => 'https://seu-dominio.com',
 'timezone' => 'America/Fortaleza',
 'session_lifetime_seconds' => 2592000, // 30 dias; mantém o PWA autenticado após fechar/reabrir.
 'db' => ['host'=>'localhost','port'=>3306,'name'=>'taskesc','user'=>'','password'=>''],
 'app_key' => '', // 64 caracteres hexadecimais aleatórios, gerados pelo instalador.
 'cron_key' => '',
 'google_client_id' => '',
 'google_client_secret' => '',
 'smtp' => ['host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from'=>'','from_name'=>'TaskEsc'],
 'wppconnect' => [
   'internal_url' => 'http://127.0.0.1:21465',
   'public_url' => 'https://wppconnect.escotilhaprodutora.com',
   'session' => 'taskesc',
   'secret' => '',
   'webhook_url' => '',
 ],
 'ai_whatsapp' => ['enabled'=>false,'groq_api_key'=>'','model'=>'llama-3.3-70b-versatile'], // Configure APENAS em config/config.php da VPS.
 'trusted_origins' => ['https://chatgpt.com','https://chat.openai.com'],
];
