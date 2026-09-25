<?php

declare(strict_types=1);

const CC_REFUND_MAX_FILE_BYTES = 10 * 1024 * 1024;
const CC_REFUND_MAX_UNPACKED_BYTES = 50 * 1024 * 1024;
const CC_REFUND_MAX_ROWS = 5000;

function ccRefundCsrfToken(): string
{
    if (!isset($_SESSION['card_refund_csrf']) || !is_string($_SESSION['card_refund_csrf'])) {
        $_SESSION['card_refund_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['card_refund_csrf'];
}

function ccRefundVerifyCsrf(?string $token): bool
{
    return is_string($token) && hash_equals(ccRefundCsrfToken(), $token);
}

function ccRefundNormalizeHeader(string $value): string
{
    $value = mb_strtolower(trim(str_replace("\u{00A0}", ' ', $value)), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/[^a-zа-я0-9]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function ccRefundColumnIndex(string $reference): int
{
    if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
        return 0;
    }
    $index = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return max(0, $index - 1);
}

function ccRefundXml(string $xml, string $label): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    try {
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (!$parsed) {
            throw new RuntimeException('Не удалось прочитать XML: ' . $label . '.');
        }
        return $parsed;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function ccRefundSharedStrings(ZipArchive $zip): array
{
    $raw = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($raw)) {
        return [];
    }
    $xml = ccRefundXml($raw, 'sharedStrings.xml');
    $items = $xml->xpath('//*[local-name()="si"]') ?: [];
    $result = [];
    foreach ($items as $item) {
        $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
        $text = '';
        foreach ($parts as $part) {
            $text .= (string)$part;
        }
        $result[] = $text;
    }
    return $result;
}

function ccRefundCellData(SimpleXMLElement $cell, array $sharedStrings): array
{
    $type = (string)($cell['t'] ?? '');
    if ($type === 'inlineStr') {
        $parts = $cell->xpath('.//*[local-name()="is"]//*[local-name()="t"]') ?: [];
        $text = '';
        foreach ($parts as $part) {
            $text .= (string)$part;
        }
        return ['value' => trim($text), 'numeric' => false];
    }

    $nodes = $cell->xpath('./*[local-name()="v"]') ?: [];
    $value = isset($nodes[0]) ? (string)$nodes[0] : '';
    if ($type === 's') {
        return ['value' => trim((string)($sharedStrings[(int)$value] ?? '')), 'numeric' => false];
    }
    if ($type === 'b') {
        return ['value' => $value === '1' ? '1' : '0', 'numeric' => false];
    }
    return ['value' => trim($value), 'numeric' => $type === '' || $type === 'n'];
}

function ccRefundReadWorksheet(ZipArchive $zip): array
{
    $raw = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!is_string($raw)) {
        throw new RuntimeException('В XLSX не найден первый лист.');
    }
    $sharedStrings = ccRefundSharedStrings($zip);
    $xml = ccRefundXml($raw, 'sheet1.xml');
    $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
    $rows = [];
    foreach ($rowNodes as $rowNode) {
        $row = [];
        $cells = $rowNode->xpath('./*[local-name()="c"]') ?: [];
        foreach ($cells as $cell) {
            $index = ccRefundColumnIndex((string)($cell['r'] ?? ''));
            $row[$index] = ccRefundCellData($cell, $sharedStrings);
        }
        if ($row) {
            ksort($row);
            $rows[] = [
                'number' => max(1, (int)($rowNode['r'] ?? (count($rows) + 1))),
                'cells' => $row,
            ];
        }
    }
    return $rows;
}

function ccRefundNormalizeExcelInteger(string $value): string
{
    $value = trim(str_replace("\u{00A0}", ' ', $value));
    if (preg_match('/^\d+\.0+$/', $value)) {
        return strstr($value, '.', true) ?: $value;
    }
    return $value;
}

function ccRefundCardNumberValue(string $value): array
{
    $value = ccRefundNormalizeExcelInteger($value);
    if ($value === '') {
        return ['', 'Не указан номер подарочной карты.'];
    }
    if (!preg_match('/^(?:\d{10}|\d{20})$/', $value)) {
        return [$value, 'Номер подарочной карты должен содержать 10 или 20 цифр.'];
    }
    return [$value, null];
}

function ccRefundOptionalDateValue(string $value, bool $numeric): array
{
    $value = trim($value);
    if ($value === '') {
        return ['', null];
    }

    if ($numeric) {
        if (!is_numeric($value) || (float)$value <= 0 || (float)$value > 100000) {
            return ['', 'Неверный формат даты обращения (ожидается ДД.ММ.ГГГГ).'];
        }
        $seconds = (int)round(((float)$value - 25569) * 86400);
        return [
            (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
            null,
        ];
    }

    $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value);
    if ($date === false || $date->format('d.m.Y') !== $value) {
        return ['', 'Неверный формат даты обращения (ожидается ДД.ММ.ГГГГ).'];
    }
    return [$date->format('Y-m-d'), null];
}

function ccRefundOptionalDigitsValue(string $value, int $length, string $errorMessage): array
{
    $value = ccRefundNormalizeExcelInteger($value);
    if ($value === '') {
        return ['', null];
    }
    if (!preg_match('/^\d{' . $length . '}$/', $value)) {
        return ['', $errorMessage];
    }
    return [$value, null];
}

function ccRefundOptionalFullNameValue(string $value): array
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($value === '') {
        return ['', null];
    }
    if (mb_strlen($value, 'UTF-8') > 255
        || !preg_match('/^\p{Cyrillic}+(?:[\s-]+\p{Cyrillic}+)*$/u', $value)) {
        return ['', 'ФИО не на кириллице.'];
    }
    return [$value, null];
}

function ccRefundOptionalPhoneValue(string $value): array
{
    $value = ccRefundNormalizeExcelInteger($value);
    if ($value === '') {
        return ['', null];
    }
    if (!preg_match('/^7\d{10}$/D', $value)) {
        return ['', 'Неверный формат телефона (ожидается 7XXXXXXXXXX).'];
    }
    return [$value, null];
}

function ccRefundOptionalEmailValue(string $value): array
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    if ($value === '') {
        return ['', null];
    }
    if (mb_strlen($value, 'UTF-8') > 320
        || !filter_var($value, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/uD', $value)) {
        return ['', 'Неверный формат электронной почты (ожидается xxx@xxx.xx).'];
    }
    return [$value, null];
}

function ccRefundContactIssue(string $phone, string $email): ?string
{
    return $phone === '' && $email === '' ? 'Укажите телефон или email.' : null;
}

function ccRefundIsBlockOnly(array $record): bool
{
    return trim((string)($record['customer_full_name'] ?? '')) === ''
        || trim((string)($record['bank_bic'] ?? '')) === ''
        || trim((string)($record['bank_account'] ?? '')) === '';
}

function ccRefundReadXlsx(string $path, int $fileSize): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('На сервере не подключено расширение PHP ZipArchive.');
    }
    if (!class_exists(SimpleXMLElement::class)) {
        throw new RuntimeException('На сервере не подключено расширение PHP SimpleXML.');
    }
    if ($fileSize <= 0 || $fileSize > CC_REFUND_MAX_FILE_BYTES) {
        throw new RuntimeException('Размер XLSX должен быть от 1 байта до 10 МБ.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($path);
    if ($opened !== true) {
        throw new RuntimeException('Файл не является корректным XLSX.');
    }
    try {
        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $unpacked += (int)($stat['size'] ?? 0);
            if ($unpacked > CC_REFUND_MAX_UNPACKED_BYTES) {
                throw new RuntimeException('Распакованный XLSX превышает безопасный лимит 50 МБ.');
            }
        }
        $rows = ccRefundReadWorksheet($zip);
    } finally {
        $zip->close();
    }

    if (count($rows) < 2) {
        throw new RuntimeException('В XLSX должна быть строка заголовков и хотя бы одно обращение.');
    }

    $aliases = [
        'card_number' => ['номер подарочной карты', 'номер карты'],
        'client_contact_date' => ['дата обращения клиента', 'дата обращения'],
        'request_number' => ['номер обращения'],
        'customer_phone' => ['телефон', 'телефон в формате 7xxxxxxxxxx'],
        'customer_email' => ['email', 'e mail', 'электронная почта', 'электронная почта только xxx xxx xx'],
        'customer_full_name' => ['фио', 'ф и о', 'фио только русские буквы'],
        'bank_bic' => ['бик', 'бик 10 цифр'],
        'bank_account' => [
            'рс', 'р с', 'рс 20 цифр',
            'расчетный счет', 'рассчетный счет', 'расчетный счёт', 'рассчетный счёт',
        ],
    ];
    $normalizedAliases = [];
    foreach ($aliases as $field => $values) {
        foreach ($values as $value) {
            $normalizedAliases[$field][] = ccRefundNormalizeHeader($value);
        }
    }

    $header = array_shift($rows);
    $headerRow = is_array($header['cells'] ?? null) ? $header['cells'] : [];
    // Anything after the first bank account column is a free-form note, not request data.
    $lastColumn = 7;
    foreach ($headerRow as $index => $cell) {
        if (in_array(ccRefundNormalizeHeader((string)($cell['value'] ?? '')),
            $normalizedAliases['bank_account'], true)) {
            $lastColumn = (int)$index;
            break;
        }
    }
    $columns = [];
    foreach ($headerRow as $index => $header) {
        if ((int)$index > $lastColumn) continue;
        $normalized = ccRefundNormalizeHeader((string)($header['value'] ?? ''));
        foreach ($normalizedAliases as $field => $fieldAliases) {
            if (!isset($columns[$field]) && in_array($normalized, $fieldAliases, true)) {
                $columns[$field] = (int)$index;
            }
        }
    }
    if (!array_key_exists('card_number', $columns)) {
        throw new RuntimeException('В XLSX не найдена обязательная колонка: Номер подарочной карты.');
    }

    $records = [];
    foreach ($rows as $rowData) {
        $rowNumber = max(2, (int)($rowData['number'] ?? 0));
        $row = is_array($rowData['cells'] ?? null) ? $rowData['cells'] : [];
        $values = [];
        $numericCells = [];
        foreach (array_keys($aliases) as $field) {
            $columnIndex = $columns[$field] ?? null;
            $cell = $columnIndex !== null
                ? ($row[$columnIndex] ?? ['value' => '', 'numeric' => false])
                : ['value' => '', 'numeric' => false];
            $values[$field] = trim((string)($cell['value'] ?? ''));
            $numericCells[$field] = (bool)($cell['numeric'] ?? false);
        }
        if (implode('', $values) === '') {
            continue;
        }
        if (count($records) >= CC_REFUND_MAX_ROWS) {
            throw new RuntimeException('В одном XLSX допускается не более 5000 обращений.');
        }

        [$cardNumber, $cardIssue] = ccRefundCardNumberValue($values['card_number']);
        [$clientContactDate, $dateIssue] = ccRefundOptionalDateValue(
            $values['client_contact_date'],
            $numericCells['client_contact_date']
        );
        [$customerFullName, $fullNameIssue] = ccRefundOptionalFullNameValue($values['customer_full_name']);
        [$customerPhone, $phoneIssue] = ccRefundOptionalPhoneValue($values['customer_phone']);
        [$customerEmail, $emailIssue] = ccRefundOptionalEmailValue($values['customer_email']);
        $contactIssue = ccRefundContactIssue($customerPhone, $customerEmail);
        [$bankBic, $bicIssue] = ccRefundOptionalDigitsValue(
            $values['bank_bic'],
            10,
            'Неверный формат БИК (ожидается 10 цифр).'
        );
        [$bankAccount, $accountIssue] = ccRefundOptionalDigitsValue(
            $values['bank_account'],
            20,
            'Неверный формат расчётного счёта (ожидается 20 цифр).'
        );

        $requestNumber = trim($values['request_number']);
        $requestIssue = null;
        if (mb_strlen($requestNumber, 'UTF-8') > 100) {
            $requestNumber = '';
            $requestIssue = 'Номер обращения превышает 100 символов.';
        }

        $issues = array_values(array_filter([
            $cardIssue,
            $dateIssue,
            $requestIssue,
            $phoneIssue,
            $emailIssue,
            $contactIssue,
            $fullNameIssue,
            $bicIssue,
            $accountIssue,
        ], static fn($issue): bool => is_string($issue) && $issue !== ''));

        $records[] = [
            '_row_number' => $rowNumber,
            '_issues' => $issues,
            '_rejected' => $cardIssue !== null || $contactIssue !== null,
            'card_number' => $cardNumber,
            'client_contact_date' => $clientContactDate,
            'request_number' => $requestNumber,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_full_name' => $customerFullName,
            'bank_bic' => $bankBic,
            'bank_account' => $bankAccount,
        ];
    }

    if (!$records) {
        throw new RuntimeException('В XLSX не найдено ни одного заполненного обращения.');
    }
    return $records;
}

