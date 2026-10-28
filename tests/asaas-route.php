<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/PlatformAsaasBilling.php';

// Regressão do endpoint real usado na validação do Asaas.
// Teste local puro: não exige chave, banco nem conexão com o provedor.
$validator=(new ReflectionClass(PlatformAsaasBilling::class))->getMethod('validApiRoute');
$allowed=[
 '/v3/myAccount/status/',
 '/v3/customers',
 '/v3/subscriptions/sub_AbC123/payments?limit=100&offset=0',
];
$blocked=[
 'https://api.asaas.com/v3/myAccount/status/',
 '/v2/myAccount/status/',
 '/v3/../../etc/passwd',
 '//external.example/',
 '/v3/subscriptions/%2Fetc',
];
foreach($allowed as $route){
 if($validator->invoke(null,$route)!==true)throw new RuntimeException('Asaas API route refused: '.$route);
}
foreach($blocked as $route){
 if($validator->invoke(null,$route)!==false)throw new RuntimeException('Invalid Asaas API route accepted: '.$route);
}
echo "Asaas API routes: OK\n";
