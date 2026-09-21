<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ccStartSession();
if (ccCurrentUser() !== null) {
    header('Location: card_refund.php');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!ccVerifyCsrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new RuntimeException('Неверный логин или пароль.');
        }
        if (!ccAttemptLogin($email, $password)) {
            throw new RuntimeException('Неверный логин или пароль либо доступ к КЦ отключён.');
        }
        header('Location: card_refund.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ccApplyHtmlHeaders();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Вход · Контакт-центр</title>
    <style>
        :root { color-scheme:light; font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; }
        body { min-height:100vh; margin:0; padding:1rem; display:grid; place-items:center; color:#0f172a; background:#f8fafc; }
        main { width:min(92vw,28rem); padding:2rem; border:1px solid #e2e8f0; border-radius:1.25rem; background:#fff; box-shadow:0 20px 50px rgba(15,23,42,.09); }
        h1 { margin:0 0 .5rem; }
        p { color:#64748b; line-height:1.5; }
        form { display:grid; gap:1rem; margin-top:1.5rem; }
        label { display:grid; gap:.4rem; color:#334155; font-size:.9rem; }
        input { width:100%; padding:.8rem .9rem; border:1px solid #cbd5e1; border-radius:.7rem; color:#0f172a; background:#fff; font:inherit; }
        input:focus { outline:3px solid #dbeafe; border-color:#2563eb; }
        button { padding:.85rem 1rem; border:0; border-radius:.7rem; color:#fff; background:#2563eb; font-weight:700; cursor:pointer; }
        button:hover { background:#1d4ed8; }
        .error { padding:.8rem 1rem; border:1px solid #fecaca; border-radius:.7rem; color:#991b1b; background:#fef2f2; }
    </style>
</head>
<body>
<main>
    <h1>Контакт-центр</h1>
    <p>Войдите с логином и паролем, выданными администратором OMNI.</p>
    <?php if ($error !== null): ?><div class="error"><?= ccEscape($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ccEscape(ccCsrfToken()) ?>">
        <label>Email<input type="email" name="email" autocomplete="username" required autofocus></label>
        <label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit">Войти</button>
    </form>
</main>
</body>
</html>
