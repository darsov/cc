<?php

declare(strict_types=1);

const CC_APP_VERSION = '2026-09-23.01';

function ccEnvironmentValue(string $name): ?string
{
    foreach ([$name, 'REDIRECT_' . $name] as $candidate) {
        $value = getenv($candidate);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }

        if (function_exists('apache_getenv')) {
            $value = apache_getenv($candidate, true);
            if ($value !== false && $value !== '') {
                return (string)$value;
            }
        }

        foreach ([$_SERVER, $_ENV] as $source) {
            if (array_key_exists($candidate, $source) && is_scalar($source[$candidate])) {
                $value = (string)$source[$candidate];
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return null;
}

function ccLoadLocalConfig(array $paths): array
{
    foreach ($paths as $path) {
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            continue;
        }

        $loaded = require $path;
        if (is_array($loaded)) {
            return $loaded;
        }
    }

    return [];
}

function ccDbConfig(): array
{
    $config = ccLoadLocalConfig([
        '/etc/omniweb/database.php',
        __DIR__ . '/.database.php',
    ]);
    $value = static function (string $envName, string $key, $default) use ($config) {
        $env = ccEnvironmentValue($envName);
        return $env !== null ? $env : ($config[$key] ?? $default);
    };
    $result = [
        'host' => (string)$value('CC_DB_HOST', 'host', '127.0.0.1'),
        'port' => (int)$value('CC_DB_PORT', 'port', 3306),
        'dbname' => (string)$value('CC_DB_NAME', 'dbname', 'omniweb'),
        'user' => (string)$value('CC_DB_USER', 'user', 'omniweb'),
        'pass' => (string)$value('CC_DB_PASS', 'pass', ''),
    ];
    if ($result['host'] === '' || $result['dbname'] === '' || $result['user'] === '') {
        throw new RuntimeException('База КЦ не настроена.');
    }
    if ($result['port'] < 1 || $result['port'] > 65535) {
        throw new RuntimeException('В конфигурации КЦ указан некорректный порт БД.');
    }
    return $result;
}

function ccDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = ccDbConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['dbname']
    );
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    return $pdo;
}

function ccEnsureSchema(PDO $pdo): void
{
    try {
        $pdo->query(
            'SELECT `id`, `omni_user_id`, `email`, `password_hash`, `department_name`, `is_active`,
                    `failed_login_attempts`, `locked_until`, `synced_at`, `last_login_at`, `created_at`, `updated_at`
             FROM `cc_users` LIMIT 0'
        );
        $pdo->query(
            'SELECT `id`, `source_key`, `payload_hash`, `payload_encrypted`, `queue_status`,
                    `submitted_by_user_id`, `submitted_at`, `exported_at`, `updated_at`
             FROM `cc_card_refund_queue` LIMIT 0'
        );
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Таблицы КЦ не созданы. Администратору нужно выполнить database/migrate_cc_portal.sql. Причина MySQL: '
            . $e->getMessage(),
            0,
            $e
        );
    }
}

function ccIsHttps(): bool
{
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
}

function ccStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'secure' => ccIsHttps(),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

function ccApplyHtmlHeaders(?string $scriptNonce = null): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    $scripts = "'self'";
    if ($scriptNonce !== null) {
        $scripts .= " 'nonce-" . $scriptNonce . "'";
    }
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src "
        . $scripts . "; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

function ccEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ccCsrfToken(): string
{
    ccStartSession();
    if (!isset($_SESSION['cc_csrf']) || !is_string($_SESSION['cc_csrf'])) {
        $_SESSION['cc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cc_csrf'];
}

function ccVerifyCsrf(?string $token): bool
{
    return is_string($token) && hash_equals(ccCsrfToken(), $token);
}

function ccCurrentUser(): ?array
{
    ccStartSession();
    $userId = (int)($_SESSION['cc_user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }
    $pdo = ccDb();
    ccEnsureSchema($pdo);
    $stmt = $pdo->prepare(
        'SELECT `id`, `omni_user_id`, `email`, `department_name`, `is_active`
         FROM `cc_users`
         WHERE `id` = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['is_active'] !== 1) {
        unset($_SESSION['cc_user_id']);
        session_regenerate_id(true);
        return null;
    }
    return $user;
}

function ccRequireAuth(): array
{
    $user = ccCurrentUser();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function ccAttemptLogin(string $email, string $password): bool
{
    $pdo = ccDb();
    ccEnsureSchema($pdo);
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare(
        'SELECT `id`, `password_hash`, `is_active`, `failed_login_attempts`, `locked_until`
         FROM `cc_users`
         WHERE `email` = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    $locked = $user && !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time();
    $valid = $user
        && (int)$user['is_active'] === 1
        && !$locked
        && password_verify($password, (string)$user['password_hash']);

    if (!$valid) {
        if ($user && !$locked) {
            $pdo->prepare(
                'UPDATE `cc_users`
                 SET `failed_login_attempts` = `failed_login_attempts` + 1,
                     `locked_until` = CASE
                         WHEN `failed_login_attempts` + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                         ELSE NULL
                     END
                 WHERE `id` = :id'
            )->execute(['id' => (int)$user['id']]);
        }
        usleep(300000);
        return false;
    }

    $pdo->prepare(
        'UPDATE `cc_users`
         SET `failed_login_attempts` = 0, `locked_until` = NULL, `last_login_at` = NOW()
         WHERE `id` = :id'
    )->execute(['id' => (int)$user['id']]);
    ccStartSession();
    session_regenerate_id(true);
    $_SESSION['cc_user_id'] = (int)$user['id'];
    return true;
}

function ccBridgeConfig(): array
{
    static $result = null;
    if (is_array($result)) {
        return $result;
    }
    $config = ccLoadLocalConfig([
        '/etc/omniweb/bridge.php',
        __DIR__ . '/.bridge.php',
    ]);
    $secret = trim((string)(ccEnvironmentValue('CC_OMNI_BRIDGE_SECRET') ?? ($config['secret'] ?? '')));
    $queueEncryptionKey = trim((string)(
        ccEnvironmentValue('CC_REFUND_QUEUE_ENCRYPTION_KEY') ?? ($config['queue_encryption_key'] ?? '')
    ));
    if (strlen($secret) < 32) {
        throw new RuntimeException('Общий секрет OMNI → КЦ не настроен.');
    }
    if (strlen($queueEncryptionKey) < 32) {
        throw new RuntimeException('Ключ шифрования очереди возвратов КЦ не настроен.');
    }
    $result = ['secret' => $secret, 'queue_encryption_key' => $queueEncryptionKey];
    return $result;
}

function ccBridgeSecret(): string
{
    return (string)ccBridgeConfig()['secret'];
}

function ccQueueEncryptionSecret(): string
{
    return (string)ccBridgeConfig()['queue_encryption_key'];
}
