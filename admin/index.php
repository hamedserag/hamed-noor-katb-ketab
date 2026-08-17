<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';
start_secure_session();

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function schema_is_installed(PDO $pdo): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE 'admin_users'");
    return (bool)$stmt->fetchColumn();
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

$error = '';
$success = '';

try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
    $error = 'تعذر الاتصال بقاعدة البيانات. أنشئ server/config.local.php باستخدام بيانات MySQL في Hostinger.';
}

$schemaInstalled = $pdo ? schema_is_installed($pdo) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $action = (string)($_POST['action'] ?? '');
    try {
        verify_csrf($_POST['csrf'] ?? null);

        if ($action === 'install_schema') {
            install_schema($pdo);
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
            $success = 'تم إنشاء حساب المدير.';
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
        } elseif ($schemaInstalled && !empty($_SESSION['admin_user_id']) && $action === 'upload_image') {
            if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
                throw new RuntimeException('اختر صورة للرفع.');
            }
            $file = $_FILES['image'];
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('حدث خطأ أثناء رفع الصورة.');
            }
            $maxBytes = (int)site_config('max_upload_bytes');
            if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxBytes) {
                throw new RuntimeException('حجم الصورة أكبر من الحد المسموح.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($file['tmp_name']);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
                throw new RuntimeException('يسمح فقط بصور JPG وPNG وWebP.');
            }

            $uploadDir = realpath(__DIR__ . '/../uploads');
            if ($uploadDir === false) {
                $targetDir = __DIR__ . '/../uploads';
                if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    throw new RuntimeException('تعذر إنشاء مجلد uploads.');
                }
                $uploadDir = realpath($targetDir);
            }
            if ($uploadDir === false) {
                throw new RuntimeException('تعذر الوصول إلى مجلد uploads.');
            }

            $fileName = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException('تعذر حفظ الصورة على الخادم.');
            }

            $caption = trim((string)($_POST['caption'] ?? ''));
            $altText = trim((string)($_POST['alt_text'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $stmt = $pdo->prepare('INSERT INTO site_images (file_name, original_name, mime_type, caption, alt_text, sort_order) VALUES (:file_name, :original_name, :mime_type, :caption, :alt_text, :sort_order)');
            $stmt->execute([
                ':file_name' => $fileName,
                ':original_name' => substr((string)$file['name'], 0, 255),
                ':mime_type' => $mime,
                ':caption' => $caption !== '' ? substr($caption, 0, 180) : null,
                ':alt_text' => $altText !== '' ? substr($altText, 0, 220) : null,
                ':sort_order' => $sortOrder,
            ]);
            $success = 'تم رفع الصورة وإضافتها إلى معرض الدعوة.';
        } elseif ($schemaInstalled && !empty($_SESSION['admin_user_id']) && $action === 'delete_image') {
            $imageId = (int)($_POST['image_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT file_name FROM site_images WHERE id = :id');
            $stmt->execute([':id' => $imageId]);
            $image = $stmt->fetch();
            if ($image) {
                $pdo->prepare('DELETE FROM site_images WHERE id = :id')->execute([':id' => $imageId]);
                $path = __DIR__ . '/../uploads/' . basename((string)$image['file_name']);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $success = 'تم حذف الصورة.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($pdo && $schemaInstalled && !empty($_SESSION['admin_user_id']) && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query('SELECT guest_name, attendance, guests, message, created_at FROM rsvp_responses ORDER BY created_at DESC')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rsvp-responses.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['الاسم', 'الحضور', 'عدد الحضور', 'الرسالة', 'وقت التسجيل']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['guest_name'],
            $row['attendance'] === 'yes' ? 'نعم' : 'لا',
            $row['guests'],
            $row['message'],
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

$adminCount = ($pdo && $schemaInstalled) ? (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() : 0;
$loggedIn = !empty($_SESSION['admin_user_id']);
$stats = ['responses' => 0, 'attending' => 0, 'declined' => 0, 'guests' => 0];
$responses = [];
$images = [];

if ($pdo && $schemaInstalled && $loggedIn) {
    $stats['responses'] = (int)$pdo->query('SELECT COUNT(*) FROM rsvp_responses')->fetchColumn();
    $stats['attending'] = (int)$pdo->query("SELECT COUNT(*) FROM rsvp_responses WHERE attendance='yes'")->fetchColumn();
    $stats['declined'] = (int)$pdo->query("SELECT COUNT(*) FROM rsvp_responses WHERE attendance='no'")->fetchColumn();
    $stats['guests'] = (int)$pdo->query("SELECT COALESCE(SUM(guests),0) FROM rsvp_responses WHERE attendance='yes'")->fetchColumn();
    $responses = $pdo->query('SELECT id, guest_name, attendance, guests, message, created_at FROM rsvp_responses ORDER BY created_at DESC LIMIT 200')->fetchAll();
    $images = $pdo->query('SELECT id, file_name, caption, alt_text, sort_order, created_at FROM site_images ORDER BY sort_order ASC, created_at ASC')->fetchAll();
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة دعوة حامد ونور</title>
<style>
:root{--bg:#14090d;--panel:#241117;--line:#6b4c37;--gold:#e2bd7d;--text:#fff5e7;--muted:#cbbcac;--danger:#c95f67}*{box-sizing:border-box}body{margin:0;background:linear-gradient(160deg,#321820,#12080c 60%);color:var(--text);font-family:Tahoma,Arial,sans-serif;min-height:100vh}a{color:var(--gold)}.wrap{width:min(1180px,calc(100% - 28px));margin:32px auto}.top{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}.panel{background:rgba(36,17,23,.94);border:1px solid rgba(226,189,125,.28);border-radius:18px;padding:22px;margin:18px 0;box-shadow:0 20px 60px rgba(0,0,0,.25)}h1,h2{color:var(--gold)}.msg{padding:12px 14px;border-radius:10px;margin:12px 0}.ok{background:#173a2a}.err{background:#4b1d24}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.stat{padding:18px;border:1px solid rgba(226,189,125,.22);border-radius:14px;text-align:center}.stat strong{display:block;font-size:2rem;color:var(--gold)}label{display:grid;gap:7px;margin:12px 0;color:var(--muted)}input,textarea{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(226,189,125,.25);background:#160b0f;color:var(--text)}button,.btn{border:0;border-radius:999px;padding:11px 18px;background:var(--gold);color:#2a1218;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.danger{background:var(--danger);color:white}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:720px}th,td{padding:11px;border-bottom:1px solid rgba(226,189,125,.15);text-align:right;vertical-align:top}th{color:var(--gold)}.images{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.image-card{border:1px solid rgba(226,189,125,.2);border-radius:14px;overflow:hidden;background:#160b0f}.image-card img{width:100%;height:220px;object-fit:cover;display:block}.image-card .body{padding:12px}.muted{color:var(--muted);font-size:.9rem}.login{max-width:500px;margin:9vh auto}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}@media(max-width:780px){.grid{grid-template-columns:repeat(2,1fr)}.images{grid-template-columns:1fr}.wrap{margin-top:18px}}
</style>
</head>
<body><div class="wrap">
<div class="top"><div><h1>لوحة إدارة الدعوة</h1><div class="muted">تأكيد الحضور + معرض الصور</div></div><?php if ($loggedIn): ?><div class="actions"><a class="btn" href="?export=csv">تصدير CSV</a><a href="logout.php">تسجيل الخروج</a></div><?php endif; ?></div>
<?php if ($success): ?><div class="msg ok"><?=h($success)?></div><?php endif; ?>
<?php if ($error): ?><div class="msg err"><?=h($error)?></div><?php endif; ?>

<?php if (!$pdo): ?>
<div class="panel login"><h2>إعداد قاعدة البيانات</h2><p>انسخ <code>server/config.example.php</code> إلى <code>server/config.local.php</code> وأدخل بيانات قاعدة MySQL التي يتم إنشاؤها من Hostinger.</p></div>
<?php elseif (!$schemaInstalled): ?>
<div class="panel login"><h2>تهيئة قاعدة البيانات</h2><p>الاتصال بقاعدة البيانات ناجح. اضغط الزر لإنشاء جداول تأكيد الحضور والمدير والصور.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="install_schema"><button type="submit">إنشاء الجداول</button></form></div>
<?php elseif ($adminCount === 0): ?>
<div class="panel login"><h2>إنشاء أول حساب مدير</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create_admin"><label>اسم المستخدم<input name="username" required minlength="3"></label><label>كلمة المرور<input name="password" type="password" required minlength="10"></label><button type="submit">إنشاء الحساب</button></form></div>
<?php elseif (!$loggedIn): ?>
<div class="panel login"><h2>تسجيل الدخول</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="login"><label>اسم المستخدم<input name="username" required></label><label>كلمة المرور<input name="password" type="password" required></label><button type="submit">دخول</button></form></div>
<?php else: ?>
<div class="grid"><div class="stat"><strong><?=h((string)$stats['responses'])?></strong>إجمالي الردود</div><div class="stat"><strong><?=h((string)$stats['attending'])?></strong>سيحضرون</div><div class="stat"><strong><?=h((string)$stats['declined'])?></strong>اعتذروا</div><div class="stat"><strong><?=h((string)$stats['guests'])?></strong>إجمالي الضيوف</div></div>

<div class="panel"><h2>تأكيدات الحضور</h2><div class="table-wrap"><table><thead><tr><th>الاسم</th><th>الحضور</th><th>العدد</th><th>الرسالة</th><th>التاريخ</th></tr></thead><tbody><?php foreach ($responses as $row): ?><tr><td><?=h($row['guest_name'])?></td><td><?=$row['attendance']==='yes'?'نعم':'لا'?></td><td><?=h((string)$row['guests'])?></td><td><?=nl2br(h($row['message']))?></td><td><?=h($row['created_at'])?></td></tr><?php endforeach; ?><?php if (!$responses): ?><tr><td colspan="5">لا توجد ردود بعد.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="panel"><h2>رفع صورة إلى المعرض</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="upload_image"><label>الصورة<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><label>التعليق أسفل الصورة<input name="caption" maxlength="180" placeholder="مثال: من أجمل لحظاتنا"></label><label>وصف الصورة لذوي الاحتياجات البصرية<input name="alt_text" maxlength="220"></label><label>الترتيب<input type="number" name="sort_order" value="0"></label><button type="submit">رفع الصورة</button></form></div>

<div class="panel"><h2>الصور المرفوعة</h2><div class="images"><?php foreach ($images as $image): ?><div class="image-card"><img src="../uploads/<?=h(rawurlencode($image['file_name']))?>" alt="<?=h($image['alt_text'])?>"><div class="body"><strong><?=h($image['caption'] ?: 'بدون تعليق')?></strong><div class="muted">الترتيب: <?=h((string)$image['sort_order'])?></div><form method="post" onsubmit="return confirm('حذف الصورة؟')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?=h((string)$image['id'])?>"><button class="danger" type="submit">حذف</button></form></div></div><?php endforeach; ?><?php if (!$images): ?><p>لم يتم رفع صور من لوحة الإدارة بعد.</p><?php endif; ?></div></div>
<?php endif; ?>
</div></body></html>
