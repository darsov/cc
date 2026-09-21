<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'site/card_refund.php',
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
$library = file_get_contents($root . '/site/card_refund_lib.php');
$bridge = file_get_contents($root . '/site/bridge_api.php');
$bootstrap = file_get_contents($root . '/site/bootstrap.php');
$migration = file_get_contents($root . '/database/migrate_cc_portal.sql');

$checks = [
    [str_contains($page, 'enctype="multipart/form-data"'), 'Upload form is missing.'],
    [str_contains($page, 'ccRequireAuth()'), 'Refund authentication is missing.'],
    [str_contains($library, 'ZipArchive'), 'XLSX reader is missing.'],
    [str_contains($library, 'ccRefundQueue'), 'Local refund queue is missing.'],
    [str_contains($library, "'aes-256-gcm'"), 'Refund queue encryption is missing.'],
    [str_contains($library, 'CC_REFUND_MAX_ROWS'), 'Row limit is missing.'],
    [str_contains($bridge, 'ccBridgeVerify'), 'Bridge authentication is missing.'],
    [str_contains($bridge, "hash_hmac("), 'HMAC verification is missing.'],
    [str_contains($bridge, 'ccBridgeSyncUsers'), 'User sync endpoint is missing.'],
    [str_contains($bridge, 'ccBridgeAcknowledgeRefunds'), 'Refund acknowledgment is missing.'],
    [str_contains($bootstrap, 'password_verify'), 'Local login verification is missing.'],
    [str_contains($bootstrap, '$_SERVER'), 'Apache SetEnv fallback is missing.'],
    [str_contains($migration, 'cc_users'), 'CC users table migration is missing.'],
    [str_contains($migration, 'cc_card_refund_queue'), 'CC refund queue migration is missing.'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

echo "OK\n";
