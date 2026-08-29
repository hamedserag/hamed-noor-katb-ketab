<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';
require_admin();

$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !in_array($type, ['guest', 'site'], true)) {
    http_response_code(400);
    exit;
}

try {
    $pdo = db();
    ensure_runtime_schema($pdo);

    if ($type === 'guest') {
        $stmt = $pdo->prepare('SELECT storage_path, mime_type FROM guest_photo_uploads WHERE id = :id LIMIT 1');
    } else {
        $stmt = $pdo->prepare('SELECT storage_path, file_name, mime_type FROM site_images WHERE id = :id LIMIT 1');
    }
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit;
    }

    $relativePath = trim((string)($row['storage_path'] ?? ''));
    if ($relativePath === '' && $type === 'site') {
        $relativePath = 'uploads/' . basename((string)$row['file_name']);
    }
    $path = storage_absolute_path($relativePath);
    if ($path === null) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . (string)$row['mime_type']);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $e) {
    error_log('Admin media error: ' . $e->getMessage());
    http_response_code(500);
}
