<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ccStartSession();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ccVerifyCsrf($_POST['csrf_token'] ?? null)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}
header('Location: login.php');
exit;
