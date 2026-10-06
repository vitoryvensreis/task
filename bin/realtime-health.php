<?php
declare(strict_types=1);

// Outside DocumentRoot. Only CLI or a direct loopback FastCGI probe can run it.
if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'fpm-fcgi' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1')) {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
require dirname(__DIR__).'/app/Realtime.php';
$health = (new Realtime())->health();
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
}
echo json_encode($health, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
if (PHP_SAPI === 'cli') exit($health['status'] === 'ok' ? 0 : 1);
