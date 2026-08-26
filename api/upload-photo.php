<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
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
            upload_status ENUM('uploaded','failed') NOT NULL,
            error_message VARCHAR(1000) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_guest_photo_uploads_status (upload_status),
            KEY ix_guest_photo_uploads_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function post_json_to_google(string $url, string $json): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_USERAGENT => 'Hamed-Noor-Wedding-Invitation/1.0',
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Google Drive connection failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Google Drive endpoint returned HTTP ' . $status . '.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: Hamed-Noor-Wedding-Invitation/1.0\r\n",
                'content' => $json,
                'timeout' => 90,
                'ignore_errors' => true,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Google Drive connection failed.');
        }
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Google Drive endpoint returned an invalid response.');
    }
    return $decoded;
}

$guestName = trim((string)($_POST['guest_name'] ?? ''));
$originalName = '';
$mime = 'application/octet-stream';
$size = 0;
$storedName = null;

try {
    if (!guest_photo_upload_configured()) {
        json_response([
            'ok' => false,
            'message' => 'رفع الصور غير مفعّل بعد. يرجى إكمال إعداد Google Drive.'
        ], 503);
    }

    if (mb_strlen($guestName) > 160) {
        json_response(['ok' => false, 'message' => 'الاسم طويل جدًا.'], 422);
    }

    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        json_response(['ok' => false, 'message' => 'اختر صورة للرفع.'], 422);
    }

    $file = $_FILES['photo'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'تعذر استلام الصورة.'], 422);
    }

    $size = (int)($file['size'] ?? 0);
    $maxBytes = max(1, (int)site_config('max_guest_photo_bytes'));
    if ($size <= 0 || $size > $maxBytes) {
        json_response(['ok' => false, 'message' => 'حجم الصورة أكبر من الحد المسموح.'], 422);
    }

    $tmpName = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    if (!isset($extensions[$mime])) {
        json_response(['ok' => false, 'message' => 'يسمح فقط بصور JPG وPNG وWebP وHEIC.'], 422);
    }

    $originalName = mb_substr(trim((string)($file['name'] ?? 'photo')), 0, 255);
    if ($originalName === '') {
        $originalName = 'photo.' . $extensions[$mime];
    }

    $storedName = sprintf(
        'wedding_%s_%s.%s',
        gmdate('Ymd_His'),
        bin2hex(random_bytes(6)),
        $extensions[$mime]
    );

    $raw = file_get_contents($tmpName);
    if ($raw === false) {
        throw new RuntimeException('Could not read uploaded image.');
    }

    $payload = json_encode([
        'secret' => (string)site_config('guest_photo_upload_secret'),
        'fileName' => $storedName,
        'originalName' => $originalName,
        'mimeType' => $mime,
        'guestName' => $guestName,
        'dataBase64' => base64_encode($raw),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $drive = post_json_to_google((string)site_config('guest_photo_upload_url'), $payload);
    if (($drive['success'] ?? false) !== true) {
        throw new RuntimeException((string)($drive['message'] ?? 'Google Drive upload failed.'));
    }

    $pdo = db();
    ensure_guest_photo_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO guest_photo_uploads
            (guest_name, original_name, stored_name, mime_type, size_bytes, drive_file_id, drive_url, upload_status)
         VALUES
            (:guest_name, :original_name, :stored_name, :mime_type, :size_bytes, :drive_file_id, :drive_url, :upload_status)'
    );
    $stmt->execute([
        ':guest_name' => $guestName !== '' ? $guestName : null,
        ':original_name' => $originalName,
        ':stored_name' => $storedName,
        ':mime_type' => $mime,
        ':size_bytes' => $size,
        ':drive_file_id' => mb_substr((string)($drive['fileId'] ?? ''), 0, 160),
        ':drive_url' => mb_substr((string)($drive['fileUrl'] ?? ''), 0, 700),
        ':upload_status' => 'uploaded',
    ]);

    json_response([
        'ok' => true,
        'message' => 'تم رفع الصورة إلى ألبوم حامد ونور. شكرًا لمشاركتنا اللحظة.',
        'fileName' => $originalName,
    ]);
} catch (Throwable $e) {
    error_log('Guest photo upload error: ' . $e->getMessage());

    try {
        if ($originalName !== '') {
            $pdo = db();
            ensure_guest_photo_table($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO guest_photo_uploads
                    (guest_name, original_name, stored_name, mime_type, size_bytes, upload_status, error_message)
                 VALUES
                    (:guest_name, :original_name, :stored_name, :mime_type, :size_bytes, :upload_status, :error_message)'
            );
            $stmt->execute([
                ':guest_name' => $guestName !== '' ? $guestName : null,
                ':original_name' => $originalName,
                ':stored_name' => $storedName,
                ':mime_type' => $mime,
                ':size_bytes' => $size,
                ':upload_status' => 'failed',
                ':error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    } catch (Throwable $logError) {
        error_log('Could not log failed guest upload: ' . $logError->getMessage());
    }

    json_response([
        'ok' => false,
        'message' => 'تعذر رفع الصورة الآن. يرجى المحاولة مرة أخرى.'
    ], 500);
}
