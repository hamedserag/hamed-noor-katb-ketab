<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    ensure_runtime_schema($pdo);
    add_database_column($pdo, 'guest_photo_uploads', 'caption', 'VARCHAR(180) NULL AFTER guest_name');

    $rows = $pdo->query(
        "SELECT id, file_name, storage_path, caption, alt_text, width_px, height_px, sort_order, created_at
         FROM site_images
         WHERE is_active = 1 AND moderation_status = 'approved'
         ORDER BY sort_order ASC, created_at ASC"
    )->fetchAll();

    $images = array_map(static function (array $row): array {
        $path = trim((string)($row['storage_path'] ?? ''));
        $url = $path !== ''
            ? '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))))
            : '/uploads/' . rawurlencode((string)$row['file_name']);
        return [
            'id' => 'site-' . (int)$row['id'],
            'source' => 'site',
            'url' => $url,
            'caption' => (string)($row['caption'] ?? ''),
            'altText' => (string)($row['alt_text'] ?? ''),
            'width' => $row['width_px'] !== null ? (int)$row['width_px'] : null,
            'height' => $row['height_px'] !== null ? (int)$row['height_px'] : null,
            'sortOrder' => (int)$row['sort_order'],
        ];
    }, $rows);

    $guestRows = $pdo->query(
        "SELECT id, guest_name, caption, original_name, public_url, width_px, height_px, created_at
         FROM guest_photo_uploads
         WHERE upload_status = 'uploaded'
           AND is_visible = 1
           AND moderation_status = 'approved'
           AND public_url IS NOT NULL
           AND public_url <> ''
         ORDER BY created_at DESC
         LIMIT 100"
    )->fetchAll();

    foreach ($guestRows as $row) {
        $guestName = trim((string)($row['guest_name'] ?? ''));
        $customCaption = trim((string)($row['caption'] ?? ''));
        $caption = $customCaption !== ''
            ? $customCaption
            : ($guestName !== '' ? 'من تصوير ' . $guestName : 'من ضيوفنا');
        $images[] = [
            'id' => 'guest-' . (int)$row['id'],
            'source' => 'guest',
            'url' => (string)$row['public_url'],
            'caption' => $caption,
            'altText' => $caption,
            'width' => $row['width_px'] !== null ? (int)$row['width_px'] : null,
            'height' => $row['height_px'] !== null ? (int)$row['height_px'] : null,
            'sortOrder' => 1000000 + (int)$row['id'],
        ];
    }

    json_response(['ok' => true, 'images' => $images]);
} catch (Throwable $e) {
    error_log('Images API error: ' . $e->getMessage());
    json_response(['ok' => false, 'images' => []], 500);
}
