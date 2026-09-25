<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CC_ALLOWED_REFUND_ORGANIZATION_ID = '75fabd9c-421e-11f0-a6e2-a7bd3aad63e9';

function ccEnsureCardToolsSchema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT `id`,`name` FROM `cc_organizations` LIMIT 0');
        $pdo->query('SELECT `datareon_shop_id`,`organization_id` FROM `cc_shops` LIMIT 0');
        $pdo->query('SELECT `card_number`,`organization_id`,`shop_id`,`checked_at` FROM `cc_card_organizations` LIMIT 0');
        $pdo->query('SELECT `id`,`user_id`,`action`,`card_number`,`outcome` FROM `cc_user_actions` LIMIT 0');
    } catch (Throwable $e) {
        throw new RuntimeException('Таблицы карт КЦ не готовы. Запустите на CC php deploy/migrate-card-tools.php. Причина: ' . $e->getMessage(), 0, $e);
    }
}

function ccCardNumber(string $value): string
{
    $value = preg_replace('/\s+/u', '', trim($value)) ?? trim($value);
    if (!preg_match('/^(?:\d{10}|\d{20})$/D', $value)) {
        throw new InvalidArgumentException('Номер подарочной карты должен содержать 10 или 20 цифр.');
    }
    return $value;
}

function ccCardOrganization(PDO $pdo, string $organizationId): string
{
    $stmt = $pdo->prepare('SELECT `name` FROM `cc_organizations` WHERE `id` = :id LIMIT 1');
    $stmt->execute(['id' => $organizationId]);
    return trim((string)($stmt->fetchColumn() ?: ''));
}

/** Keep successful Datareon responses (including an empty organization) in CC. */
function ccStoreCardOrganizations(PDO $pdo, array $results): void
{
    $stmt = $pdo->prepare('INSERT INTO `cc_card_organizations`
        (`card_number`,`organization_id`,`shop_id`,`checked_at`)
        VALUES (:card_number,:organization_id,:shop_id,NOW())
        ON DUPLICATE KEY UPDATE `organization_id`=VALUES(`organization_id`),
            `shop_id`=VALUES(`shop_id`),`checked_at`=NOW()');
    foreach ($results as $number => $result) {
        if (!is_array($result) || !array_key_exists('organization_id', $result)) continue;
        $stmt->execute([
            'card_number' => ccCardNumber((string)$number),
            'organization_id' => $result['organization_id'],
            'shop_id' => $result['shop_id'] ?? null,
        ]);
    }
}

/** Parse both fields from one response; a malformed ID must not be cached. */
function ccParseGiftCardLookup(array $result, string $cardNumber): array
{
    if (($result['giftcartNumber'] ?? null) !== $cardNumber) {
        throw new RuntimeException('Datareon вернул некорректный ответ для карты.');
    }
    $organizationId = $result['organizationId'] ?? null;
    if ($organizationId !== null && !is_string($organizationId)) {
        throw new RuntimeException('Datareon вернул некорректный идентификатор организации.');
    }
    $organizationId = trim((string)$organizationId);
    if ($organizationId !== '' && !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $organizationId)) {
        throw new RuntimeException('Datareon вернул некорректный идентификатор организации.');
    }
    $shopId = $result['shopId'] ?? null;
    if ($shopId !== null && !is_string($shopId)) {
        throw new RuntimeException('Datareon вернул некорректный shopId.');
    }
    $shopId = trim((string)$shopId);
    if (strlen($shopId) > 100 || preg_match('/[\x00-\x1f\x7f]/', $shopId)) {
        throw new RuntimeException('Datareon вернул некорректный shopId.');
    }
    return [
        'organization_id' => $organizationId === '' ? null : strtolower($organizationId),
        'shop_id' => $shopId === '' ? null : $shopId,
    ];
}

/** Both p13 and p12 redirect to p11; probe p13 first, then p11 only once. */
function ccGiftcardsFindUrls(): array
{
    return array_map(static fn(string $host): string =>
        'https://ru-dtrap-' . $host . '.sys.clz.ru:14104/giftcardsFind', ['p13', 'p11']);
}

