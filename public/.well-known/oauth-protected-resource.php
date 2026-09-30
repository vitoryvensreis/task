<?php
require dirname(__DIR__,2).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
echo json(['resource'=>base('mcp.php'),'authorization_servers'=>[],'scopes_supported'=>['tools.read','tools.write']]);
