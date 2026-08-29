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
    'spam_score_threshold' => 4,
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

function database_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND INDEX_NAME = :index_name'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':index_name' => $index,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function add_database_column(PDO $pdo, string $table, string $column, string $definition): bool {
    if (database_column_exists($pdo, $table, $column)) {
        return false;
    }

    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        return true;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1060) {
            return false;
        }
        throw $e;
    }
}

function add_database_index(PDO $pdo, string $table, string $index, string $definition): void {
    if (database_index_exists($pdo, $table, $index)) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE {$table} ADD INDEX {$index} {$definition}");
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1061) {
            throw $e;
        }
    }
}

function ensure_rsvp_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rsvp_responses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_name VARCHAR(160) NOT NULL,
            attendance ENUM('yes','no') NOT NULL,
            guests TINYINT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(1500) NULL,
            user_agent VARCHAR(500) NULL,
            submission_hash CHAR(64) NULL,
            spam_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            spam_reasons VARCHAR(1000) NULL,
            is_spam TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_rsvp_created_at (created_at),
            KEY ix_rsvp_attendance (attendance),
            KEY ix_rsvp_spam (is_spam, created_at),
            KEY ix_rsvp_submission_hash (submission_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    add_database_column($pdo, 'rsvp_responses', 'submission_hash', 'CHAR(64) NULL AFTER user_agent');
    add_database_column($pdo, 'rsvp_responses', 'spam_score', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER submission_hash');
    add_database_column($pdo, 'rsvp_responses', 'spam_reasons', 'VARCHAR(1000) NULL AFTER spam_score');
    add_database_column($pdo, 'rsvp_responses', 'is_spam', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER spam_reasons');
    add_database_index($pdo, 'rsvp_responses', 'ix_rsvp_spam', '(is_spam, created_at)');
    add_database_index($pdo, 'rsvp_responses', 'ix_rsvp_submission_hash', '(submission_hash, created_at)');
}

function ensure_site_image_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_images (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_name VARCHAR(255) NOT NULL,
            storage_path VARCHAR(500) NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            caption VARCHAR(180) NULL,
            alt_text VARCHAR(220) NULL,
            width_px INT UNSIGNED NULL,
            height_px INT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            moderation_status ENUM('approved','binned') NOT NULL DEFAULT 'approved',
            binned_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_site_images_file_name (file_name),
            KEY ix_site_images_display (is_active, sort_order, created_at),
            KEY ix_site_images_moderation (moderation_status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    add_database_column($pdo, 'site_images', 'storage_path', 'VARCHAR(500) NULL AFTER file_name');
    add_database_column($pdo, 'site_images', 'width_px', 'INT UNSIGNED NULL AFTER alt_text');
    add_database_column($pdo, 'site_images', 'height_px', 'INT UNSIGNED NULL AFTER width_px');
    add_database_column($pdo, 'site_images', 'moderation_status', "ENUM('approved','binned') NOT NULL DEFAULT 'approved' AFTER is_active");
    add_database_column($pdo, 'site_images', 'binned_at', 'TIMESTAMP NULL DEFAULT NULL AFTER moderation_status');
    add_database_index($pdo, 'site_images', 'ix_site_images_moderation', '(moderation_status, created_at)');
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
            is_visible TINYINT(1) NOT NULL DEFAULT 0,
            moderation_status ENUM('pending','approved','binned') NOT NULL DEFAULT 'pending',
            status_before_bin ENUM('pending','approved') NULL,
            width_px INT UNSIGNED NULL,
            height_px INT UNSIGNED NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            binned_at TIMESTAMP NULL DEFAULT NULL,
            upload_status ENUM('uploaded','failed') NOT NULL,
            error_message VARCHAR(1000) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_guest_photo_uploads_status (upload_status),
            KEY ix_guest_photo_uploads_created_at (created_at),
            KEY ix_guest_photo_uploads_gallery (upload_status, is_visible, created_at),
            KEY ix_guest_photo_uploads_moderation (moderation_status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    add_database_column($pdo, 'guest_photo_uploads', 'storage_path', 'VARCHAR(500) NULL AFTER drive_url');
    add_database_column($pdo, 'guest_photo_uploads', 'public_url', 'VARCHAR(700) NULL AFTER storage_path');
    add_database_column($pdo, 'guest_photo_uploads', 'is_visible', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER public_url');
    $moderationAdded = add_database_column(
        $pdo,
        'guest_photo_uploads',
        'moderation_status',
        "ENUM('pending','approved','binned') NOT NULL DEFAULT 'pending' AFTER is_visible"
    );
    add_database_column($pdo, 'guest_photo_uploads', 'status_before_bin', "ENUM('pending','approved') NULL AFTER moderation_status");
    add_database_column($pdo, 'guest_photo_uploads', 'width_px', 'INT UNSIGNED NULL AFTER status_before_bin');
    add_database_column($pdo, 'guest_photo_uploads', 'height_px', 'INT UNSIGNED NULL AFTER width_px');
    add_database_column($pdo, 'guest_photo_uploads', 'reviewed_at', 'TIMESTAMP NULL DEFAULT NULL AFTER height_px');
    add_database_column($pdo, 'guest_photo_uploads', 'reviewed_by', 'BIGINT UNSIGNED NULL AFTER reviewed_at');
    add_database_column($pdo, 'guest_photo_uploads', 'binned_at', 'TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by');

    if ($moderationAdded) {
        $pdo->exec(
            "UPDATE guest_photo_uploads
             SET moderation_status = CASE
                 WHEN upload_status = 'uploaded' AND is_visible = 1 THEN 'approved'
                 ELSE 'pending'
             END"
        );
    }

    add_database_index($pdo, 'guest_photo_uploads', 'ix_guest_photo_uploads_gallery', '(upload_status, is_visible, created_at)');
    add_database_index($pdo, 'guest_photo_uploads', 'ix_guest_photo_uploads_moderation', '(moderation_status, created_at)');
}

function ensure_runtime_schema(PDO $pdo): void {
    static $completed = false;
    if ($completed) {
        return;
    }
    ensure_rsvp_table($pdo);
    ensure_site_image_table($pdo);
    ensure_guest_photo_table($pdo);
    $completed = true;
}

function uploads_root_directory(): string {
    $uploadsRoot = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploadsRoot) && !mkdir($uploadsRoot, 0755, true) && !is_dir($uploadsRoot)) {
        throw new RuntimeException('Could not create the uploads directory.');
    }
    $real = realpath($uploadsRoot);
    if ($real === false) {
        throw new RuntimeException('Could not resolve the uploads directory.');
    }
    return $real;
}

function upload_storage_directory(string $relativeDirectory): string {
    $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');
    if ($relativeDirectory === ''
        || str_contains($relativeDirectory, '..')
        || preg_match('#[^a-zA-Z0-9/_-]#', $relativeDirectory)) {
        throw new RuntimeException('Invalid upload storage directory.');
    }

    $root = uploads_root_directory();
    $directory = $root . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create an upload storage directory.');
    }
    $real = realpath($directory);
    if ($real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Upload storage directory is outside the upload root.');
    }
    return $real;
}

function guest_photo_storage_directory(): string {
    return upload_storage_directory('guest-photos');
}

function storage_absolute_path(string $relativePath): ?string {
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!str_starts_with($relativePath, 'uploads/') || str_contains($relativePath, '..')) {
        return null;
    }
    $candidate = dirname(__DIR__) . '/' . $relativePath;
    $real = realpath($candidate);
    $root = uploads_root_directory();
    if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $real;
}

function move_storage_file(string $sourceRelativePath, string $destinationDirectory, string $preferredName): string {
    $source = storage_absolute_path($sourceRelativePath);
    if ($source === null) {
        throw new RuntimeException('The stored image file could not be found.');
    }

    $destination = upload_storage_directory($destinationDirectory);
    $safeName = basename($preferredName);
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        throw new RuntimeException('Invalid image filename.');
    }

    $target = $destination . DIRECTORY_SEPARATOR . $safeName;
    if (is_file($target)) {
        $extension = pathinfo($safeName, PATHINFO_EXTENSION);
        $stem = pathinfo($safeName, PATHINFO_FILENAME);
        $safeName = $stem . '_' . bin2hex(random_bytes(4)) . ($extension !== '' ? '.' . $extension : '');
        $target = $destination . DIRECTORY_SEPARATOR . $safeName;
    }

    if (!@rename($source, $target)) {
        if (!@copy($source, $target) || !@unlink($source)) {
            @unlink($target);
            throw new RuntimeException('Could not move the image between storage folders.');
        }
    }
    @chmod($target, 0644);
    return 'uploads/' . trim($destinationDirectory, '/') . '/' . $safeName;
}

