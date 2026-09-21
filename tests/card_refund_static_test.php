<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'site/card_refund.php',
    'site/card_refund_lib.php',
    'deploy/card_refund.php.example',
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Missing file: ' . $file);
    }
}

$page = file_get_contents($root . '/site/card_refund.php');
$library = file_get_contents($root . '/site/card_refund_lib.php');

$checks = [
    [str_contains($page, 'enctype="multipart/form-data"'), 'Upload form is missing.'],
    [str_contains($library, 'ZipArchive'), 'XLSX reader is missing.'],
    [str_contains($library, "hash_hmac('sha256'"), 'HMAC signing is missing.'],
    [str_contains($library, 'CURLOPT_SSL_VERIFYPEER'), 'TLS verification is missing.'],
    [str_contains($library, 'CC_REFUND_MAX_ROWS'), 'Row limit is missing.'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

echo "OK\n";
