<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'site/card_refund.php',
    'site/card_refund.js',
    'site/card_refund_lib.php',
    'site/bootstrap.php',
    'site/bridge_api.php',
    'site/login.php',
    'site/logout.php',
    'database/migrate_cc_portal.sql',
    'deploy/database.php.example',
    'deploy/bridge.php.example',
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Missing file: ' . $file);
    }
}

$page = file_get_contents($root . '/site/card_refund.php');
$index = file_get_contents($root . '/site/index.php');
$login = file_get_contents($root . '/site/login.php');
$refundScript = file_get_contents($root . '/site/card_refund.js');
$library = file_get_contents($root . '/site/card_refund_lib.php');
$bridge = file_get_contents($root . '/site/bridge_api.php');
$bootstrap = file_get_contents($root . '/site/bootstrap.php');
$migration = file_get_contents($root . '/database/migrate_cc_portal.sql');

$checks = [
    [str_contains($page, 'enctype="multipart/form-data"'), 'Upload form is missing.'],
    [str_contains($page, 'ccRequireAuth()'), 'Refund authentication is missing.'],
    [str_contains($page, 'Отправить в CLZ'), 'CLZ upload button label is missing.'],
    [str_contains($page, 'id="refund-submit" type="submit" disabled'), 'Upload button is not disabled initially.'],
    [str_contains($page, 'Уже загружено:'), 'Duplicate upload label is unclear.'],
    [!str_contains($page, '— обязательная'), 'Required/optional hint is still visible.'],
    [str_contains($refundScript, 'fileInput.files.length === 0'), 'Upload button file-state logic is missing.'],
    [str_contains($page, 'Результат загрузки'), 'Per-row result table is missing.'],
    [!str_contains($page, 'Они попадут в защищённую очередь'), 'Internal queue text is still visible.'],
    [!str_contains($page, 'Сам XLSX не сохраняется'), 'Technical XLSX note is still visible.'],
    [str_contains($index, "Location: card_refund.php"), 'Main CC redirect is missing.'],
    [str_contains($login, "Location: card_refund.php"), 'Login redirect to refund upload is missing.'],
    [!str_contains($index, 'КЦ запущен'), 'Legacy CC splash is still visible.'],
    [str_contains($login, 'color-scheme:light'), 'Login page is not light.'],
    [str_contains($library, 'ZipArchive'), 'XLSX reader is missing.'],
    [str_contains($library, 'ccRefundQueue'), 'Local refund queue is missing.'],
    [str_contains($library, "'aes-256-gcm'"), 'Refund queue encryption is missing.'],
    [str_contains($library, 'CC_REFUND_MAX_ROWS'), 'Row limit is missing.'],
    [str_contains($library, "(?:\\d{10}|\\d{20})"), 'Gift card length validation is missing.'],
    [str_contains($library, 'Загружено с замечаниями'), 'Partial row acceptance is missing.'],
    [str_contains($library, "(int)(\$rowNode['r']"), 'Exact XLSX row numbers are missing.'],
    [str_contains($bridge, 'ccBridgeVerify'), 'Bridge authentication is missing.'],
    [str_contains($bridge, "hash_hmac("), 'HMAC verification is missing.'],
    [str_contains($bridge, 'ccBridgeSyncUsers'), 'User sync endpoint is missing.'],
    [str_contains($bridge, 'ccBridgeAcknowledgeRefunds'), 'Refund acknowledgment is missing.'],
    [str_contains($bootstrap, 'password_verify'), 'Local login verification is missing.'],
    [str_contains($bootstrap, '$_SERVER'), 'Apache SetEnv fallback is missing.'],
    [str_contains($bootstrap, "'REDIRECT_'"), 'Redirected SetEnv fallback is missing.'],
    [str_contains($bootstrap, 'apache_getenv'), 'Apache environment fallback is missing.'],
    [str_contains($bootstrap, "__DIR__ . '/.bridge.php'"), 'Local bridge config fallback is missing.'],
    [str_contains($bootstrap, "__DIR__ . '/.database.php'"), 'Local database config fallback is missing.'],
    [str_contains($migration, 'cc_users'), 'CC users table migration is missing.'],
    [str_contains($migration, 'cc_card_refund_queue'), 'CC refund queue migration is missing.'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

require_once $root . '/site/card_refund_lib.php';

foreach (['1234567890', '12345678901234567890'] as $cardNumber) {
    [$normalizedCard, $cardIssue] = ccRefundCardNumberValue($cardNumber);
    if ($normalizedCard !== $cardNumber || $cardIssue !== null) {
        throw new RuntimeException('A valid gift card number was rejected.');
    }
}

[, $invalidCardIssue] = ccRefundCardNumberValue('12345678901');
if ($invalidCardIssue === null) {
    throw new RuntimeException('An invalid gift card length was accepted.');
}

[$dateValue, $dateIssue] = ccRefundOptionalDateValue('30.08.2026', false);
if ($dateValue !== '2026-08-30' || $dateIssue !== null) {
    throw new RuntimeException('The required contact date format was rejected.');
}

[, $wrongDateIssue] = ccRefundOptionalDateValue('2026-08-30', false);
if ($wrongDateIssue === null) {
    throw new RuntimeException('A wrong contact date format was accepted.');
}

[$validName, $validNameIssue] = ccRefundOptionalFullNameValue('Иванов Иван Иванович');
if ($validName === '' || $validNameIssue !== null) {
    throw new RuntimeException('A Cyrillic full name was rejected.');
}

[$invalidName, $invalidNameIssue] = ccRefundOptionalFullNameValue('John Smith');
if ($invalidName !== '' || $invalidNameIssue === null) {
    throw new RuntimeException('A non-Cyrillic full name was accepted.');
}

echo "OK\n";
