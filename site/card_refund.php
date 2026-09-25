<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/card_refund_lib.php';
require_once __DIR__ . '/card_services.php';

header('X-CC-Refund-Revision: 20260925-queue-without-lookup');
$currentUser = ccRequireAuth();
$scriptNonce = base64_encode(random_bytes(16));
ccApplyHtmlHeaders();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; "
    . "script-src 'self' 'nonce-" . $scriptNonce . "'; base-uri 'none'; "
    . "form-action 'self'; frame-ancestors 'none'");

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
        ccEnsureCardToolsSchema(ccDb());
        $manual = (string)($_POST['submission_type'] ?? '') === 'manual';
        if ($manual) {
            $records = [ccRefundManualRecord($_POST)];
        } else {
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
        }
        $result = ccRefundQueue(ccDb(), $records, (int)$currentUser['id']);
        foreach ($result['rows'] as $row) {
            ccLogAction(ccDb(), (int)$currentUser['id'], $manual ? 'refund_manual' : 'refund_upload',
                (string)($row['card_number'] ?? ''),
                implode('; ', (array)($row['details'] ?? [])) ?: (string)($row['label'] ?? ''));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        try {
            ccLogAction(ccDb(), (int)$currentUser['id'],
                (string)($_POST['submission_type'] ?? '') === 'manual' ? 'refund_manual' : 'refund_upload',
                isset($_POST['card_number']) ? (string)$_POST['card_number'] : null, 'error');
        } catch (Throwable $loggingError) { error_log('CC action log failed: ' . $loggingError->getMessage()); }
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
            color-scheme:light;
            font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }
        * { box-sizing:border-box; }
        body { min-height:100vh; margin:0; padding:2rem 1rem; color:#0f172a; background:#f8fafc; }
        main { width:min(100%,72rem); margin:0 auto; }
        .cc-menu { margin-bottom:1rem; display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
        .cc-menu a { color:#2563eb; text-decoration:none; }
        .cc-menu form { display:inline; }
        .cc-menu button { padding:0; background:none; color:#2563eb; font-weight:400; }
        .panel {
            padding:2rem;
            border:1px solid #e2e8f0;
            border-radius:1.25rem;
            background:#fff;
            box-shadow:0 18px 45px rgba(15,23,42,.08);
        }
        .results { margin-bottom:1rem; }
        h1 { margin:0 0 .65rem; font-size:clamp(1.8rem,5vw,2.7rem); letter-spacing:-.03em; }
        h2 { margin:0 0 1rem; font-size:1.25rem; }
        .columns {
            margin:1rem 0 1.5rem;
            padding:1rem 1.2rem;
            border:1px solid #e2e8f0;
            border-radius:.8rem;
            background:#f8fafc;
            color:#475569;
            line-height:1.8;
        }
        code { color:#1d4ed8; }
        .upload-form { display:grid; gap:1rem; }
        input[type=file] {
            width:100%;
            padding:1rem;
            border:1px dashed #94a3b8;
            border-radius:.8rem;
            background:#fff;
            color:#334155;
        }
        .manual-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:1rem; }
        .manual-grid label { display:grid; gap:.4rem; color:#475569; }
        .manual-grid input { padding:.7rem; border:1px solid #94a3b8; border-radius:.6rem; width:100%; }
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
        button:disabled { background:#94a3b8; cursor:not-allowed; opacity:.72; }
        .message { margin-bottom:1rem; padding:1rem 1.2rem; border-radius:.8rem; line-height:1.5; }
        .message.error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .stats { display:flex; flex-wrap:wrap; gap:.6rem; margin-bottom:1rem; }
        .stat { padding:.4rem .7rem; border-radius:999px; background:#f1f5f9; color:#475569; font-size:.88rem; }
        .table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:.8rem; }
        table { width:100%; min-width:48rem; border-collapse:collapse; }
        th,td { padding:.75rem .85rem; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; font-size:.9rem; }
        th { background:#f8fafc; color:#475569; white-space:nowrap; }
        tbody tr:last-child td { border-bottom:0; }
        td code { color:#0f172a; white-space:nowrap; }
        .status { display:inline-block; padding:.3rem .55rem; border-radius:999px; font-size:.8rem; font-weight:700; white-space:nowrap; }
        .status.success { color:#166534; background:#dcfce7; }
        .status.warning { color:#92400e; background:#fef3c7; }
        .status.error { color:#991b1b; background:#fee2e2; }
        .details { margin:0; padding-left:1.2rem; color:#475569; }
        .details li + li { margin-top:.3rem; }
        @media (max-width:640px) {
            body { padding:1rem .7rem; }
            .panel { padding:1.25rem; }
        }
    </style>
</head>
<body>
<main>
    <?php include __DIR__ . '/menu.php'; ?>

    <?php if ($error !== null): ?>
        <div class="message error"><?= ccRefundEscape($error) ?></div>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <section class="panel results">
            <h2>Результат загрузки</h2>
            <div class="stats">
                <span class="stat">Обработано: <?= (int)($result['received'] ?? 0) ?></span>
                <span class="stat">Добавлено: <?= (int)($result['queued'] ?? 0) ?></span>
                <span class="stat">Обновлено: <?= (int)($result['updated'] ?? 0) ?></span>
                <span class="stat">Уже загружено: <?= (int)($result['skipped'] ?? 0) ?></span>
                <span class="stat">С замечаниями: <?= (int)($result['warnings'] ?? 0) ?></span>
                <span class="stat">Отклонено: <?= (int)($result['rejected'] ?? 0) ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Строка XLSX</th>
                        <th>Номер карты</th>
                        <th>Результат</th>
                        <th>Подробности</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <?php
                        $resultClass = in_array(($row['result'] ?? ''), ['success', 'warning', 'error'], true)
                            ? (string)$row['result']
                            : 'error';
                        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
                        ?>
                        <tr>
                            <td><?= (int)($row['row_number'] ?? 0) ?></td>
                            <td><code><?= ccRefundEscape((string)($row['card_number'] ?? '')) ?: '—' ?></code></td>
                            <td><span class="status <?= ccRefundEscape($resultClass) ?>"><?= ccRefundEscape((string)($row['label'] ?? '')) ?></span></td>
                            <td>
                                <ul class="details">
                                    <?php foreach ($details as $detail): ?>
                                        <li><?= ccRefundEscape((string)$detail) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h1>Возврат подарочных сертификатов</h1>

        <div class="columns">
            Порядок колонок:<br>
            <code>Номер карты</code>,
            <code>Дата обращения</code>,
            <code>Номер обращения</code>,
            <code>Телефон в формате 7XXXXXXXXXX</code>,
            <code>Электронная почта только xxx@xxx.xx</code>,
            <code>ФИО только русские буквы</code>,
            <code>БИК 9 цифр</code>,
            <code>РС 20 цифр</code>.
        </div>
        <p>Укажите телефон или email. Если ФИО либо банковские реквизиты не заполнены, заявка будет отмечена как «Только блокировка». Колонки после РС игнорируются.</p>

        <form class="upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= ccRefundEscape(ccRefundCsrfToken()) ?>">
            <input type="hidden" name="submission_type" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= CC_REFUND_MAX_FILE_BYTES ?>">
            <input id="refund-xlsx" type="file" name="refund_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <button id="refund-submit" type="submit" disabled>Отправить в CLZ</button>
        </form>
        <h2 style="margin-top:2rem">Ввести одно обращение вручную</h2>
        <form id="refund-manual-form" class="upload-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= ccRefundEscape(ccRefundCsrfToken()) ?>">
            <input type="hidden" name="submission_type" value="manual">
            <div class="manual-grid">
                <label>Номер карты<input name="card_number" inputmode="numeric" pattern="[0-9]{10}|[0-9]{20}" required maxlength="20"></label>
                <label>Дата обращения<input name="client_contact_date" placeholder="ДД.ММ.ГГГГ"></label>
                <label>Номер обращения<input name="request_number" maxlength="100"></label>
                <label>Телефон<input name="customer_phone" placeholder="7XXXXXXXXXX" inputmode="tel"></label>
                <label>Email<input name="customer_email" type="text" inputmode="email"></label>
                <label>ФИО<input name="customer_full_name"></label>
                <label>БИК<input name="bank_bic" inputmode="numeric"></label>
                <label>Расчётный счёт<input name="bank_account" inputmode="numeric"></label>
            </div>
            <button id="refund-manual-submit" type="submit" disabled>Отправить в CLZ</button>
        </form>
    </section>
</main>
<script nonce="<?= ccRefundEscape($scriptNonce) ?>">
(() => {
    'use strict';

    const fileInput = document.getElementById('refund-xlsx');
    const uploadButton = document.getElementById('refund-submit');
    const manualForm = document.getElementById('refund-manual-form');
    const manualButton = document.getElementById('refund-manual-submit');
    if (!fileInput || !uploadButton || !manualForm || !manualButton) return;

    const card = manualForm.elements.namedItem('card_number');
    const phone = manualForm.elements.namedItem('customer_phone');
    const email = manualForm.elements.namedItem('customer_email');
    const refresh = () => {
        uploadButton.disabled = !(fileInput.files && fileInput.files.length > 0);
        manualButton.disabled = !card.value.trim()
            || !(phone.value.trim() || email.value.trim())
            || !manualForm.checkValidity();
    };

    fileInput.addEventListener('change', refresh);
    manualForm.addEventListener('input', refresh);
    manualForm.addEventListener('change', refresh);
    window.addEventListener('pageshow', refresh);
    refresh();
})();
</script>
</body>
</html>
