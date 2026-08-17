<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $rows = db()->query(
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

    json_response(['ok' => true, 'images' => $images]);
} catch (Throwable $e) {
    error_log('Images API error: ' . $e->getMessage());
    json_response(['ok' => false, 'images' => []], 500);
}