/** A gateway or backend failure must never be interpreted as a missing card. */
function ccFindGiftCardDetails(string $cardNumber): array
{
    $cardNumber = ccCardNumber($cardNumber);
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На сервере КЦ недоступен PHP cURL.');
    }
    $body = json_encode([
        'dummyField' => 0,
        'organizationId' => '',
        'giftcartNumber' => $cardNumber,
    ], JSON_THROW_ON_ERROR);
    $failures = [];
    foreach (ccGiftcardsFindUrls() as $url) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            // p11 can return its gateway error after roughly 10 seconds.
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            $failures[] = parse_url($url, PHP_URL_HOST) . ': ' . ($error ?: 'ошибка соединения');
            continue;
        }
        if ($status !== 200) {
            $failures[] = parse_url($url, PHP_URL_HOST) . ': HTTP ' . $status;
            continue;
        }
        $result = json_decode($response, true);
        if (!is_array($result)) {
            throw new RuntimeException('Datareon вернул некорректный ответ для карты.');
        }
        return ccParseGiftCardLookup($result, $cardNumber);
    }
    throw new RuntimeException('Проверка организации не выполнена: ' . implode('; ', $failures));
}

/** Batch lookup for OMNI's refund table. Parallel requests keep page loading bounded. */
function ccFindGiftCardOrganizationsBatch(array $cardNumbers): array
{
    $results = [];
    $remaining = [];
    foreach ($cardNumbers as $number) {
        $number = ccCardNumber((string)$number);
        $remaining[$number] = true;
    }
    if (!function_exists('curl_multi_init')) {
        foreach (array_keys($remaining) as $number) {
            try { $results[$number] = ccFindGiftCardDetails($number); }
            catch (Throwable $e) { $results[$number] = ['error' => $e->getMessage()]; }
        }
        return $results;
    }
    $urls = ccGiftcardsFindUrls();
    for ($attempt = 0; $attempt < count($urls) && $remaining; $attempt++) {
        $url = $urls[$attempt];
        $retry = [];
        foreach (array_chunk(array_keys($remaining), 25) as $chunk) {
            $multi = curl_multi_init();
            $handles = [];
            foreach ($chunk as $number) {
                $curl = curl_init($url);
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['dummyField' => 0, 'organizationId' => '',
                        'giftcartNumber' => $number], JSON_THROW_ON_ERROR),
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                ]);
                curl_multi_add_handle($multi, $curl);
                $handles[$number] = $curl;
            }
            do {
                $code = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 1.0);
            } while ($running && $code === CURLM_OK);
            foreach ($handles as $number => $curl) {
                $response = curl_multi_getcontent($curl);
                $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_error($curl);
                if ($code !== CURLM_OK || $response === false || $status !== 200) {
                    if ($attempt < count($urls) - 1) {
                        $retry[$number] = true;
                    } else {
                        $results[$number] = ['error' => 'Проверка организации не выполнена: '
                            . parse_url($url, PHP_URL_HOST) . ': '
                            . ($status ? 'HTTP ' . $status : ($error ?: 'ошибка соединения'))];
                    }
                } else {
                    $data = json_decode($response, true);
                    try {
                        if (!is_array($data)) throw new RuntimeException('Datareon вернул некорректный ответ.');
                        $results[$number] = ccParseGiftCardLookup($data, $number);
                    } catch (Throwable $e) {
                        $results[$number] = ['error' => $e->getMessage()];
                    }
                }
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
            }
            curl_multi_close($multi);
        }
        $remaining = $retry;
    }
    return $results;
}

function ccMindboxBrandDefinitions(): array
{
    return [
        'calzedonia' => ['prefix' => 'Calz', 'label' => 'Calzedonia'],
        'intimissimi' => ['prefix' => 'Int', 'label' => 'Intimissimi'],
        'tezenis' => ['prefix' => 'Tez', 'label' => 'Tezenis'],
        'falconeri' => ['prefix' => 'Falc', 'label' => 'Falconeri'],
    ];
}

