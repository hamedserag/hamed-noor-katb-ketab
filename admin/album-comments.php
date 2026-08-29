<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/bootstrap.php';
start_secure_session();

if (empty($_SESSION['admin_user_id'])) {
    json_response(['ok' => false, 'message' => 'يجب تسجيل الدخول أولًا.'], 401);
}

function ensure_guest_album_caption_column(PDO $pdo): void {
    add_database_column($pdo, 'guest_photo_uploads', 'caption', 'VARCHAR(180) NULL AFTER guest_name');
}

function guest_album_fallback_caption(array $row): string {
    $guestName = trim((string)($row['guest_name'] ?? ''));
    return $guestName !== '' ? 'من تصوير ' . $guestName : 'من ضيوفنا';
}

function normalize_album_caption(?string $value): ?string {
    $caption = trim((string)$value);
    if ($caption === '') {
        return null;
    }
    if (mb_strlen($caption) > 180) {
        throw new RuntimeException('التعليق يجب ألا يتجاوز 180 حرفًا.');
    }
    return $caption;
}

try {
    $pdo = db();
    ensure_runtime_schema($pdo);
    ensure_guest_album_caption_column($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $comments = [];

        $siteRows = $pdo->query(
            "SELECT id, caption
             FROM site_images
             WHERE moderation_status = 'approved' AND is_active = 1
             ORDER BY sort_order ASC, created_at ASC
             LIMIT 200"
        )->fetchAll();

        foreach ($siteRows as $row) {
            $raw = trim((string)($row['caption'] ?? ''));
            $comments['site-' . (int)$row['id']] = [
                'source' => 'site',
                'id' => (int)$row['id'],
                'caption' => $raw,
                'displayCaption' => $raw !== '' ? $raw : 'صورة من الإدارة',
            ];
        }

        $guestRows = $pdo->query(
            "SELECT id, guest_name, caption
             FROM guest_photo_uploads
             WHERE upload_status = 'uploaded'
               AND moderation_status = 'approved'
               AND is_visible = 1
             ORDER BY created_at DESC
             LIMIT 200"
        )->fetchAll();

        foreach ($guestRows as $row) {
            $raw = trim((string)($row['caption'] ?? ''));
            $comments['guest-' . (int)$row['id']] = [
                'source' => 'guest',
                'id' => (int)$row['id'],
                'caption' => $raw,
                'displayCaption' => $raw !== '' ? $raw : guest_album_fallback_caption($row),
            ];
        }

        json_response(['ok' => true, 'comments' => $comments]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    verify_csrf($_POST['csrf'] ?? null);

    $source = (string)($_POST['source'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $caption = normalize_album_caption($_POST['caption'] ?? null);

    if ($id <= 0 || !in_array($source, ['site', 'guest'], true)) {
        throw new RuntimeException('بيانات الصورة غير صحيحة.');
    }

    if ($source === 'site') {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM site_images
             WHERE id = :id AND moderation_status = 'approved' AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('لم يتم العثور على صورة المعرض.');
        }

        $update = $pdo->prepare('UPDATE site_images SET caption = :caption WHERE id = :id');
        $update->execute([':caption' => $caption, ':id' => $id]);
        $displayCaption = $caption ?? 'صورة من الإدارة';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, guest_name
             FROM guest_photo_uploads
             WHERE id = :id
               AND upload_status = 'uploaded'
               AND moderation_status = 'approved'
               AND is_visible = 1
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('لم يتم العثور على صورة الضيف في الألبوم.');
        }

        $update = $pdo->prepare('UPDATE guest_photo_uploads SET caption = :caption WHERE id = :id');
        $update->execute([':caption' => $caption, ':id' => $id]);
        $displayCaption = $caption ?? guest_album_fallback_caption($row);
    }

    json_response([
        'ok' => true,
        'caption' => $caption ?? '',
        'displayCaption' => $displayCaption,
        'message' => 'تم تحديث تعليق الصورة.',
    ]);
} catch (Throwable $e) {
    error_log('Album comment admin error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
