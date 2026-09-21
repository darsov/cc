<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $forwardedProto === 'https';
$phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$serverTime = (new DateTimeImmutable())->format('d.m.Y H:i:s T');

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
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
        <p class="lead">Веб-интерфейс OMNI готов к разработке.</p>
        <a class="action" href="card_refund.php">↩ Возврат подарочного сертификата</a>

        <div class="status" aria-label="Состояние сервера">
            <span class="badge"><span class="ok">●</span> PHP <?= escape($phpVersion) ?></span>
            <span class="badge"><span class="ok">●</span> <?= $isHttps ? 'HTTPS' : 'HTTP' ?></span>
            <span class="badge"><?= escape($serverTime) ?></span>
        </div>
    </main>
</body>
</html>
