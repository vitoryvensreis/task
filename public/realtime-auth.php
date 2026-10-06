<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Vary: Cookie');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        fail('Método não permitido.', 405);
    }

    $base = parse_url((string)config('base_url', ''));
    $expectedOrigin = is_array($base) && isset($base['scheme'], $base['host'])
        ? $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '')
        : '';
    $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if (($origin !== '' && ($expectedOrigin === '' || !hash_equals($expectedOrigin, $origin)))
        || ($fetchSite !== '' && $fetchSite !== 'same-origin')) {
        fail('Origem realtime não autorizada.', 403);
    }

    installed();
    Auth::load();
    if (Auth::$origin !== 'web') fail('Realtime disponível apenas para sessão web autenticada.', 403);
    checkCsrf();

    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) fail('Envie JSON.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($raw) || strlen($raw) > 4096) fail('Requisição realtime inválida.', 413);
    $input = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) fail('JSON inválido.', 400);

    $auth = new RealtimeAuth();
    out(array_key_exists('channel', $input) ? $auth->subscription($input) : $auth->connection($input));
} catch (JsonException) {
    out(['error' => 'JSON inválido.'], 400);
} catch (AppError $e) {
    out(['error' => $e->getMessage()], $e->status);
} catch (PDOException $e) {
    if (class_exists('SystemHealth')) SystemHealth::capture($e, 'realtime.auth.database');
    error_log('realtime.auth database failure');
    out(['error' => 'Realtime temporariamente indisponível.'], 503);
} catch (Throwable $e) {
    if (class_exists('SystemHealth')) SystemHealth::capture($e, 'realtime.auth');
    error_log('realtime.auth internal failure');
    out(['error' => 'Realtime temporariamente indisponível.'], 503);
}
