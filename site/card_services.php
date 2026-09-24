<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CC_ALLOWED_REFUND_ORGANIZATION_ID = '75fabd9c-421e-11f0-a6e2-a7bd3aad63e9';

function ccEnsureCardToolsSchema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT `id`,`name` FROM `cc_organizations` LIMIT 0');
        $pdo->query('SELECT `datareon_shop_id`,`organization_id` FROM `cc_shops` LIMIT 0');
        $pdo->query('SELECT `id`,`user_id`,`action`,`card_number`,`outcome` FROM `cc_user_actions` LIMIT 0');
    } catch (Throwable $e) {
        throw new RuntimeException('Таблицы карт КЦ не готовы. Выполните database/migrate_cc_card_tools.sql.', 0, $e);
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

/**
 * This is a read-only synchronous query. A gateway or backend failure must
 * never be interpreted as a missing card or an allowed refund.
 */
function ccFindGiftCardOrganization(string $cardNumber): ?string
{
    $cardNumber = ccCardNumber($cardNumber);
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На сервере КЦ недоступен PHP cURL.');
    }
    $url = trim((string)(ccEnvironmentValue('CC_DATAREON_GIFTCARDS_URL')
        ?? 'https://ru-dtrap-p13.sys.clz.ru:14104/giftcardsFind'));
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException('Адрес Datareon должен использовать HTTPS.');
    }
    $body = json_encode([
        'dummyField' => 0,
        'organizationId' => '',
        'giftcartNumber' => $cardNumber,
    ], JSON_THROW_ON_ERROR);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new RuntimeException('Datareon недоступен: ' . $error);
        }
        if (in_array($status, [502, 503, 504], true) && $attempt === 0) {
            usleep(300000);
            continue;
        }
        if ($status !== 200) {
            throw new RuntimeException('Datareon вернул HTTP ' . $status . '. Проверка организации не выполнена.');
        }
        $result = json_decode($response, true);
        if (!is_array($result) || (string)($result['giftcartNumber'] ?? '') !== $cardNumber) {
            throw new RuntimeException('Datareon вернул некорректный ответ для карты.');
        }
        $organizationId = trim((string)($result['organizationId'] ?? ''));
        if ($organizationId === '') return null;
        if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $organizationId)) {
            throw new RuntimeException('Datareon вернул некорректный идентификатор организации.');
        }
        return strtolower($organizationId);
    }
    throw new RuntimeException('Не удалось проверить организацию карты.');
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
            try { $results[$number] = ['organization_id' => ccFindGiftCardOrganization($number)]; }
            catch (Throwable $e) { $results[$number] = ['error' => $e->getMessage()]; }
        }
        return $results;
    }
    $url = trim((string)(ccEnvironmentValue('CC_DATAREON_GIFTCARDS_URL')
        ?? 'https://ru-dtrap-p13.sys.clz.ru:14104/giftcardsFind'));
    if (!str_starts_with($url, 'https://')) throw new RuntimeException('Адрес Datareon должен использовать HTTPS.');
    for ($attempt = 0; $attempt < 2 && $remaining; $attempt++) {
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
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
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
                if ($code !== CURLM_OK || $response === false || $status === 0) {
                    $results[$number] = ['error' => 'Datareon недоступен: ' . ($error ?: 'ошибка соединения')];
                } elseif (in_array($status, [502, 503, 504], true) && $attempt === 0) {
                    $retry[$number] = true;
                } elseif ($status !== 200) {
                    $results[$number] = ['error' => 'Datareon вернул HTTP ' . $status . '.'];
                } else {
                    $data = json_decode($response, true);
                    $id = is_array($data) ? trim((string)($data['organizationId'] ?? '')) : '';
                    if (!is_array($data) || (string)($data['giftcartNumber'] ?? '') !== $number
                        || ($id !== '' && !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id))) {
                        $results[$number] = ['error' => 'Datareon вернул некорректный ответ.'];
                    } else {
                        $results[$number] = ['organization_id' => $id === '' ? null : strtolower($id)];
                    }
                }
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
            }
            curl_multi_close($multi);
        }
        $remaining = $retry;
        if ($remaining) usleep(300000);
    }
    return $results;
}

function ccCheckMindboxGiftCard(string $cardNumber): array
{
    $cardNumber = ccCardNumber($cardNumber);
    $baseUrl = rtrim(trim((string)(ccEnvironmentValue('CC_OMNI_URL') ?? 'https://omni.clz.ru')), '/');
    if (!str_starts_with($baseUrl, 'https://')) {
        throw new RuntimeException('Адрес OMNI должен использовать HTTPS.');
    }
    $path = '/cc_gift_card_api.php';
    $body = json_encode(['card_number' => $cardNumber], JSON_THROW_ON_ERROR);
    $timestamp = (string)time();
    $signature = hash_hmac('sha256', $timestamp . "\nPOST\n" . $path . "\n" . $body, ccBridgeSecret());
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Omni-Timestamp: ' . $timestamp,
            'X-Omni-Signature: sha256=' . $signature,
        ],
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('Mindbox через OMNI недоступен: ' . $error);
    $data = json_decode($response, true);
    if ($status !== 200 || !is_array($data) || empty($data['ok'])) {
        throw new RuntimeException('Проверка Mindbox: ' . (string)($data['error'] ?? 'HTTP ' . $status));
    }
    return $data;
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
