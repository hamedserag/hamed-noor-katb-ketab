<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $pdo = db();
    $rows = $pdo->query(
        'SELECT id, file_name, caption, alt_text, sort_order, created_at FROM site_images WHERE is_active = 1 ORDER BY sort_order ASC, created_at ASC'
    )->fetchAll();

    $images = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'url' => 'uploads/' . rawurlencode((string)$row['file_name']),
            'caption' => (string)($row['caption'] ?? ''),
            'altText' => (string)($row['alt_text'] ?? ''),
            'sortOrder' => (int)$row['sort_order'],
        ];
    }, $rows);

    ensure_guest_photo_table($pdo);
    $guestRows = $pdo->query(
        "SELECT id, guest_name, original_name, public_url, created_at
         FROM guest_photo_uploads
         WHERE upload_status = 'uploaded'
           AND is_visible = 1
           AND public_url IS NOT NULL
           AND public_url <> ''
         ORDER BY created_at DESC
         LIMIT 100"
    )->fetchAll();

    foreach ($guestRows as $row) {
        $guestName = trim((string)($row['guest_name'] ?? ''));
        $caption = $guestName !== '' ? 'من تصوير ' . $guestName : 'من ضيوفنا';
        $images[] = [
            'id' => 'guest-' . (int)$row['id'],
            'url' => (string)$row['public_url'],
            'caption' => $caption,
            'altText' => $caption,
            'sortOrder' => 1000000 + (int)$row['id'],
        ];
    }

    json_response(['ok' => true, 'images' => $images]);
} catch (Throwable $e) {
    error_log('Images API error: ' . $e->getMessage());
    json_response(['ok' => false, 'images' => []], 500);
}
