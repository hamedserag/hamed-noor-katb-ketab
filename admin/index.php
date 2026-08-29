<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';
start_secure_session();

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function schema_is_installed(PDO $pdo): bool {
    return (bool)$pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchColumn();
}

function install_schema(PDO $pdo): void {
    $sql = file_get_contents(__DIR__ . '/../server/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read schema.sql.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function valid_admin_view(?string $value): string {
    $view = (string)$value;
    return in_array($view, ['overview', 'review', 'album', 'bin', 'rsvp'], true) ? $view : 'overview';
}

function redirect_admin(string $view, string $message, string $kind = 'success'): void {
    $_SESSION['admin_flash'] = ['message' => $message, 'kind' => $kind];
    header('Location: index.php?view=' . rawurlencode(valid_admin_view($view)));
    exit;
}

function fetch_guest_photo(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM guest_photo_uploads WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('لم يتم العثور على صورة الضيف.');
    }
    return $row;
}

function fetch_site_image(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM site_images WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('لم يتم العثور على صورة المعرض.');
    }
    return $row;
}

function site_image_storage_path(array $image): string {
    $stored = trim((string)($image['storage_path'] ?? ''));
    return $stored !== '' ? $stored : 'uploads/' . basename((string)$image['file_name']);
}

function public_url_for_storage_path(string $path): string {
    return '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

function move_storage_with_database_update(
    PDO $pdo,
    string $sourcePath,
    string $destinationDirectory,
    string $preferredName,
    callable $databaseUpdate
): string {
    $movedPath = null;
    $pdo->beginTransaction();
    try {
        $movedPath = move_storage_file($sourcePath, $destinationDirectory, $preferredName);
        $databaseUpdate($movedPath);
        $pdo->commit();
        return $movedPath;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_string($movedPath)) {
            rollback_storage_move($movedPath, $sourcePath);
        }
        throw $e;
    }
}

$error = '';
$success = '';
$view = valid_admin_view($_GET['view'] ?? $_POST['return_view'] ?? null);
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
if (is_array($flash)) {
    if (($flash['kind'] ?? '') === 'error') {
        $error = (string)($flash['message'] ?? '');
    } else {
        $success = (string)($flash['message'] ?? '');
    }
}

try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
    $error = 'تعذر الاتصال بقاعدة البيانات. أنشئ server/config.local.php باستخدام بيانات MySQL في Hostinger.';
}

$schemaInstalled = $pdo ? schema_is_installed($pdo) : false;
$loggedIn = !empty($_SESSION['admin_user_id']);

