<?php
declare(strict_types=1);

$baseConfig = [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => (int)(getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: '',
    'db_user' => getenv('DB_USER') ?: '',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'max_upload_bytes' => 10 * 1024 * 1024,
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

function database_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function ensure_guest_photo_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS guest_photo_uploads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_name VARCHAR(160) NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            drive_file_id VARCHAR(160) NULL,
            drive_url VARCHAR(700) NULL,
            storage_path VARCHAR(500) NULL,
            public_url VARCHAR(700) NULL,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            upload_status ENUM('uploaded','failed') NOT NULL,
            error_message VARCHAR(1000) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_guest_photo_uploads_status (upload_status),
            KEY ix_guest_photo_uploads_created_at (created_at),
            KEY ix_guest_photo_uploads_gallery (upload_status, is_visible, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'storage_path' => 'VARCHAR(500) NULL AFTER drive_url',
        'public_url' => 'VARCHAR(700) NULL AFTER storage_path',
        'is_visible' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER public_url',
    ];

    foreach ($columns as $column => $definition) {
        if (!database_column_exists($pdo, 'guest_photo_uploads', $column)) {
            $pdo->exec("ALTER TABLE guest_photo_uploads ADD COLUMN {$column} {$definition}");
        }
    }
}

function guest_photo_storage_directory(): string {
    $uploadsRoot = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploadsRoot) && !mkdir($uploadsRoot, 0755, true) && !is_dir($uploadsRoot)) {
        throw new RuntimeException('Could not create the uploads directory.');
    }

    $uploadsRootReal = realpath($uploadsRoot);
    if ($uploadsRootReal === false) {
        throw new RuntimeException('Could not resolve the uploads directory.');
    }

    $guestDirectory = $uploadsRootReal . '/guest-photos';
    if (!is_dir($guestDirectory) && !mkdir($guestDirectory, 0755, true) && !is_dir($guestDirectory)) {
        throw new RuntimeException('Could not create the guest photo directory.');
    }

    $guestDirectoryReal = realpath($guestDirectory);
    if ($guestDirectoryReal === false
        || !str_starts_with($guestDirectoryReal . DIRECTORY_SEPARATOR, $uploadsRootReal . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Guest photo directory is outside the uploads root.');
    }

    return $guestDirectoryReal;
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
