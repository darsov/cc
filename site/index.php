<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ccRequireAuth();
ccApplyHtmlHeaders();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Контакт-центр</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing:border-box; }
        body {
            min-height:100vh;
            margin:0;
            padding:1rem;
            display:grid;
            place-items:center;
            background:#f8fafc;
        }
        .action {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:3.25rem;
            padding:.9rem 1.35rem;
            border:1px solid #bfdbfe;
            border-radius:.85rem;
            color:#fff;
            background:#2563eb;
            box-shadow:0 12px 30px rgba(37,99,235,.2);
            text-decoration:none;
            font-weight:700;
        }
        .action:hover { background:#1d4ed8; }
        .action:focus-visible { outline:3px solid #93c5fd; outline-offset:3px; }
    </style>
</head>
<body>
    <a class="action" href="card_refund.php">💳 Возврат подарочного сертификата</a>
</body>
</html>