function ccEnsureMindboxSettingsSchema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT `brand`,`endpoint_id`,`secret_encrypted` FROM `cc_mindbox_settings` LIMIT 0');
    } catch (Throwable $e) {
        throw new RuntimeException('Настройки Mindbox КЦ не созданы. Выполните database/migrate_cc_mindbox_settings.sql.', 0, $e);
    }
}

function ccMindboxEncryptionKey(): string
{
    return hash('sha256', 'cc-mindbox-settings-v1|' . ccQueueEncryptionSecret(), true);
}

function ccMindboxEncryptSecret(string $secret): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', ccMindboxEncryptionKey(),
        OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) throw new RuntimeException('Не удалось сохранить настройки Mindbox КЦ.');
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function ccMindboxDecryptSecret(string $encrypted): string
{
    if (!str_starts_with($encrypted, 'v1:')) throw new RuntimeException('Неизвестный формат ключа Mindbox КЦ.');
    $payload = base64_decode(substr($encrypted, 3), true);
    if ($payload === false || strlen($payload) < 29) throw new RuntimeException('Ключ Mindbox КЦ повреждён.');
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', ccMindboxEncryptionKey(),
        OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
    if (!is_string($plain)) throw new RuntimeException('Не удалось расшифровать ключ Mindbox КЦ.');
    return $plain;
}