if ($pdo && $schemaInstalled) {
    try {
        ensure_runtime_schema($pdo);
    } catch (Throwable $e) {
        error_log('Schema migration error: ' . $e->getMessage());
        $error = 'تعذر تحديث بنية قاعدة البيانات. راجع صلاحيات مستخدم MySQL.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = (string)($_POST['action'] ?? '');
    try {
        verify_csrf($_POST['csrf'] ?? null);

        if ($action === 'install_schema') {
            install_schema($pdo);
            ensure_runtime_schema($pdo);
            $schemaInstalled = true;
            $success = 'تم إنشاء جداول قاعدة البيانات بنجاح.';
        } elseif ($schemaInstalled && $action === 'create_admin') {
            $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($adminCount > 0) {
                throw new RuntimeException('تم إنشاء حساب المدير بالفعل.');
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($username) < 3 || mb_strlen($username) > 120) {
                throw new RuntimeException('اسم المستخدم يجب أن يكون بين 3 و120 حرفًا.');
            }
            if (strlen($password) < 10) {
                throw new RuntimeException('كلمة المرور يجب ألا تقل عن 10 أحرف.');
            }
            $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (:username, :password_hash)');
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $_SESSION['admin_user_id'] = (int)$pdo->lastInsertId();
            $_SESSION['admin_username'] = $username;
            session_regenerate_id(true);
            redirect_admin('overview', 'تم إنشاء حساب المدير.');
        } elseif ($schemaInstalled && $action === 'login') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $admin = $stmt->fetch();
            if (!$admin || !password_verify($password, (string)$admin['password_hash'])) {
                throw new RuntimeException('اسم المستخدم أو كلمة المرور غير صحيحة.');
            }
            $_SESSION['admin_user_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = (string)$admin['username'];
            session_regenerate_id(true);
            redirect_admin('overview', 'مرحبًا بك في لوحة الإدارة.');
        } elseif (!$schemaInstalled || !$loggedIn) {
            throw new RuntimeException('يجب تسجيل الدخول أولًا.');
        } elseif ($action === 'upload_image') {
            if (!isset($_FILES['image']) || !is_uploaded_file((string)($_FILES['image']['tmp_name'] ?? ''))) {
                throw new RuntimeException('اختر صورة للرفع.');
            }
            $file = $_FILES['image'];
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('حدث خطأ أثناء رفع الصورة.');
            }
            $maxBytes = (int)site_config('max_upload_bytes');
            if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxBytes) {
                throw new RuntimeException('حجم الصورة أكبر من الحد المسموح.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file((string)$file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $dimensions = @getimagesize((string)$file['tmp_name']);
            if (!isset($extensions[$mime]) || !is_array($dimensions)) {
                throw new RuntimeException('يسمح فقط بصور JPG وPNG وWebP الصالحة.');
            }

            $uploadDir = upload_storage_directory('gallery');
            $fileName = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
                throw new RuntimeException('تعذر حفظ الصورة على الخادم.');
            }
            @chmod($destination, 0644);

            try {
                $caption = trim((string)($_POST['caption'] ?? ''));
                $altText = trim((string)($_POST['alt_text'] ?? ''));
                $stmt = $pdo->prepare(
                    'INSERT INTO site_images
                        (file_name, storage_path, original_name, mime_type, caption, alt_text, width_px, height_px, sort_order, is_active, moderation_status)
                     VALUES
                        (:file_name, :storage_path, :original_name, :mime_type, :caption, :alt_text, :width_px, :height_px, :sort_order, 1, :moderation_status)'
                );
                $stmt->execute([
                    ':file_name' => $fileName,
                    ':storage_path' => 'uploads/gallery/' . $fileName,
                    ':original_name' => mb_substr((string)$file['name'], 0, 255),
                    ':mime_type' => $mime,
                    ':caption' => $caption !== '' ? mb_substr($caption, 0, 180) : null,
                    ':alt_text' => $altText !== '' ? mb_substr($altText, 0, 220) : null,
                    ':width_px' => (int)$dimensions[0],
                    ':height_px' => (int)$dimensions[1],
                    ':sort_order' => (int)($_POST['sort_order'] ?? 0),
                    ':moderation_status' => 'approved',
                ]);
            } catch (Throwable $e) {
                @unlink($destination);
                throw $e;
            }
            redirect_admin('album', 'تم رفع الصورة وإضافتها إلى المعرض.');
        } elseif ($action === 'approve_guest_photo') {
            $photo = fetch_guest_photo($pdo, (int)($_POST['photo_id'] ?? 0));
            if ($photo['upload_status'] !== 'uploaded' || $photo['moderation_status'] !== 'pending') {
                throw new RuntimeException('هذه الصورة ليست في قائمة الانتظار.');
            }
            move_storage_with_database_update(
                $pdo,
                (string)$photo['storage_path'],
                'guest-photos',
                (string)$photo['stored_name'],
                static function (string $newPath) use ($pdo, $photo): void {
                    $stmt = $pdo->prepare(
                        "UPDATE guest_photo_uploads
                         SET storage_path = :storage_path,
                             public_url = :public_url,
                             is_visible = 1,
                             moderation_status = 'approved',
                             reviewed_at = NOW(),
                             reviewed_by = :reviewed_by,
                             binned_at = NULL,
                             status_before_bin = NULL
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        ':storage_path' => $newPath,
                        ':public_url' => public_url_for_storage_path($newPath),
                        ':reviewed_by' => (int)$_SESSION['admin_user_id'],
                        ':id' => (int)$photo['id'],
                    ]);
                }
            );
            redirect_admin('review', 'تم اعتماد الصورة وإضافتها إلى الألبوم.');
        } elseif ($action === 'bin_guest_photo') {
            $photo = fetch_guest_photo($pdo, (int)($_POST['photo_id'] ?? 0));
            if ($photo['moderation_status'] === 'binned') {
                throw new RuntimeException('الصورة موجودة في سلة المحذوفات بالفعل.');
            }
            $previousStatus = $photo['moderation_status'] === 'approved' ? 'approved' : 'pending';
            move_storage_with_database_update(
                $pdo,
                (string)$photo['storage_path'],
                'bin/guest-photos',
                'guest_' . (int)$photo['id'] . '_' . basename((string)$photo['stored_name']),
                static function (string $newPath) use ($pdo, $photo, $previousStatus): void {
                    $stmt = $pdo->prepare(
                        "UPDATE guest_photo_uploads
                         SET storage_path = :storage_path,
                             public_url = NULL,
                             is_visible = 0,
                             status_before_bin = :previous_status,
                             moderation_status = 'binned',
                             binned_at = NOW()
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        ':storage_path' => $newPath,
                        ':previous_status' => $previousStatus,
                        ':id' => (int)$photo['id'],
                    ]);
                }
            );
            redirect_admin($view, 'تم نقل الصورة إلى سلة المحذوفات.');
        } elseif ($action === 'restore_guest_photo') {
            $photo = fetch_guest_photo($pdo, (int)($_POST['photo_id'] ?? 0));
            if ($photo['moderation_status'] !== 'binned') {
                throw new RuntimeException('الصورة ليست في سلة المحذوفات.');
            }
            move_storage_with_database_update(
                $pdo,
                (string)$photo['storage_path'],
                'pending/guest-photos',
                (string)$photo['stored_name'],
                static function (string $newPath) use ($pdo, $photo): void {
                    $stmt = $pdo->prepare(
                        "UPDATE guest_photo_uploads
                         SET storage_path = :storage_path,
                             public_url = NULL,
                             is_visible = 0,
                             moderation_status = 'pending',
                             status_before_bin = NULL,
                             binned_at = NULL
                         WHERE id = :id"
                    );
                    $stmt->execute([':storage_path' => $newPath, ':id' => (int)$photo['id']]);
                }
            );
            redirect_admin('bin', 'تمت استعادة الصورة إلى قائمة المراجعة.');
        } elseif ($action === 'delete_guest_forever') {
            $photo = fetch_guest_photo($pdo, (int)($_POST['photo_id'] ?? 0));
            if ($photo['moderation_status'] !== 'binned') {
                throw new RuntimeException('يجب نقل الصورة إلى السلة قبل حذفها نهائيًا.');
            }
            delete_storage_file((string)$photo['storage_path']);
            $pdo->prepare('DELETE FROM guest_photo_uploads WHERE id = :id')->execute([':id' => (int)$photo['id']]);
            redirect_admin('bin', 'تم حذف الصورة نهائيًا.');
        } elseif ($action === 'bin_site_image') {
            $image = fetch_site_image($pdo, (int)($_POST['image_id'] ?? 0));
            if ($image['moderation_status'] === 'binned') {
                throw new RuntimeException('الصورة موجودة في السلة بالفعل.');
            }
            move_storage_with_database_update(
                $pdo,
                site_image_storage_path($image),
                'bin/site-images',
                'site_' . (int)$image['id'] . '_' . basename((string)$image['file_name']),
                static function (string $newPath) use ($pdo, $image): void {
                    $stmt = $pdo->prepare(
                        "UPDATE site_images
                         SET storage_path = :storage_path,
                             is_active = 0,
                             moderation_status = 'binned',
                             binned_at = NOW()
                         WHERE id = :id"
                    );
                    $stmt->execute([':storage_path' => $newPath, ':id' => (int)$image['id']]);
                }
            );
            redirect_admin('album', 'تم نقل الصورة إلى سلة المحذوفات.');
        } elseif ($action === 'restore_site_image') {
            $image = fetch_site_image($pdo, (int)($_POST['image_id'] ?? 0));
            if ($image['moderation_status'] !== 'binned') {
                throw new RuntimeException('الصورة ليست في سلة المحذوفات.');
            }
            move_storage_with_database_update(
                $pdo,
                (string)$image['storage_path'],
                'gallery',
                (string)$image['file_name'],
                static function (string $newPath) use ($pdo, $image): void {
                    $stmt = $pdo->prepare(
                        "UPDATE site_images
                         SET storage_path = :storage_path,
                             is_active = 1,
                             moderation_status = 'approved',
                             binned_at = NULL
                         WHERE id = :id"
                    );
                    $stmt->execute([':storage_path' => $newPath, ':id' => (int)$image['id']]);
                }
            );
            redirect_admin('bin', 'تمت استعادة الصورة إلى الألبوم.');
        } elseif ($action === 'delete_site_forever') {
            $image = fetch_site_image($pdo, (int)($_POST['image_id'] ?? 0));
            if ($image['moderation_status'] !== 'binned') {
                throw new RuntimeException('يجب نقل الصورة إلى السلة قبل حذفها نهائيًا.');
            }
            delete_storage_file(site_image_storage_path($image));
            $pdo->prepare('DELETE FROM site_images WHERE id = :id')->execute([':id' => (int)$image['id']]);
            redirect_admin('bin', 'تم حذف الصورة نهائيًا.');
        } elseif ($action === 'delete_rsvp') {
            $id = (int)($_POST['rsvp_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM rsvp_responses WHERE id = :id');
            $stmt->execute([':id' => $id]);
            redirect_admin('rsvp', $stmt->rowCount() > 0 ? 'تم حذف رد تأكيد الحضور.' : 'لم يتم العثور على الرد.');
        } elseif ($action === 'set_rsvp_spam') {
            $id = (int)($_POST['rsvp_id'] ?? 0);
            $spamValue = (int)($_POST['spam_value'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE rsvp_responses SET is_spam = :is_spam WHERE id = :id');
            $stmt->execute([':is_spam' => $spamValue, ':id' => $id]);
            redirect_admin('rsvp', $spamValue ? 'تم تعليم الرد كمحتوى مزعج.' : 'تم اعتبار الرد سليمًا.');
        } else {
            throw new RuntimeException('الإجراء المطلوب غير معروف.');
        }
    } catch (Throwable $e) {
        error_log('Admin action error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$adminCount = ($pdo && $schemaInstalled) ? (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() : 0;
$loggedIn = !empty($_SESSION['admin_user_id']);
$stats = [
    'responses' => 0,
    'attending' => 0,
    'guests' => 0,
    'spam' => 0,
    'pending' => 0,
    'album' => 0,
    'bin' => 0,
];
$responses = [];
$pendingPhotos = [];
$guestAlbum = [];
$siteAlbum = [];
$guestBin = [];
$siteBin = [];
$rsvpFilter = (string)($_GET['filter'] ?? 'all');
$rsvpSearch = trim((string)($_GET['q'] ?? ''));
if (!in_array($rsvpFilter, ['all', 'yes', 'no', 'spam', 'clean'], true)) {
    $rsvpFilter = 'all';
}

if ($pdo && $schemaInstalled && $loggedIn && $error === '') {
    $stats['responses'] = (int)$pdo->query('SELECT COUNT(*) FROM rsvp_responses WHERE is_spam = 0')->fetchColumn();
    $stats['attending'] = (int)$pdo->query("SELECT COUNT(*) FROM rsvp_responses WHERE attendance = 'yes' AND is_spam = 0")->fetchColumn();
    $stats['guests'] = (int)$pdo->query("SELECT COALESCE(SUM(guests), 0) FROM rsvp_responses WHERE attendance = 'yes' AND is_spam = 0")->fetchColumn();
    $stats['spam'] = (int)$pdo->query('SELECT COUNT(*) FROM rsvp_responses WHERE is_spam = 1')->fetchColumn();
    $stats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM guest_photo_uploads WHERE upload_status = 'uploaded' AND moderation_status = 'pending'")->fetchColumn();
    $stats['album'] =
        (int)$pdo->query("SELECT COUNT(*) FROM site_images WHERE moderation_status = 'approved' AND is_active = 1")->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM guest_photo_uploads WHERE moderation_status = 'approved' AND is_visible = 1")->fetchColumn();
    $stats['bin'] =
        (int)$pdo->query("SELECT COUNT(*) FROM site_images WHERE moderation_status = 'binned'")->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM guest_photo_uploads WHERE moderation_status = 'binned'")->fetchColumn();

    if ($view === 'review' || $view === 'overview') {
        $pendingPhotos = $pdo->query(
            "SELECT id, guest_name, original_name, mime_type, width_px, height_px, created_at
             FROM guest_photo_uploads
             WHERE upload_status = 'uploaded' AND moderation_status = 'pending'
             ORDER BY created_at ASC
             LIMIT 200"
        )->fetchAll();
    }
    if ($view === 'album') {
        $guestAlbum = $pdo->query(
            "SELECT id, guest_name, original_name, width_px, height_px, created_at
             FROM guest_photo_uploads
             WHERE upload_status = 'uploaded' AND moderation_status = 'approved' AND is_visible = 1
             ORDER BY created_at DESC
             LIMIT 200"
        )->fetchAll();
        $siteAlbum = $pdo->query(
            "SELECT id, file_name, caption, alt_text, width_px, height_px, sort_order, created_at
             FROM site_images
             WHERE moderation_status = 'approved' AND is_active = 1
             ORDER BY sort_order ASC, created_at ASC
             LIMIT 200"
        )->fetchAll();
    }
    if ($view === 'bin') {
        $guestBin = $pdo->query(
            "SELECT id, guest_name, original_name, width_px, height_px, status_before_bin, binned_at
             FROM guest_photo_uploads
             WHERE moderation_status = 'binned'
             ORDER BY binned_at DESC
             LIMIT 200"
        )->fetchAll();
        $siteBin = $pdo->query(
            "SELECT id, file_name, caption, width_px, height_px, binned_at
             FROM site_images
             WHERE moderation_status = 'binned'
             ORDER BY binned_at DESC
             LIMIT 200"
        )->fetchAll();
    }
    if ($view === 'rsvp') {
        $conditions = [];
        $params = [];
        if ($rsvpFilter === 'yes' || $rsvpFilter === 'no') {
            $conditions[] = 'attendance = :attendance';
            $params[':attendance'] = $rsvpFilter;
        } elseif ($rsvpFilter === 'spam') {
            $conditions[] = 'is_spam = 1';
        } elseif ($rsvpFilter === 'clean') {
            $conditions[] = 'is_spam = 0';
        }
        if ($rsvpSearch !== '') {
            $conditions[] = '(guest_name LIKE :query OR message LIKE :query)';
            $params[':query'] = '%' . $rsvpSearch . '%';
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt = $pdo->prepare(
            "SELECT id, guest_name, attendance, guests, message, spam_score, spam_reasons, is_spam, created_at
             FROM rsvp_responses
             {$where}
             ORDER BY created_at DESC
             LIMIT 500"
        );
        $stmt->execute($params);
        $responses = $stmt->fetchAll();
    }
}

if ($pdo && $schemaInstalled && $loggedIn && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query(
        'SELECT guest_name, attendance, guests, message, is_spam, spam_score, spam_reasons, created_at
         FROM rsvp_responses
         ORDER BY created_at DESC'
    )->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rsvp-responses.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['الاسم', 'الحضور', 'عدد الحضور', 'الرسالة', 'مزعج', 'درجة الاشتباه', 'الأسباب', 'وقت التسجيل']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['guest_name'],
            $row['attendance'] === 'yes' ? 'نعم' : 'لا',
            $row['guests'],
            $row['message'],
            $row['is_spam'] ? 'نعم' : 'لا',
            $row['spam_score'],
            $row['spam_reasons'],
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#170c10">
<title>إدارة دعوة حامد ونور</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="admin-shell">
  <header class="admin-header">
    <div>
      <p class="eyebrow">حامد ونور</p>
      <h1>لوحة إدارة الدعوة</h1>
      <p class="header-note">إدارة الصور وتأكيدات الحضور من مكان واحد</p>
    </div>
    <?php if ($loggedIn): ?>
      <div class="header-actions">
        <span class="admin-name"><?=h((string)($_SESSION['admin_username'] ?? 'المدير'))?></span>
        <a class="button button-ghost" href="?view=rsvp&amp;export=csv">تصدير CSV</a>
        <a class="logout-link" href="logout.php">تسجيل الخروج</a>
      </div>
    <?php endif; ?>
  </header>

  <?php if ($success): ?><div class="alert alert-success" role="status"><?=h($success)?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?=h($error)?></div><?php endif; ?>

  <?php if (!$pdo): ?>
    <main class="auth-card"><h2>إعداد قاعدة البيانات</h2><p>انسخ <code>server/config.example.php</code> إلى <code>server/config.local.php</code> وأدخل بيانات MySQL من Hostinger.</p></main>
  <?php elseif (!$schemaInstalled): ?>
    <main class="auth-card"><h2>تهيئة قاعدة البيانات</h2><p>الاتصال ناجح. أنشئ الجداول للبدء.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="install_schema"><button class="button" type="submit">إنشاء الجداول</button></form></main>
  <?php elseif ($adminCount === 0): ?>
    <main class="auth-card"><h2>إنشاء أول حساب مدير</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create_admin"><label>اسم المستخدم<input name="username" required minlength="3"></label><label>كلمة المرور<input name="password" type="password" required minlength="10"></label><button class="button" type="submit">إنشاء الحساب</button></form></main>
  <?php elseif (!$loggedIn): ?>
    <main class="auth-card"><h2>تسجيل الدخول</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="login"><label>اسم المستخدم<input name="username" required autocomplete="username"></label><label>كلمة المرور<input name="password" type="password" required autocomplete="current-password"></label><button class="button" type="submit">دخول</button></form></main>
  <?php else: ?>
    <nav class="admin-nav" aria-label="أقسام لوحة الإدارة">
      <a class="<?=$view==='overview'?'active':''?>" href="?view=overview"><span>⌂</span>نظرة عامة</a>
      <a class="<?=$view==='review'?'active':''?>" href="?view=review"><span>✓</span>مراجعة الصور<?php if ($stats['pending']): ?><b><?=h((string)$stats['pending'])?></b><?php endif; ?></a>
      <a class="<?=$view==='album'?'active':''?>" href="?view=album"><span>▧</span>الألبوم</a>
      <a class="<?=$view==='rsvp'?'active':''?>" href="?view=rsvp"><span>✉</span>الحضور<?php if ($stats['spam']): ?><b class="warning-count"><?=h((string)$stats['spam'])?></b><?php endif; ?></a>
      <a class="<?=$view==='bin'?'active':''?>" href="?view=bin"><span>♲</span>السلة<?php if ($stats['bin']): ?><b><?=h((string)$stats['bin'])?></b><?php endif; ?></a>
    </nav>

    <main class="admin-main">
      <?php if ($view === 'overview'): ?>
        <section class="page-heading"><div><p class="eyebrow">اليوم</p><h2>كل ما يحتاج انتباهك</h2></div></section>
        <section class="stats-grid">
          <article><span>ردود سليمة</span><strong><?=h((string)$stats['responses'])?></strong></article>
          <article><span>سيحضرون</span><strong><?=h((string)$stats['attending'])?></strong></article>
          <article><span>إجمالي الضيوف</span><strong><?=h((string)$stats['guests'])?></strong></article>
          <article class="<?=$stats['pending']?'attention':''?>"><span>صور تنتظر المراجعة</span><strong><?=h((string)$stats['pending'])?></strong><a href="?view=review">ابدأ المراجعة</a></article>
          <article class="<?=$stats['spam']?'attention':''?>"><span>ردود مشتبه بها</span><strong><?=h((string)$stats['spam'])?></strong><a href="?view=rsvp&amp;filter=spam">راجعها</a></article>
          <article><span>صور الألبوم</span><strong><?=h((string)$stats['album'])?></strong><a href="?view=album">عرض الألبوم</a></article>
        </section>
        <?php if ($pendingPhotos): ?>
          <section class="content-card">
            <div class="section-title"><div><p class="eyebrow">الأقدم أولًا</p><h3>أول صور تنتظر قرارك</h3></div><a href="?view=review">عرض الكل</a></div>
            <div class="photo-review-grid compact">
              <?php foreach (array_slice($pendingPhotos, 0, 4) as $photo): ?>
                <article class="photo-review-card">
                  <img src="media.php?type=guest&amp;id=<?=h((string)$photo['id'])?>" alt="<?=h($photo['original_name'])?>">
                  <div class="photo-card-body"><strong><?=h($photo['guest_name'] ?: 'ضيف بدون اسم')?></strong><small><?=h($photo['created_at'])?></small></div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

      <?php elseif ($view === 'review'): ?>
        <section class="page-heading"><div><p class="eyebrow">مراجعة آمنة</p><h2>صور الضيوف الجديدة</h2><p>لن تظهر أي صورة للزوار قبل اعتمادها.</p></div><span class="page-count"><?=h((string)$stats['pending'])?> صورة</span></section>
        <?php if (!$pendingPhotos): ?>
          <div class="empty-state"><span>✓</span><h3>لا توجد صور تنتظر المراجعة</h3><p>أنت على اطلاع بكل الصور الجديدة.</p></div>
        <?php else: ?>
          <div class="photo-review-grid">
            <?php foreach ($pendingPhotos as $photo): ?>
              <article class="photo-review-card">
                <img src="media.php?type=guest&amp;id=<?=h((string)$photo['id'])?>" alt="<?=h($photo['original_name'])?>">
                <div class="photo-card-body">
                  <div><strong><?=h($photo['guest_name'] ?: 'ضيف بدون اسم')?></strong><small><?=h($photo['original_name'])?> · <?=h($photo['created_at'])?></small></div>
                  <div class="card-actions">
                    <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="approve_guest_photo"><input type="hidden" name="photo_id" value="<?=h((string)$photo['id'])?>"><input type="hidden" name="return_view" value="review"><button class="button button-approve" type="submit">اعتماد ونشر</button></form>
                    <form method="post" data-confirm="نقل الصورة إلى سلة المحذوفات؟"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="bin_guest_photo"><input type="hidden" name="photo_id" value="<?=h((string)$photo['id'])?>"><input type="hidden" name="return_view" value="review"><button class="button button-danger-soft" type="submit">إلى السلة</button></form>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($view === 'album'): ?>
        <section class="page-heading"><div><p class="eyebrow">المحتوى المنشور</p><h2>إدارة الألبوم</h2><p>صور الإدارة وصور الضيوف المعتمدة التي يراها الزوار.</p></div><span class="page-count"><?=h((string)$stats['album'])?> صورة</span></section>
        <details class="upload-drawer">
          <summary><span>＋</span> رفع صورة من لوحة الإدارة</summary>
          <form method="post" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="upload_image">
            <input type="hidden" name="return_view" value="album">
            <label>الصورة<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
            <label>التعليق<input name="caption" maxlength="180" placeholder="مثال: من أجمل لحظاتنا"></label>
            <label>الوصف لذوي الاحتياجات البصرية<input name="alt_text" maxlength="220"></label>
            <label>الترتيب<input type="number" name="sort_order" value="0"></label>
            <button class="button" type="submit">رفع ونشر</button>
          </form>
        </details>
        <?php if (!$siteAlbum && !$guestAlbum): ?>
          <div class="empty-state"><span>▧</span><h3>الألبوم فارغ</h3><p>اعتمد صورة ضيف أو ارفع صورة من لوحة الإدارة.</p></div>
        <?php else: ?>
          <div class="album-admin-wall">
            <?php foreach ($siteAlbum as $image): ?>
              <article class="album-admin-item">
                <img src="media.php?type=site&amp;id=<?=h((string)$image['id'])?>" alt="<?=h($image['alt_text'])?>">
                <div><strong><?=h($image['caption'] ?: 'صورة من الإدارة')?></strong><small>ترتيب <?=h((string)$image['sort_order'])?></small><form method="post" data-confirm="إزالة الصورة من الألبوم ونقلها إلى السلة؟"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="bin_site_image"><input type="hidden" name="image_id" value="<?=h((string)$image['id'])?>"><input type="hidden" name="return_view" value="album"><button class="text-danger" type="submit">إزالة</button></form></div>
              </article>
            <?php endforeach; ?>
            <?php foreach ($guestAlbum as $photo): ?>
              <article class="album-admin-item">
                <img src="media.php?type=guest&amp;id=<?=h((string)$photo['id'])?>" alt="<?=h($photo['original_name'])?>">
                <div><strong><?=h($photo['guest_name'] ? 'من تصوير ' . $photo['guest_name'] : 'من ضيوفنا')?></strong><small><?=h($photo['created_at'])?></small><form method="post" data-confirm="إزالة الصورة من الألبوم ونقلها إلى السلة؟"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="bin_guest_photo"><input type="hidden" name="photo_id" value="<?=h((string)$photo['id'])?>"><input type="hidden" name="return_view" value="album"><button class="text-danger" type="submit">إزالة</button></form></div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($view === 'bin'): ?>
        <section class="page-heading"><div><p class="eyebrow">مساحة آمنة</p><h2>سلة الصور</h2><p>الصور هنا غير متاحة للزوار. يمكنك استعادتها أو حذفها نهائيًا.</p></div><span class="page-count"><?=h((string)$stats['bin'])?> صورة</span></section>
        <?php if (!$siteBin && !$guestBin): ?>
          <div class="empty-state"><span>♲</span><h3>السلة فارغة</h3></div>
        <?php else: ?>
          <div class="photo-review-grid bin-grid">
            <?php foreach ($guestBin as $photo): ?>
              <article class="photo-review-card binned">
                <img src="media.php?type=guest&amp;id=<?=h((string)$photo['id'])?>" alt="<?=h($photo['original_name'])?>">
                <div class="photo-card-body"><div><strong><?=h($photo['guest_name'] ?: 'صورة ضيف')?></strong><small>نُقلت <?=h($photo['binned_at'])?></small></div><div class="card-actions"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="restore_guest_photo"><input type="hidden" name="photo_id" value="<?=h((string)$photo['id'])?>"><input type="hidden" name="return_view" value="bin"><button class="button button-ghost" type="submit">استعادة للمراجعة</button></form><form method="post" data-confirm-permanent="سيتم حذف ملف الصورة وسجلها نهائيًا ولا يمكن استعادتهما. متابعة؟"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_guest_forever"><input type="hidden" name="photo_id" value="<?=h((string)$photo['id'])?>"><input type="hidden" name="return_view" value="bin"><button class="button button-danger" type="submit">حذف نهائي</button></form></div></div>
              </article>
            <?php endforeach; ?>
            <?php foreach ($siteBin as $image): ?>
              <article class="photo-review-card binned">
                <img src="media.php?type=site&amp;id=<?=h((string)$image['id'])?>" alt="<?=h($image['caption'])?>">
                <div class="photo-card-body"><div><strong><?=h($image['caption'] ?: 'صورة من الإدارة')?></strong><small>نُقلت <?=h($image['binned_at'])?></small></div><div class="card-actions"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="restore_site_image"><input type="hidden" name="image_id" value="<?=h((string)$image['id'])?>"><input type="hidden" name="return_view" value="bin"><button class="button button-ghost" type="submit">استعادة</button></form><form method="post" data-confirm-permanent="سيتم حذف ملف الصورة وسجلها نهائيًا ولا يمكن استعادتهما. متابعة؟"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_site_forever"><input type="hidden" name="image_id" value="<?=h((string)$image['id'])?>"><input type="hidden" name="return_view" value="bin"><button class="button button-danger" type="submit">حذف نهائي</button></form></div></div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($view === 'rsvp'): ?>
        <section class="page-heading"><div><p class="eyebrow">قائمة الضيوف</p><h2>تأكيدات الحضور</h2><p>الاشتباه بالمحتوى المزعج لا يمنع التسجيل؛ القرار النهائي لك.</p></div><span class="page-count"><?=h((string)count($responses))?> نتيجة</span></section>
        <form method="get" class="filter-bar">
          <input type="hidden" name="view" value="rsvp">
          <label class="search-field"><span>⌕</span><input name="q" value="<?=h($rsvpSearch)?>" placeholder="ابحث بالاسم أو الرسالة"></label>
          <select name="filter">
            <option value="all" <?=$rsvpFilter==='all'?'selected':''?>>كل الردود</option>
            <option value="clean" <?=$rsvpFilter==='clean'?'selected':''?>>الردود السليمة</option>
            <option value="yes" <?=$rsvpFilter==='yes'?'selected':''?>>سيحضرون</option>
            <option value="no" <?=$rsvpFilter==='no'?'selected':''?>>اعتذروا</option>
            <option value="spam" <?=$rsvpFilter==='spam'?'selected':''?>>مشتبه بها</option>
          </select>
          <button class="button button-ghost" type="submit">تطبيق</button>
          <a href="?view=rsvp">مسح</a>
        </form>
        <section class="rsvp-list">
          <?php foreach ($responses as $row): ?>
            <article class="rsvp-row <?=$row['is_spam']?'spam-row':''?>">
              <div class="rsvp-main"><div class="rsvp-name"><strong><?=h($row['guest_name'])?></strong><?php if ($row['is_spam']): ?><span class="spam-badge">مشتبه · <?=h((string)$row['spam_score'])?></span><?php endif; ?></div><p><?=nl2br(h($row['message'] ?: 'بدون رسالة'))?></p><?php if ($row['spam_reasons']): ?><small class="spam-reasons"><?=h($row['spam_reasons'])?></small><?php endif; ?></div>
              <div class="rsvp-meta"><span class="<?=$row['attendance']==='yes'?'yes':'no'?>"><?=$row['attendance']==='yes'?'سيحضر':'اعتذر'?></span><strong><?=h((string)$row['guests'])?> ضيف</strong><small><?=h($row['created_at'])?></small></div>
              <div class="row-actions">
                <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="set_rsvp_spam"><input type="hidden" name="rsvp_id" value="<?=h((string)$row['id'])?>"><input type="hidden" name="spam_value" value="<?=$row['is_spam']?'0':'1'?>"><input type="hidden" name="return_view" value="rsvp"><button type="submit"><?=$row['is_spam']?'اعتباره سليمًا':'تعليم كمزعج'?></button></form>
                <form method="post" data-confirm="حذف رد <?=h($row['guest_name'])?>؟ لا يمكن التراجع عن حذف الرد."><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_rsvp"><input type="hidden" name="rsvp_id" value="<?=h((string)$row['id'])?>"><input type="hidden" name="return_view" value="rsvp"><button class="text-danger" type="submit">حذف الرد</button></form>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if (!$responses): ?><div class="empty-state"><span>✉</span><h3>لا توجد نتائج مطابقة</h3></div><?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  <?php endif; ?>
</div>
<script src="admin.js"></script>
</body>
</html>
