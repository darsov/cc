<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$currentUser = ccRequireAuth();
ccApplyHtmlHeaders();
$isHttps = ccIsHttps();
$phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$serverTime = (new DateTimeImmutable())->format('d.m.Y H:i:s T');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>КЦ · OMNI</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 1rem;
            display: grid;
            place-items: center;
            overflow-x: hidden;
            overflow-y: auto;
            color: #f8fafc;
            background:
                radial-gradient(circle at 50% 35%, rgba(249, 115, 22, .2), transparent 28rem),
                linear-gradient(145deg, #09090b, #111827 60%, #09090b);
        }

        main {
            width: min(92vw, 38rem);
            padding: 3.5rem 2rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 1.75rem;
            background: rgba(17, 24, 39, .72);
            box-shadow: 0 2rem 7rem rgba(0, 0, 0, .45);
            backdrop-filter: blur(18px);
        }

        .fire {
            display: inline-block;
            font-size: clamp(5rem, 20vw, 9rem);
            line-height: 1;
            filter: drop-shadow(0 1rem 2rem rgba(249, 115, 22, .4));
            animation: pulse 1.8s ease-in-out infinite;
        }

        h1 {
            margin: 1.5rem 0 .55rem;
            font-size: clamp(2rem, 7vw, 3.4rem);
            letter-spacing: -.04em;
        }

        .lead {
            margin: 0;
            color: #cbd5e1;
            font-size: 1.05rem;
        }

        .action {
            display: inline-block;
            margin-top: 1.5rem;
            padding: .8rem 1.1rem;
            border-radius: .75rem;
            color: #fff;
            background: #2563eb;
            text-decoration: none;
            font-weight: 700;
        }

        .action:hover {
            background: #1d4ed8;
        }

        .user { margin-top:1rem; color:#94a3b8; font-size:.9rem; }
        .logout { margin-top:1rem; }
        .logout button { border:0; background:none; color:#93c5fd; cursor:pointer; font:inherit; }

        .status {
            margin: 2rem auto 0;
            padding-top: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: .55rem;
            border-top: 1px solid rgba(255, 255, 255, .08);
            color: #94a3b8;
            font-size: .82rem;
        }

        .badge {
            padding: .45rem .7rem;
            border: 1px solid rgba(255, 255, 255, .09);
            border-radius: 999px;
            background: rgba(255, 255, 255, .04);
        }

        .ok {
            color: #86efac;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1) rotate(-1deg);
            }

            50% {
                transform: scale(1.06) rotate(1deg);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .fire {
                animation: none;
            }
        }

        @media (max-height: 36rem) {
            main {
                padding-block: 1.5rem;
            }

            .fire {
                font-size: 4rem;
            }

            h1 {
                margin-top: .75rem;
            }

            .status {
                margin-top: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="fire" aria-label="Огонь">🔥</div>
        <h1>КЦ запущен</h1>
        <p class="lead">Внешний кабинет Контакт-центра OMNI.</p>
        <div class="user"><?= ccEscape((string)$currentUser['email']) ?> · <?= ccEscape((string)$currentUser['department_name']) ?></div>
        <a class="action" href="card_refund.php">↩ Возврат подарочного сертификата</a>
        <form class="logout" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= ccEscape(ccCsrfToken()) ?>"><button type="submit">Выйти</button></form>

        <div class="status" aria-label="Состояние сервера">
            <span class="badge"><span class="ok">●</span> PHP <?= ccEscape($phpVersion) ?></span>
            <span class="badge"><span class="ok">●</span> <?= $isHttps ? 'HTTPS' : 'HTTP' ?></span>
            <span class="badge"><?= ccEscape($serverTime) ?></span>
        </div>
    </main>
</body>
</html>
