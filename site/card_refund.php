<?php

declare(strict_types=1);

require_once __DIR__ . '/card_refund_lib.php';

$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

function ccRefundEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = null;
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!ccRefundVerifyCsrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Сессия устарела. Обновите страницу и попробуйте снова.');
        }
        if (!isset($_FILES['refund_xlsx']) || !is_array($_FILES['refund_xlsx'])) {
            throw new RuntimeException('Выберите XLSX-файл.');
        }
        $file = $_FILES['refund_xlsx'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'Файл превышает лимит сервера.',
                UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит формы.',
                UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью.',
                UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
            ];
            throw new RuntimeException($messages[$uploadError] ?? 'Ошибка загрузки файла.');
        }
        $name = trim((string)($file['name'] ?? ''));
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Допускаются только файлы .xlsx.');
        }
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Не удалось подтвердить загруженный файл.');
        }

        $records = ccRefundReadXlsx($tmpName, (int)($file['size'] ?? 0));
        $result = ccRefundSendToOmni($records);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Возврат подарочных сертификатов · КЦ</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing:border-box; }
        body {
            min-height:100vh;
            margin:0;
            padding:2rem 1rem;
            color:#f8fafc;
            background:
                radial-gradient(circle at 20% 10%, rgba(37,99,235,.2), transparent 30rem),
                linear-gradient(145deg,#09090b,#111827 60%,#09090b);
        }
        main { width:min(100%,56rem); margin:0 auto; }
        nav { margin-bottom:1rem; }
        nav a { color:#93c5fd; text-decoration:none; }
        .panel {
            padding:2rem;
            border:1px solid rgba(255,255,255,.1);
            border-radius:1.25rem;
            background:rgba(17,24,39,.82);
            box-shadow:0 2rem 7rem rgba(0,0,0,.35);
        }
        h1 { margin:0 0 .65rem; font-size:clamp(1.8rem,5vw,2.7rem); letter-spacing:-.03em; }
        .lead { margin:0 0 1.5rem; color:#cbd5e1; line-height:1.55; }
        .columns {
            margin:1rem 0 1.5rem;
            padding:1rem 1.2rem;
            border-radius:.8rem;
            background:rgba(255,255,255,.04);
            color:#cbd5e1;
            line-height:1.7;
        }
        code { color:#fde68a; }
        form { display:grid; gap:1rem; }
        input[type=file] {
            width:100%;
            padding:1rem;
            border:1px dashed #64748b;
            border-radius:.8rem;
            background:#0f172a;
            color:#e2e8f0;
        }
        button {
            justify-self:start;
            border:0;
            border-radius:.75rem;
            padding:.8rem 1.2rem;
            background:#2563eb;
            color:#fff;
            cursor:pointer;
            font-weight:700;
        }
        button:hover { background:#1d4ed8; }
        .message { margin-bottom:1rem; padding:1rem 1.2rem; border-radius:.8rem; line-height:1.5; }
        .error { background:rgba(220,38,38,.18); border:1px solid rgba(248,113,113,.35); color:#fecaca; }
        .success { background:rgba(22,163,74,.18); border:1px solid rgba(74,222,128,.35); color:#bbf7d0; }
        .stats { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:.7rem; }
        .stat { padding:.4rem .65rem; border-radius:999px; background:rgba(255,255,255,.08); font-size:.88rem; }
        .note { margin-top:1.2rem; color:#94a3b8; font-size:.88rem; line-height:1.5; }
        @media (max-width:640px) { .panel { padding:1.25rem; } }
    </style>
</head>
<body>
<main>
    <nav><a href="index.php">← Главная КЦ</a></nav>

    <?php if ($error !== null): ?>
        <div class="message error"><?= ccRefundEscape($error) ?></div>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <div class="message success">
            <strong>Файл передан в OMNI.</strong>
            <div class="stats">
                <span class="stat">Получено: <?= (int)($result['received'] ?? 0) ?></span>
                <span class="stat">Создано: <?= (int)($result['created'] ?? 0) ?></span>
                <span class="stat">Обновлено: <?= (int)($result['updated'] ?? 0) ?></span>
                <span class="stat">Без изменений: <?= (int)($result['skipped'] ?? 0) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h1>Возврат подарочных сертификатов</h1>
        <p class="lead">Загрузите обращения клиентов в XLSX. Файл проверяется и сразу передаётся в раздел «Возвраты → Подарочные сертификаты» системы OMNI.</p>

        <div class="columns">
            Первая строка должна содержать колонки:<br>
            <code>Номер подарочной карты</code>,
            <code>Дата обращения клиента</code>,
            <code>Номер обращения</code>,
            <code>ФИО</code>,
            <code>БИК</code>,
            <code>Расчётный счёт</code>.
        </div>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= ccRefundEscape(ccRefundCsrfToken()) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= CC_REFUND_MAX_FILE_BYTES ?>">
            <input type="file" name="refund_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <button type="submit">Загрузить в OMNI</button>
        </form>

        <p class="note">
            БИК и расчётный счёт должны быть сохранены в Excel как текст. Файл после обработки на сервере КЦ не сохраняется.
            Повторная загрузка того же обращения и той же карты не создаёт дубль.
        </p>
    </section>
</main>
</body>
</html>
