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
            $rows[] = $row;
        }
    }
    return $rows;
}

function ccRefundDate(string $value, int $rowNumber): string
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException("Строка {$rowNumber}: не заполнена дата обращения клиента.");
    }
    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial <= 0 || $serial > 100000) {
            throw new RuntimeException("Строка {$rowNumber}: некорректная дата обращения клиента.");
        }
        $seconds = (int)round(($serial - 25569) * 86400);
        return (new DateTimeImmutable('@' . $seconds))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');
    }
    foreach (['!d.m.Y', '!Y-m-d', '!d/m/Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date !== false && $date->format(substr($format, 1)) === $value) {
            return $date->format('Y-m-d');
        }
    }
    throw new RuntimeException("Строка {$rowNumber}: дата обращения не распознана — {$value}.");
}

function ccRefundDigits(string $value, int $length, string $label, int $rowNumber): string
{
    $value = trim(str_replace("\u{00A0}", ' ', $value));
    if (preg_match('/[eE][+-]?\d+/', $value)) {
        throw new RuntimeException(
            "Строка {$rowNumber}: {$label} сохранён Excel в научном формате. "
            . 'Задайте колонке текстовый формат и вставьте исходный номер заново.'
        );
    }
    if (preg_match('/^\d+\.0+$/', $value)) {
        $value = strstr($value, '.', true) ?: $value;
    }
    $value = preg_replace('/[\s-]+/u', '', $value) ?? $value;
    if (!preg_match('/^\d{' . $length . '}$/', $value)) {
        throw new RuntimeException("Строка {$rowNumber}: {$label} должен состоять из {$length} цифр.");
    }
    return $value;
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
        'customer_full_name' => ['фио', 'ф и о'],
        'bank_bic' => ['бик'],
        'bank_account' => ['расчетный счет', 'рассчетный счет', 'расчетный счёт', 'рассчетный счёт'],
    ];
    $normalizedAliases = [];
    foreach ($aliases as $field => $values) {
        foreach ($values as $value) {
            $normalizedAliases[$field][] = ccRefundNormalizeHeader($value);
        }
    }

    $headerRow = array_shift($rows);
    $columns = [];
    foreach ($headerRow as $index => $header) {
        $normalized = ccRefundNormalizeHeader((string)($header['value'] ?? ''));
        foreach ($normalizedAliases as $field => $fieldAliases) {
            if (in_array($normalized, $fieldAliases, true)) {
                $columns[$field] = (int)$index;
            }
        }
    }
    $missing = array_diff(array_keys($aliases), array_keys($columns));
    if ($missing) {
        $labels = [
            'card_number' => 'Номер подарочной карты',
            'client_contact_date' => 'Дата обращения клиента',
            'request_number' => 'Номер обращения',
            'customer_full_name' => 'ФИО',
            'bank_bic' => 'БИК',
            'bank_account' => 'Расчётный счёт',
        ];
        throw new RuntimeException(
            'В XLSX не найдены обязательные колонки: '
            . implode(', ', array_map(static fn(string $field): string => $labels[$field], $missing))
            . '.'
        );
    }

    $records = [];
    foreach ($rows as $rowIndex => $row) {
        $rowNumber = $rowIndex + 2;
        $values = [];
        $numericCells = [];
        foreach ($columns as $field => $columnIndex) {
            $cell = $row[$columnIndex] ?? ['value' => '', 'numeric' => false];
            $values[$field] = trim((string)($cell['value'] ?? ''));
            $numericCells[$field] = (bool)($cell['numeric'] ?? false);
        }
        if (implode('', $values) === '') {
            continue;
        }
        if (count($records) >= CC_REFUND_MAX_ROWS) {
            throw new RuntimeException('В одном XLSX допускается не более 5000 обращений.');
        }

        $cardNumber = preg_replace('/\s+/u', '', $values['card_number']) ?? '';
        if ($cardNumber === '' || mb_strlen($cardNumber, 'UTF-8') > 100) {
            throw new RuntimeException("Строка {$rowNumber}: некорректный номер подарочной карты.");
        }
        if (preg_match('/[eE][+-]?\d+/', $cardNumber)) {
            throw new RuntimeException(
                "Строка {$rowNumber}: номер подарочной карты сохранён Excel в научном формате. "
                . 'Задайте колонке текстовый формат и вставьте номер заново.'
            );
        }
        if ($numericCells['card_number'] && strlen($cardNumber) > 15) {
            throw new RuntimeException(
                "Строка {$rowNumber}: длинный номер подарочной карты должен быть сохранён в Excel как текст."
            );
        }
        if ($values['request_number'] === '' || mb_strlen($values['request_number'], 'UTF-8') > 100) {
            throw new RuntimeException("Строка {$rowNumber}: номер обращения обязателен.");
        }
        if ($values['customer_full_name'] === '' || mb_strlen($values['customer_full_name'], 'UTF-8') > 255) {
            throw new RuntimeException("Строка {$rowNumber}: ФИО обязательно.");
        }
        if ($numericCells['bank_account']) {
            throw new RuntimeException(
                "Строка {$rowNumber}: расчётный счёт должен быть сохранён в Excel как текст, "
                . 'иначе Excel может округлить 20-значный номер.'
            );
        }

        $records[] = [
            'card_number' => $cardNumber,
            'client_contact_date' => ccRefundDate($values['client_contact_date'], $rowNumber),
            'request_number' => $values['request_number'],
            'customer_full_name' => $values['customer_full_name'],
            'bank_bic' => ccRefundDigits($values['bank_bic'], 9, 'БИК', $rowNumber),
            'bank_account' => ccRefundDigits($values['bank_account'], 20, 'Расчётный счёт', $rowNumber),
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

function ccRefundPayloadJson(array $record): string
{
    return json_encode([
        'card_number' => (string)$record['card_number'],
        'client_contact_date' => (string)$record['client_contact_date'],
        'request_number' => (string)$record['request_number'],
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
    $pdo->beginTransaction();
    try {
        foreach ($records as $record) {
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
                continue;
            }
            if ((string)$existing['queue_status'] === 'pending'
                && hash_equals((string)$existing['payload_hash'], $payloadHash)) {
                $skipped++;
                continue;
            }
            $update->execute([
                'payload_hash' => $payloadHash,
                'payload_encrypted' => ccRefundEncryptPayload($payloadJson),
                'submitted_by_user_id' => $submittedByUserId,
                'id' => (int)$existing['id'],
            ]);
            $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'received' => count($records),
        'queued' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
    ];
}
