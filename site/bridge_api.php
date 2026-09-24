<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/card_refund_lib.php';
require_once __DIR__ . '/card_services.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ccBridgeReply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ccBridgeVerify(string $body): void
{
    $timestamp = trim((string)($_SERVER['HTTP_X_OMNI_TIMESTAMP'] ?? ''));
    $signature = strtolower(trim((string)($_SERVER['HTTP_X_OMNI_SIGNATURE'] ?? '')));
    if (!preg_match('/^\d{10}$/', $timestamp) || abs(time() - (int)$timestamp) > 300) {
        throw new RuntimeException('Некорректная или просроченная временная метка.');
    }
    if (str_starts_with($signature, 'sha256=')) {
        $signature = substr($signature, 7);
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
        throw new RuntimeException('Некорректная подпись запроса.');
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/bridge_api.php');
    $path = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '/bridge_api.php');
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $requestTarget = $path . ($query !== '' ? '?' . $query : '');
    $expected = hash_hmac(
        'sha256',
        $timestamp . "\n" . $method . "\n" . $requestTarget . "\n" . $body,
        ccBridgeSecret()
    );
    if (!hash_equals($expected, $signature)) {
        throw new RuntimeException('Подпись запроса не совпадает.');
    }
}

function ccBridgeJsonBody(string $body): array
{
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new InvalidArgumentException('Ожидается Content-Type: application/json.');
    }
    if ($body === '') {
        throw new InvalidArgumentException('Пустое тело запроса.');
    }
    $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Ожидается JSON-объект.');
    }
    return $decoded;
}

function ccBridgeSyncUsers(PDO $pdo, array $payload): array
{
    $users = $payload['users'] ?? null;
    if (!is_array($users) || count($users) > 5000) {
        throw new InvalidArgumentException('Массив users отсутствует или превышает 5000 записей.');
    }
    $validated = [];
    $seenIds = [];
    $seenEmails = [];
    foreach ($users as $index => $user) {
        if (!is_array($user)) {
            throw new InvalidArgumentException('Пользователь #' . ($index + 1) . ': ожидается объект.');
        }
        $omniUserId = (int)($user['omni_user_id'] ?? 0);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $passwordHash = (string)($user['password_hash'] ?? '');
        $departmentName = trim((string)($user['department_name'] ?? ''));
        if ($omniUserId <= 0 || isset($seenIds[$omniUserId])) {
            throw new InvalidArgumentException('Пользователь #' . ($index + 1) . ': некорректный или повторяющийся ID.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seenEmails[$email])) {
            throw new InvalidArgumentException('Пользователь #' . ($index + 1) . ': некорректный или повторяющийся email.');
        }
        if (strlen($passwordHash) > 255 || (password_get_info($passwordHash)['algo'] ?? null) === null) {
            throw new InvalidArgumentException('Пользователь #' . ($index + 1) . ': некорректный хеш пароля.');
        }
        if ($departmentName === '' || mb_strlen($departmentName, 'UTF-8') > 120) {
            throw new InvalidArgumentException('Пользователь #' . ($index + 1) . ': некорректный отдел.');
        }
        $seenIds[$omniUserId] = true;
        $seenEmails[$email] = true;
        $validated[] = compact('omniUserId', 'email', 'passwordHash', 'departmentName');
    }

    $upsert = $pdo->prepare(
        'INSERT INTO `cc_users`
            (`omni_user_id`, `email`, `password_hash`, `department_name`, `is_active`, `synced_at`)
         VALUES
            (:omni_user_id, :email, :password_hash, :department_name, 1, NOW())
         ON DUPLICATE KEY UPDATE
            `omni_user_id` = VALUES(`omni_user_id`),
            `email` = VALUES(`email`),
            `password_hash` = VALUES(`password_hash`),
            `department_name` = VALUES(`department_name`),
            `is_active` = 1,
            `failed_login_attempts` = 0,
            `locked_until` = NULL,
            `synced_at` = NOW()'
    );
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE `cc_users` SET `is_active` = 0, `synced_at` = NOW()');
        foreach ($validated as $user) {
            $upsert->execute([
                'omni_user_id' => $user['omniUserId'],
                'email' => $user['email'],
                'password_hash' => $user['passwordHash'],
                'department_name' => $user['departmentName'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['active' => count($validated)];
}

function ccBridgeRefunds(PDO $pdo): array
{
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 500)));
    $stmt = $pdo->query(
        'SELECT `id`, `payload_encrypted`
         FROM `cc_card_refund_queue`
         WHERE `queue_status` = \'pending\'
         ORDER BY `id`
         LIMIT ' . $limit
    );
    $records = [];
    foreach ($stmt->fetchAll() as $row) {
        $record = ccRefundDecryptPayload((string)$row['payload_encrypted']);
        $records[] = ['queue_id' => (int)$row['id']] + $record;
    }
    return ['records' => $records];
}

function ccBridgeAcknowledgeRefunds(PDO $pdo, array $payload): array
{
    $ids = $payload['queue_ids'] ?? null;
    if (!is_array($ids) || !$ids || count($ids) > 500) {
        throw new InvalidArgumentException('queue_ids должен содержать от 1 до 500 идентификаторов.');
    }
    $normalized = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            throw new InvalidArgumentException('queue_ids содержит некорректный идентификатор.');
        }
        $normalized[$id] = true;
    }
    $placeholders = implode(',', array_fill(0, count($normalized), '?'));
    $stmt = $pdo->prepare(
        'UPDATE `cc_card_refund_queue`
         SET `payload_encrypted` = NULL,
             `queue_status` = \'exported\',
             `exported_at` = NOW()
         WHERE `queue_status` = \'pending\'
           AND `id` IN (' . $placeholders . ')'
    );
    $stmt->execute(array_keys($normalized));
    return ['acknowledged' => $stmt->rowCount()];
}