function ccRefundSourceKey(string $requestNumber, string $cardNumber): string
{
    return hash(
        'sha256',
        mb_strtolower(trim($requestNumber), 'UTF-8') . "\n" . preg_replace('/[\s\p{Z}]+/u', '', trim($cardNumber))
    );
}

function ccRefundManualRecord(array $form): array
{
    $values = [];
    foreach (['card_number', 'client_contact_date', 'request_number', 'customer_phone',
              'customer_email', 'customer_full_name', 'bank_bic', 'bank_account'] as $field) {
        $values[$field] = trim((string)($form[$field] ?? ''));
    }
    [$card, $cardIssue] = ccRefundCardNumberValue($values['card_number']);
    [$date, $dateIssue] = ccRefundOptionalDateValue($values['client_contact_date'], false);
    [$phone, $phoneIssue] = ccRefundOptionalPhoneValue($values['customer_phone']);
    [$email, $emailIssue] = ccRefundOptionalEmailValue($values['customer_email']);
    $contactIssue = ccRefundContactIssue($phone, $email);
    [$name, $nameIssue] = ccRefundOptionalFullNameValue($values['customer_full_name']);
    [$bic, $bicIssue] = ccRefundOptionalDigitsValue($values['bank_bic'], 10, 'Неверный формат БИК (ожидается 10 цифр).');
    [$account, $accountIssue] = ccRefundOptionalDigitsValue($values['bank_account'], 20, 'Неверный формат расчётного счёта (ожидается 20 цифр).');
    $issues = array_values(array_filter([$cardIssue, $dateIssue, $phoneIssue, $emailIssue, $contactIssue,
        $nameIssue, $bicIssue, $accountIssue]));
    if (mb_strlen($values['request_number'], 'UTF-8') > 100) $issues[] = 'Номер обращения превышает 100 символов.';
    if ($issues) throw new InvalidArgumentException(implode(' ', $issues));
    return [
        '_row_number' => 1, '_issues' => [], '_rejected' => false,
        'card_number' => $card, 'client_contact_date' => $date,
        'request_number' => $values['request_number'], 'customer_phone' => $phone,
        'customer_email' => $email, 'customer_full_name' => $name,
        'bank_bic' => $bic, 'bank_account' => $account,
    ];
}

