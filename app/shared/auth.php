<?php

declare(strict_types=1);

/*
 * BCP Authentication
 * Central session and access protection.
 */

function authLoginUrl(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    if (str_starts_with($scriptName, '/BCP_SCHEDULING/')) {
        return '/BCP_SCHEDULING/index.php';
    }

    return '/index.php';
}
const SESSION_IDLE_LIMIT = 1800;    // 30 minutes
const SESSION_MAX_LIFETIME = 28800; // 8 hours

function authDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 3306);
    $database = getenv('DB_NAME') ?: 'bcp_scheduling';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    return $pdo;
}

function authStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_trans_sid', '0');

    session_name('BCP_AUTH');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

function authNoCache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function authCsrf(): string
{
    authStart();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function authCsrfValid($token): bool
{
    authStart();

    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function authClear(): void
{
    authStart();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite']
        ]);
    }

    session_destroy();
}

function authLoggedIn(): bool
{
    authStart();

    if (empty($_SESSION['auth_user_id'])) {
        return false;
    }

    $now = time();

    $lastActivity = (int) (
        $_SESSION['auth_last_activity'] ?? 0
    );

    $loginTime = (int) (
        $_SESSION['auth_login_time'] ?? 0
    );

    if (
        $lastActivity <= 0 ||
        $loginTime <= 0 ||
        ($now - $lastActivity) > SESSION_IDLE_LIMIT ||
        ($now - $loginTime) > SESSION_MAX_LIFETIME
    ) {
        authClear();

        return false;
    }

    return true;
}

function authRequire(
    bool $api = false,
    array $allowedRoles = ['ADMIN', 'SCHEDULER']
): void {
    authNoCache();

    if (!authLoggedIn()) {
        if ($api) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'status' => 'AUTH_REQUIRED',
                'message' => 'Please log in again.'
            ]);

            exit;
        }

        header('Location: ' . authLoginUrl(), true, 303);
        exit;
    }

    // Check whether the account is still active.
    $stmt = authDb()->prepare(
        'SELECT role, is_active
         FROM auth_users
         WHERE user_id = :id
         LIMIT 1'
    );

    $stmt->execute([
        'id' => (int) $_SESSION['auth_user_id']
    ]);

    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        authClear();

        if ($api) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'status' => 'SESSION_REVOKED'
            ]);

            exit;
        }

        header('Location: ' . authLoginUrl(), true, 303);
        exit;
    }

    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }

    $_SESSION['auth_role'] = $user['role'];
    $_SESSION['auth_last_activity'] = time();

    // Periodic session ID regeneration.
    if (
        time() - (int) (
            $_SESSION['auth_regenerated_at'] ?? 0
        ) > 900
    ) {
        session_regenerate_id(true);

        $_SESSION['auth_regenerated_at'] = time();
    }
}
