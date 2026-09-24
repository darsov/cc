<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/datareon_shops_lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Ожидается POST.']);
    exit;
}

try {
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new InvalidArgumentException('Ожидается Content-Type: application/json.');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 256 * 1024) {
        http_response_code(413);
        throw new InvalidArgumentException('Сообщение превышает 256 КБ.');
    }
    $body = file_get_contents('php://input', false, null, 0, 256 * 1024 + 1);
    if (!is_string($body) || $body === '' || strlen($body) > 256 * 1024) {
        throw new InvalidArgumentException('Пустое или слишком большое сообщение.');
    }
    $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Ожидается JSON-объект.');
    }
    $shop = ccNormalizeDatareonShop($payload);
    $result = ccStoreDatareonShop(ccDb(), $shop);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $e) {
    if (http_response_code() !== 413) http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('CC Datareon shops ingest error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ошибка сохранения магазина.'], JSON_UNESCAPED_UNICODE);
}
