<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ccStartSession();
if (ccCurrentUser() !== null) {
    header('Location: index.php');
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
        header('Location: index.php');
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
        :root { color-scheme:dark; font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; }
        body { min-height:100vh; margin:0; padding:1rem; display:grid; place-items:center; color:#f8fafc; background:radial-gradient(circle at 50% 20%,rgba(37,99,235,.22),transparent 30rem),linear-gradient(145deg,#09090b,#111827 60%,#09090b); }
        main { width:min(92vw,28rem); padding:2rem; border:1px solid rgba(255,255,255,.1); border-radius:1.25rem; background:rgba(17,24,39,.86); box-shadow:0 2rem 7rem rgba(0,0,0,.4); }
        h1 { margin:0 0 .5rem; }
        p { color:#94a3b8; line-height:1.5; }
        form { display:grid; gap:1rem; margin-top:1.5rem; }
        label { display:grid; gap:.4rem; color:#cbd5e1; font-size:.9rem; }
        input { width:100%; padding:.8rem .9rem; border:1px solid #475569; border-radius:.7rem; color:#f8fafc; background:#0f172a; font:inherit; }
        button { padding:.85rem 1rem; border:0; border-radius:.7rem; color:#fff; background:#2563eb; font-weight:700; cursor:pointer; }
        .error { padding:.8rem 1rem; border:1px solid rgba(248,113,113,.4); border-radius:.7rem; color:#fecaca; background:rgba(220,38,38,.18); }
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
