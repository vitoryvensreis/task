<?php
declare(strict_types=1);
// Compatibilidade com links antigos. A renderização agora acontece somente
// pelo front controller em /contratar, nunca diretamente pelo script físico.
require_once dirname(__DIR__).'/app/bootstrap.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
$query=(string)($_SERVER['QUERY_STRING']??'');
$query=preg_replace('/[\r\n]/','',$query);
header('Location: '.base('contratar').($query!==''?'?'.$query:''),true,302);
exit;
