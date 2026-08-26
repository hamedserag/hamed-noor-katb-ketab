<?php
declare(strict_types=1);

$baseConfig = [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => (int)(getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: '',
    'db_user' => getenv('DB_USER') ?: '',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'max_upload_bytes' => 10 * 1024 * 1024,

    // Guest photos are posted to Hostinger PHP first, then relayed server-to-server
    // to the private Google Apps Script web app endpoint.
    'guest_photo_upload_url' => getenv('GUEST_PHOTO_UPLOAD_URL') ?: '',
    'guest_photo_upload_secret' => getenv('GUEST_PHOTO_UPLOAD_SECRET') ?: '',
    'max_guest_photo_bytes' => 10 * 1024 * 1024,
];

$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $local = require $localConfigFile;
    if (is_array($local)) {
        $baseConfig = array_replace($baseConfig, $local);
    }
}

$GLOBALS['site_config'] = $baseConfig;

function site_config(?string $key = null) {
    $config = $GLOBALS['site_config'];
    return $key === null ? $config : ($config[$key] ?? null);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $name = (string)site_config('db_name');
    $user = (string)site_config('db_user');
    if ($name === '' || $user === '') {
        throw new RuntimeException('Database configuration is missing. Create server/config.local.php from config.example.php.');
    }

    $host = (string)site_config('db_host');
    $port = (int)site_config('db_port');
    $password = (string)site_config('db_password');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function guest_photo_upload_configured(): bool {
    $url = trim((string)site_config('guest_photo_upload_url'));
    $secret = trim((string)site_config('guest_photo_upload_secret'));
    return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && $secret !== '';
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): void {
    start_secure_session();
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Invalid CSRF token. Please refresh the page and try again.');
    }
}

function require_admin(): void {
    start_secure_session();
    if (empty($_SESSION['admin_user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
