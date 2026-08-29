<?php
declare(strict_types=1);
require_once __DIR__ . '/../server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data)) {
        throw new InvalidArgumentException('Invalid request body.');
    }

    // Honeypot: real guests never see or fill this field.
    if (!empty($data['website'])) {
        json_response(['ok' => true, 'message' => 'تم تسجيل تأكيد الحضور.']);
    }

    $name = trim((string)($data['name'] ?? ''));
    $attendance = (string)($data['attendance'] ?? '');
    $guests = (int)($data['guests'] ?? 1);
    $message = trim((string)($data['message'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
        json_response(['ok' => false, 'message' => 'يرجى إدخال الاسم الكامل بشكل صحيح.'], 422);
    }
    if (!in_array($attendance, ['yes', 'no'], true)) {
        json_response(['ok' => false, 'message' => 'يرجى اختيار حالة الحضور.'], 422);
    }

    if ($attendance === 'no') {
        $guests = 0;
    } elseif ($guests < 1 || $guests > 10) {
        json_response(['ok' => false, 'message' => 'عدد الحضور يجب أن يكون بين ١ و١٠.'], 422);
    }

    if (mb_strlen($message) > 1500) {
        json_response(['ok' => false, 'message' => 'الرسالة طويلة جدًا.'], 422);
    }

    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $pdo = db();
    ensure_runtime_schema($pdo);
    $spam = analyze_rsvp_spam($pdo, $name, $attendance, $guests, $message, $userAgent);

    $stmt = $pdo->prepare(
        'INSERT INTO rsvp_responses
            (guest_name, attendance, guests, message, user_agent, submission_hash, spam_score, spam_reasons, is_spam)
         VALUES
            (:name, :attendance, :guests, :message, :user_agent, :submission_hash, :spam_score, :spam_reasons, :is_spam)'
    );
    $stmt->execute([
        ':name' => $name,
        ':attendance' => $attendance,
        ':guests' => $guests,
        ':message' => $message !== '' ? $message : null,
        ':user_agent' => $userAgent !== '' ? $userAgent : null,
        ':submission_hash' => $spam['submission_hash'],
        ':spam_score' => $spam['spam_score'],
        ':spam_reasons' => $spam['spam_reasons'],
        ':is_spam' => $spam['is_spam'],
    ]);

    json_response(['ok' => true, 'message' => 'تم تسجيل تأكيد حضوركم، شكرًا لمشاركتنا فرحتنا.']);
} catch (JsonException $e) {
    json_response(['ok' => false, 'message' => 'تعذر قراءة البيانات المرسلة.'], 400);
} catch (Throwable $e) {
    error_log('RSVP error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'تعذر تسجيل الرد الآن. يرجى المحاولة مرة أخرى.'], 500);
}
