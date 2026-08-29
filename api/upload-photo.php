<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$guestName = trim((string)($_POST['guest_name'] ?? ''));
$originalName = '';
$mime = 'application/octet-stream';
$size = 0;
$storedName = null;
$storedPath = null;
$width = null;
$height = null;

try {
    if (mb_strlen($guestName) > 160) {
        json_response(['ok' => false, 'message' => 'الاسم طويل جدًا.'], 422);
    }

    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        json_response(['ok' => false, 'message' => 'اختر صورة للرفع.'], 422);
    }

    $file = $_FILES['photo'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
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

    $dimensions = @getimagesize($tmpName);
    if (is_array($dimensions)) {
        $width = isset($dimensions[0]) ? (int)$dimensions[0] : null;
        $height = isset($dimensions[1]) ? (int)$dimensions[1] : null;
    } elseif (!in_array($mime, ['image/heic', 'image/heif'], true)) {
        json_response(['ok' => false, 'message' => 'ملف الصورة غير صالح.'], 422);
    }

    $originalName = mb_substr(trim((string)($file['name'] ?? 'photo')), 0, 255);
    if ($originalName === '') {
        $originalName = 'photo.' . $extensions[$mime];
    }

    $storedName = sprintf(
        'wedding_%s_%s.%s',
        gmdate('Ymd_His'),
        bin2hex(random_bytes(8)),
        $extensions[$mime]
    );

    $pdo = db();
    ensure_runtime_schema($pdo);

    $storageDirectory = upload_storage_directory('pending/guest-photos');
    $storedPath = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpName, $storedPath)) {
        throw new RuntimeException('Could not move the uploaded image into Hostinger storage.');
    }
    @chmod($storedPath, 0644);

    $relativePath = 'uploads/pending/guest-photos/' . $storedName;

    $stmt = $pdo->prepare(
        'INSERT INTO guest_photo_uploads
            (guest_name, original_name, stored_name, mime_type, size_bytes, storage_path, public_url, is_visible, moderation_status, width_px, height_px, upload_status)
         VALUES
            (:guest_name, :original_name, :stored_name, :mime_type, :size_bytes, :storage_path, NULL, 0, :moderation_status, :width_px, :height_px, :upload_status)'
    );
    $stmt->execute([
        ':guest_name' => $guestName !== '' ? $guestName : null,
        ':original_name' => $originalName,
        ':stored_name' => $storedName,
        ':mime_type' => $mime,
        ':size_bytes' => $size,
        ':storage_path' => $relativePath,
        ':moderation_status' => 'pending',
        ':width_px' => $width,
        ':height_px' => $height,
        ':upload_status' => 'uploaded',
    ]);

    json_response([
        'ok' => true,
        'message' => 'تم استلام الصورة وستظهر في الألبوم بعد مراجعتها. شكرًا لمشاركتنا اللحظة.',
        'fileName' => $originalName,
        'pendingReview' => true,
    ]);
} catch (Throwable $e) {
    error_log('Guest photo upload error: ' . $e->getMessage());

    if (is_string($storedPath) && is_file($storedPath)) {
        @unlink($storedPath);
    }

    try {
        if ($originalName !== '') {
            $pdo = db();
            ensure_runtime_schema($pdo);
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