/** Settings arrive only through the signed OMNI -> CC bridge. */
function ccStoreMindboxSettings(PDO $pdo, array $brands): int
{
    $definitions = ccMindboxBrandDefinitions();
    if (count($brands) !== count($definitions) || array_diff_key($brands, $definitions)) {
        throw new InvalidArgumentException('Ожидаются настройки четырёх брендов Mindbox.');
    }
    $validated = [];
    foreach (array_keys($definitions) as $brand) {
        $row = $brands[$brand] ?? null;
        if (!is_array($row)) throw new InvalidArgumentException('Отсутствуют настройки Mindbox ' . $brand . '.');
        $endpoint = trim((string)($row['endpoint_id'] ?? ''));
        $secret = trim((string)($row['secret_key'] ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 255 || preg_match('/[\x00-\x1f\x7f]/', $endpoint)
            || $secret === '' || strlen($secret) > 4096 || preg_match('/[\r\n"]/', $secret)) {
            throw new InvalidArgumentException('Некорректные настройки Mindbox ' . $brand . '.');
        }
        $validated[$brand] = ['endpoint_id' => $endpoint,
            'secret_encrypted' => ccMindboxEncryptSecret($secret)];
    }
    ccEnsureMindboxSettingsSchema($pdo);
    $stmt = $pdo->prepare('INSERT INTO `cc_mindbox_settings` (`brand`,`endpoint_id`,`secret_encrypted`)
        VALUES (:brand,:endpoint_id,:secret_encrypted)
        ON DUPLICATE KEY UPDATE `endpoint_id`=VALUES(`endpoint_id`),
            `secret_encrypted`=VALUES(`secret_encrypted`),`updated_at`=NOW()');
    $pdo->beginTransaction();
    try {
        foreach ($validated as $brand => $row) {
            $stmt->execute(['brand' => $brand] + $row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return count($validated);
}

/** Return null for a missing card or a card belonging to another brand. */
function ccMindboxParseGiftCard(array $payload, string $brand): ?array
{
    $operationStatus = trim((string)($payload['status'] ?? ''));
    if (strcasecmp($operationStatus, 'Success') !== 0) {
        $message = trim((string)($payload['errorMessage'] ?? $payload['message'] ?? ''));
        throw new RuntimeException('Mindbox не выполнил запрос: ' . ($message !== '' ? $message : $operationStatus));
    }
    $card = $payload['giftCard'] ?? null;
    if (!is_array($card)) return null;
    $status = trim((string)($card['status']['ids']['systemName'] ?? $card['processingStatus'] ?? ''));
    if ($status === '' || strcasecmp(preg_replace('/[\s_-]+/', '', $status) ?? $status, 'notfound') === 0) return null;
    $pool = trim((string)($card['pool']['systemName'] ?? ''));
    $prefix = ccMindboxBrandDefinitions()[$brand]['prefix'];
    if ($pool === '' || strncasecmp($pool, $prefix, strlen($prefix)) !== 0) return null;
    if (strcasecmp($status, 'canBeUsed') !== 0) {
        throw new RuntimeException('Карта недоступна в Mindbox: ' . $status . '.');
    }
    $balance = $card['balance'] ?? null;
    if ($balance === null || !is_numeric((string)$balance)) {
        throw new RuntimeException('Mindbox не вернул баланс карты.');
    }
    return ['balance' => number_format((float)$balance, 2, '.', ''),
        'status' => $status, 'pool' => $pool, 'brand' => ccMindboxBrandDefinitions()[$brand]['label']];
}

function ccMindboxRequestBrand(string $cardNumber, string $brand, array $row): ?array
{
    $url = 'https://api.mindbox.ru/v3/operations/sync?' . http_build_query([
        'endpointId' => $row['endpoint_id'], 'operation' => 'Website.CheckGiftCard',
    ], '', '&', PHP_QUERY_RFC3986);
    $body = json_encode(['giftCard' => ['ids' => ['number' => $cardNumber]]], JSON_THROW_ON_ERROR);
    $secret = ccMindboxDecryptSecret((string)$row['secret_encrypted']);
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Не удалось инициализировать запрос Mindbox.');
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8',
            'Accept: application/json', 'Authorization: Mindbox secretKey="' . $secret . '"'],
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('Mindbox недоступен: ' . $error);
    if ($status !== 200) throw new RuntimeException('Mindbox вернул HTTP ' . $status . '.');
    $payload = json_decode((string)$response, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('Mindbox вернул некорректный ответ.');
    return ccMindboxParseGiftCard($payload, $brand);
}

function ccCheckMindboxGiftCard(string $cardNumber): array
{
    $cardNumber = ccCardNumber($cardNumber);
    if (!function_exists('curl_init')) throw new RuntimeException('На сервере КЦ недоступен PHP cURL.');
    $pdo = ccDb();
    ccEnsureMindboxSettingsSchema($pdo);
    $rows = $pdo->query('SELECT `brand`,`endpoint_id`,`secret_encrypted` FROM `cc_mindbox_settings`')
        ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $brands = array_keys(ccMindboxBrandDefinitions());
    if (strlen($cardNumber) === 10) {
        $brand = ['1' => 'calzedonia', '2' => 'intimissimi',
            '3' => 'tezenis', '4' => 'falconeri'][$cardNumber[0]] ?? null;
        if ($brand === null) throw new RuntimeException('Бренд карты не определён по номеру.');
        $brands = [$brand];
    }
    $firstError = null;
    foreach ($brands as $brand) {
        if (!isset($rows[$brand])) throw new RuntimeException('Настройки Mindbox КЦ не переданы для ' . $brand . '.');
        try {
            $result = ccMindboxRequestBrand($cardNumber, $brand, $rows[$brand]);
            if ($result !== null) return $result;
        } catch (Throwable $e) {
            $firstError ??= $e;
            if (count($brands) === 1) throw $e;
        }
    }
    if ($firstError !== null) throw new RuntimeException('Не удалось завершить проверку Mindbox: ' . $firstError->getMessage(), 0, $firstError);
    throw new RuntimeException('Карта не найдена в Mindbox.');
}

function ccLogAction(PDO $pdo, int $userId, string $action, ?string $cardNumber, string $outcome): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO `cc_user_actions` (`user_id`,`action`,`card_number`,`outcome`) '
        . 'VALUES (:user_id,:action,:card_number,:outcome)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'action' => $action,
        'card_number' => $cardNumber === null ? null : mb_substr($cardNumber, 0, 20, 'UTF-8'),
        'outcome' => mb_substr($outcome, 0, 120, 'UTF-8'),
    ]);
}