function ccBridgeLookupOrganizations(PDO $pdo, array $payload): array
{
    $cards = $payload['card_numbers'] ?? null;
    if (!is_array($cards) || count($cards) < 1 || count($cards) > 50) {
        throw new InvalidArgumentException('Укажите от 1 до 50 номеров карт.');
    }
    $numbers = [];
    foreach ($cards as $card) {
        $number = ccCardNumber((string)$card);
        $numbers[$number] = true;
    }
    ccEnsureCardToolsSchema($pdo);
    $results = ccFindGiftCardOrganizationsBatch(array_keys($numbers));
    ccStoreCardOrganizations($pdo, $results);
    return ['organizations' => $results];
}

function ccBridgeSyncShops(PDO $pdo, array $payload): array
{
    $shops = $payload['shops'] ?? null;
    if (!is_array($shops) || count($shops) > 5000) {
        throw new InvalidArgumentException('Некорректный список магазинов.');
    }
    $upsertShop = $pdo->prepare('INSERT INTO `cc_shops`
        (`datareon_shop_id`,`shop_id`,`organization_id`,`organization_name`,`synced_at`)
        VALUES (:datareon_shop_id,:shop_id,:organization_id,:organization_name,NOW())
        ON DUPLICATE KEY UPDATE `shop_id`=VALUES(`shop_id`),
            `organization_id`=VALUES(`organization_id`),
            `organization_name`=VALUES(`organization_name`),`synced_at`=NOW()');
    $upsertOrg = $pdo->prepare('INSERT INTO `cc_organizations` (`id`,`name`)
        VALUES (:id,:name) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`)');
    $pdo->beginTransaction();
    try {
        foreach ($shops as $shop) {
            if (!is_array($shop)) throw new InvalidArgumentException('Некорректный магазин.');
            $id = trim((string)($shop['datareon_shop_id'] ?? ''));
            $shopId = trim((string)($shop['shop_id'] ?? ''));
            $orgId = strtolower(trim((string)($shop['organization_id'] ?? '')));
            $orgName = trim((string)($shop['organization_name'] ?? ''));
            if ($id === '' || strlen($id) > 255 || strlen($shopId) > 255
                || strlen($orgName) > 255 || ($orgId !== ''
                && !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $orgId))) {
                throw new InvalidArgumentException('Некорректные данные магазина.');
            }
            $upsertShop->execute([
                'datareon_shop_id' => $id, 'shop_id' => $shopId ?: null,
                'organization_id' => $orgId ?: null, 'organization_name' => $orgName ?: null,
            ]);
            if ($orgId !== '' && $orgName !== '') $upsertOrg->execute(['id' => $orgId, 'name' => $orgName]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['synced' => count($shops)];
}

$body = file_get_contents('php://input');
if (!is_string($body)) {
    $body = '';
}
if (strlen($body) > 5 * 1024 * 1024) {
    ccBridgeReply(413, ['ok' => false, 'error' => 'Запрос превышает 5 МБ.']);
}

try {
    ccBridgeVerify($body);
    $pdo = ccDb();
    ccEnsureSchema($pdo);
    $action = trim((string)($_GET['action'] ?? ''));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($action === 'status' && $method === 'GET') {
        $pending = (int)$pdo->query(
            "SELECT COUNT(*) FROM `cc_card_refund_queue` WHERE `queue_status` = 'pending'"
        )->fetchColumn();
        ccBridgeReply(200, ['ok' => true, 'version' => CC_APP_VERSION, 'pending_refunds' => $pending]);
    }
    if ($action === 'sync_users' && $method === 'POST') {
        ccBridgeReply(200, ['ok' => true] + ccBridgeSyncUsers($pdo, ccBridgeJsonBody($body)));
    }
    if ($action === 'refunds' && $method === 'GET') {
        ccBridgeReply(200, ['ok' => true] + ccBridgeRefunds($pdo));
    }
    if ($action === 'ack_refunds' && $method === 'POST') {
        ccBridgeReply(200, ['ok' => true] + ccBridgeAcknowledgeRefunds($pdo, ccBridgeJsonBody($body)));
    }
    if ($action === 'lookup_organizations' && $method === 'POST') {
        ccBridgeReply(200, ['ok' => true] + ccBridgeLookupOrganizations($pdo, ccBridgeJsonBody($body)));
    }
    if ($action === 'sync_shops' && $method === 'POST') {
        ccBridgeReply(200, ['ok' => true] + ccBridgeSyncShops($pdo, ccBridgeJsonBody($body)));
    }
    ccBridgeReply(405, ['ok' => false, 'error' => 'Неизвестное действие или метод.']);
} catch (JsonException | InvalidArgumentException $e) {
    ccBridgeReply(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    error_log('CC bridge auth/config error: ' . $e->getMessage());
    ccBridgeReply(401, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('CC bridge error: ' . $e->getMessage());
    ccBridgeReply(500, ['ok' => false, 'error' => 'Внутренняя ошибка КЦ.']);
}