function rollback_storage_move(string $movedRelativePath, string $originalRelativePath): void {
    $source = storage_absolute_path($movedRelativePath);
    $originalRelativePath = ltrim(str_replace('\\', '/', $originalRelativePath), '/');
    if ($source === null
        || !str_starts_with($originalRelativePath, 'uploads/')
        || str_contains($originalRelativePath, '..')) {
        return;
    }

    $root = uploads_root_directory();
    $target = dirname(__DIR__) . '/' . $originalRelativePath;
    $targetDirectory = realpath(dirname($target));
    if ($targetDirectory === false
        || !str_starts_with($targetDirectory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)
        || is_file($target)) {
        return;
    }

    if (!@rename($source, $target)) {
        if (@copy($source, $target)) {
            @unlink($source);
        }
    }
}

function delete_storage_file(?string $relativePath): void {
    if (!is_string($relativePath) || $relativePath === '') {
        return;
    }
    $path = storage_absolute_path($relativePath);
    if ($path !== null && !@unlink($path)) {
        throw new RuntimeException('Could not permanently delete the stored image.');
    }
}

function normalize_submission_text(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    return (string)preg_replace('/\s+/u', ' ', $value);
}

function analyze_rsvp_spam(
    PDO $pdo,
    string $name,
    string $attendance,
    int $guests,
    string $message,
    string $userAgent
): array {
    $normalized = implode('|', [
        normalize_submission_text($name),
        $attendance,
        (string)$guests,
        normalize_submission_text($message),
    ]);
    $submissionHash = hash('sha256', $normalized);
    $score = 0;
    $reasons = [];

    $duplicate = $pdo->prepare(
        'SELECT COUNT(*) FROM rsvp_responses
         WHERE submission_hash = :submission_hash
           AND created_at >= (NOW() - INTERVAL 30 MINUTE)'
    );
    $duplicate->execute([':submission_hash' => $submissionHash]);
    if ((int)$duplicate->fetchColumn() > 0) {
        $score += 4;
        $reasons[] = 'رد مطابق مكرر خلال 30 دقيقة';
    }

    if (preg_match('/(?:https?:\/\/|www\.|\.[a-z]{2,}(?:\/|$))/iu', $name)) {
        $score += 4;
        $reasons[] = 'رابط داخل الاسم';
    }

    preg_match_all('/(?:https?:\/\/|www\.)/iu', $message, $urlMatches);
    $urlCount = count($urlMatches[0] ?? []);
    if ($urlCount >= 2) {
        $score += 4;
        $reasons[] = 'روابط متعددة داخل الرسالة';
    } elseif ($urlCount === 1) {
        $score += 2;
        $reasons[] = 'رابط داخل الرسالة';
    }

    if (preg_match('/(.)\1{7,}/u', $name . ' ' . $message)) {
        $score += 2;
        $reasons[] = 'تكرار غير طبيعي للحروف';
    }
    if (!preg_match('/[\p{L}]/u', $name)) {
        $score += 3;
        $reasons[] = 'الاسم لا يحتوي على حروف';
    }
    if (mb_strlen($message) > 900) {
        $score += 1;
        $reasons[] = 'رسالة طويلة جدًا';
    }
    if (preg_match('/\b(?:bot|crawler|spider|curl|wget|python-requests)\b/i', $userAgent)) {
        $score += 2;
        $reasons[] = 'عميل آلي محتمل';
    }

    $threshold = max(1, (int)site_config('spam_score_threshold'));
    return [
        'submission_hash' => $submissionHash,
        'spam_score' => $score,
        'spam_reasons' => $reasons ? implode('، ', $reasons) : null,
        'is_spam' => $score >= $threshold ? 1 : 0,
    ];
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
