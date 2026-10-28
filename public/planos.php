<?php
declare(strict_types=1);
// Compatibilidade com links antigos. A renderização agora acontece somente
// pelo front controller em /planos, nunca diretamente pelo script físico.
require_once dirname(__DIR__).'/app/bootstrap.php';
$query=(string)($_SERVER['QUERY_STRING']??'');
$query=preg_replace('/[\r\n]/','',$query);
header('Location: '.base('planos').($query!==''?'?'.$query:''),true,301);
exit;