function ccRefundPayloadJson(array $record): string
{
    return json_encode([
        'card_number' => (string)$record['card_number'],
        'organization_id' => (string)($record['organization_id'] ?? ''),
        'client_contact_date' => (string)$record['client_contact_date'],
        'request_number' => (string)$record['request_number'],
        'customer_phone' => (string)$record['customer_phone'],
        'customer_email' => (string)$record['customer_email'],
        'customer_full_name' => (string)$record['customer_full_name'],
        'bank_bic' => (string)$record['bank_bic'],
        'bank_account' => (string)$record['bank_account'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ccRefundEncryptionKey(): string
{
    return hash('sha256', 'cc-refund-queue-v1|' . ccQueueEncryptionSecret(), true);
}

function ccRefundEncryptPayload(string $plainText): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('На сервере КЦ не подключено расширение OpenSSL.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        ccRefundEncryptionKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($encrypted === false) {
        throw new RuntimeException('Не удалось зашифровать обращение перед сохранением в очередь.');
    }
    return 'v1:' . base64_encode($iv . $tag . $encrypted);
}

function ccRefundDecryptPayload(string $encryptedValue): array
{
    if (!str_starts_with($encryptedValue, 'v1:')) {
        throw new RuntimeException('Неизвестный формат зашифрованного обращения КЦ.');
    }
    $payload = base64_decode(substr($encryptedValue, 3), true);
    if ($payload === false || strlen($payload) < 29) {
        throw new RuntimeException('Зашифрованное обращение КЦ повреждено.');
    }
    $plainText = openssl_decrypt(
        substr($payload, 28),
        'aes-256-gcm',
        ccRefundEncryptionKey(),
        OPENSSL_RAW_DATA,
        substr($payload, 0, 12),
        substr($payload, 12, 16)
    );
    if (!is_string($plainText)) {
        throw new RuntimeException('Не удалось расшифровать обращение КЦ. Проверьте общий секрет.');
    }
    $record = json_decode($plainText, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($record)) {
        throw new RuntimeException('Расшифрованное обращение КЦ имеет неверный формат.');
    }
    return $record;
}

function ccRefundQueue(PDO $pdo, array $records, int $submittedByUserId): array
{
    ccEnsureSchema($pdo);
    $select = $pdo->prepare(
        'SELECT `id`, `payload_hash`, `queue_status`
         FROM `cc_card_refund_queue`
         WHERE `source_key` = :source_key
         LIMIT 1
         FOR UPDATE'
    );
    $insert = $pdo->prepare(
        'INSERT INTO `cc_card_refund_queue`
            (`source_key`, `payload_hash`, `payload_encrypted`, `queue_status`, `submitted_by_user_id`, `submitted_at`)
         VALUES
            (:source_key, :payload_hash, :payload_encrypted, \'pending\', :submitted_by_user_id, NOW())'
    );
    $update = $pdo->prepare(
        'UPDATE `cc_card_refund_queue`
         SET `payload_hash` = :payload_hash,
             `payload_encrypted` = :payload_encrypted,
             `queue_status` = \'pending\',
             `submitted_by_user_id` = :submitted_by_user_id,
             `submitted_at` = NOW(),
             `exported_at` = NULL
         WHERE `id` = :id'
    );

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $rejected = 0;
    $warnings = 0;
    $resultRows = [];
    $acceptedRecords = [];

    require_once __DIR__ . '/card_services.php';
    $cardsToCheck = [];
    foreach ($records as $record) {
        if (empty($record['_rejected']) && !empty($record['card_number'])) {
            $cardsToCheck[(string)$record['card_number']] = true;
        }
    }
    $organizations = [];
    foreach (array_chunk(array_keys($cardsToCheck), 50) as $cardBatch) {
        $organizations += ccFindGiftCardOrganizationsBatch($cardBatch);
    }
    if ($organizations) ccStoreCardOrganizations($pdo, $organizations);

    foreach ($records as $record) {
        $rowNumber = (int)($record['_row_number'] ?? 0);
        $issues = is_array($record['_issues'] ?? null) ? $record['_issues'] : [];
        if (empty($record['_rejected'])) {
            try {
                $organization = $organizations[(string)$record['card_number']] ?? [];
                if (isset($organization['error']) || !array_key_exists('organization_id', $organization)) {
                    throw new RuntimeException((string)($organization['error'] ?? 'Ответ Datareon отсутствует.'));
                }
                $organizationId = $organization['organization_id'];
                if ($organizationId === null) {
                    $issues[] = 'Карта не найдена в Datareon.';
                    $record['_rejected'] = true;
                } elseif ($organizationId !== CC_ALLOWED_REFUND_ORGANIZATION_ID) {
                    $issues[] = 'Карта выпущена другой организацией.';
                    $record['_rejected'] = true;
                } else {
                    $record['organization_id'] = $organizationId;
                }
            } catch (Throwable $e) {
                $issues[] = 'Проверка организации не выполнена: ' . $e->getMessage();
                $record['_rejected'] = true;
            }
        }
        if (!empty($record['_rejected'])) {
            $rejected++;
            $resultRows[$rowNumber] = [
                'row_number' => $rowNumber,
                'card_number' => (string)($record['card_number'] ?? ''),
                'result' => 'error',
                'label' => 'Ошибка',
                'details' => array_map(
                    static fn(string $issue): string => "Строка {$rowNumber} — {$issue}",
                    $issues
                ),
            ];
            continue;
        }
        if ($issues) {
            $warnings++;
        }
        $acceptedRecords[] = $record;
    }

    if ($acceptedRecords) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($acceptedRecords as $record) {
            $rowNumber = (int)$record['_row_number'];
            $issues = $record['_issues'];
            $sourceKey = ccRefundSourceKey(
                (string)$record['request_number'],
                (string)$record['card_number']
            );
            $payloadJson = ccRefundPayloadJson($record);
            $payloadHash = hash('sha256', $payloadJson);
            $select->execute(['source_key' => $sourceKey]);
            $existing = $select->fetch();
            if (!$existing) {
                $insert->execute([
                    'source_key' => $sourceKey,
                    'payload_hash' => $payloadHash,
                    'payload_encrypted' => ccRefundEncryptPayload($payloadJson),
                    'submitted_by_user_id' => $submittedByUserId,
                ]);
                $created++;
                $operation = 'Добавлено в CLZ.';
            } elseif ((string)$existing['queue_status'] === 'pending'
                && hash_equals((string)$existing['payload_hash'], $payloadHash)) {
                $skipped++;
                $operation = 'Уже загружено ранее — изменений нет.';
            } else {
                $update->execute([
                    'payload_hash' => $payloadHash,
                    'payload_encrypted' => ccRefundEncryptPayload($payloadJson),
                    'submitted_by_user_id' => $submittedByUserId,
                    'id' => (int)$existing['id'],
                ]);
                $updated++;
                $operation = 'Обновлено в CLZ.';
            }

            $details = ["Строка {$rowNumber} — {$operation}"];
            $blockOnly = ccRefundIsBlockOnly($record);
            if ($blockOnly) {
                $details[] = "Строка {$rowNumber} — только блокировка: нет полного набора ФИО, БИК и РС.";
            }
            foreach ($issues as $issue) {
                $details[] = "Строка {$rowNumber} — {$issue}";
            }
            $resultRows[$rowNumber] = [
                'row_number' => $rowNumber,
                'card_number' => (string)$record['card_number'],
                'result' => $issues ? 'warning' : 'success',
                'label' => $issues ? 'Загружено с замечаниями' : ($blockOnly ? 'Только блокировка' : 'Успех'),
                'details' => $details,
            ];
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ksort($resultRows);

    return [
        'received' => count($records),
        'queued' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'rejected' => $rejected,
        'warnings' => $warnings,
        'rows' => array_values($resultRows),
    ];
}
