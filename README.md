# كتب كتاب حامد ونور

دعوة عربية رقمية متجاوبة لكتب كتاب حامد ونور في مسجد قصر محمد علي بالمنيل يوم 3 أكتوبر 2026.

## الوضع الحالي

- الواجهة الأمامية تعمل كصفحة ثابتة ويمكن معاينتها عبر GitHub Pages.
- على Hostinger يتم تفعيل Backend PHP/MySQL لتأكيد الحضور ورفع صور الضيوف.
- تأكيدات الحضور تحفظ في MySQL من خلال `api/rsvp.php`.
- يوجد زر عام للضيوف لرفع صور كتب الكتاب من الهاتف مباشرة.
- صور الضيوف تمر عبر Hostinger PHP ثم Google Apps Script وتحفظ في Google Drive الخاص بحامد ونور.
- بيانات كل عملية رفع تحفظ كذلك في MySQL داخل `guest_photo_uploads`.
- لوحة الإدارة موجودة تحت `/admin/`.
- يظل رفع صور معرض الدعوة من لوحة الإدارة إلى `uploads/` كما هو، بشكل مستقل عن صور الضيوف الخاصة على Google Drive.

## نشر النسخة الكاملة على Hostinger

1. أنشئ قاعدة MySQL ومستخدمًا لها من hPanel في Hostinger.
2. انسخ `server/config.example.php` إلى `server/config.local.php` على الخادم.
3. ضع بيانات قاعدة البيانات الفعلية في `server/config.local.php`.
4. أكمل إعداد Google Apps Script الموضح أدناه، ثم أضف رابط Web App والـ secret إلى `server/config.local.php`.
5. انشر محتويات المستودع داخل `public_html` أو مجلد الموقع المطلوب.
6. افتح `/admin/`.
7. اضغط **إنشاء الجداول** إذا كانت قاعدة البيانات جديدة، ثم أنشئ أول حساب مدير.
8. اختبر نموذج **تأكيد الحضور** وتحقق من ظهوره في لوحة الإدارة.
9. اختبر زر **رفع الصور إلى ألبومنا** من هاتف حقيقي وتحقق من وصول الصورة إلى Google Drive.

> ملف `server/config.local.php` مستبعد من Git ولا يجب رفع كلمات مرور قاعدة البيانات أو secret الخاص بالصور إلى المستودع.

## قاعدة البيانات

المخطط موجود في:

`server/schema.sql`

ويحتوي على:

- `admin_users`
- `rsvp_responses`
- `site_images`
- `guest_photo_uploads`

`api/upload-photo.php` ينفذ `CREATE TABLE IF NOT EXISTS guest_photo_uploads` أيضًا، لذلك يمكن نشر هذه الإضافة على قاعدة موجودة بدون حذف البيانات الحالية.

## تأكيد الحضور

عند النشر على Hostinger يرسل النموذج البيانات إلى:

`api/rsvp.php`

وتحفظ مباشرة في جدول:

`rsvp_responses`

البيانات الحالية تشمل الاسم، حالة الحضور، عدد الحضور، الرسالة، ووقت التسجيل.

على GitHub Pages فقط، لأن PHP غير مدعوم، يبقى السلوك كنسخة معاينة ويحفظ الرد محليًا على جهاز المتصفح بدل قاعدة البيانات.

## صور الضيوف على Google Drive

تم تخصيص مجلد Google Drive التالي لصور الضيوف:

- الاسم: `Hamed & Noor - Wedding Guest Photos`
- Folder ID: `16GHLIT64O9zGlpI8SCbAX3vDfDJziw6K`

وجود Folder ID في المستودع لا يمنح أي شخص صلاحية الدخول إلى المجلد؛ صلاحيات Google Drive نفسها تبقى هي الحاكمة.

### لماذا يوجد Google Apps Script؟

المتصفح لا يتصل بـ Google Drive مباشرة ولا توجد أي Google credentials في JavaScript العام.

المسار هو:

```text
Guest browser
   -> api/upload-photo.php on Hostinger
   -> Google Apps Script Web App
   -> private Google Drive folder
```

الـ secret يبقى على Hostinger وفي Script Properties فقط ولا يرسل إلى متصفح الضيف.

### إعداد Google Apps Script

1. افتح `https://script.google.com` بالحساب الذي لديه صلاحية الكتابة على مجلد الصور.
2. أنشئ مشروع Apps Script جديد.
3. انسخ محتوى:

   `google-apps-script/Code.gs`

   إلى ملف `Code.gs` في المشروع.
4. من **Project Settings -> Script Properties** أضف:

   - Property: `UPLOAD_SECRET`
   - Value: secret عشوائي طويل، مثال يمكنك توليده محليًا بـ:

     ```bash
     openssl rand -hex 32
     ```

5. اختر **Deploy -> New deployment -> Web app**.
6. اجعل **Execute as** = `Me`.
7. اجعل الوصول إلى Web App متاحًا لاستدعاء الموقع العام. الحماية الفعلية للرفع تتم عبر `UPLOAD_SECRET` الذي يتحقق منه السكربت.
8. وافق على صلاحية Google Drive عند الطلب.
9. انسخ رابط Web App الذي ينتهي بـ `/exec`.
10. على Hostinger أنشئ `server/config.local.php` وأضف الرابط ونفس الـ secret.

مثال:

```php
<?php
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'YOUR_DB_NAME',
    'db_user' => 'YOUR_DB_USER',
    'db_password' => 'YOUR_DB_PASSWORD',

    'guest_photo_upload_url' => 'https://script.google.com/macros/s/YOUR_DEPLOYMENT_ID/exec',
    'guest_photo_upload_secret' => 'THE_SAME_LONG_RANDOM_SECRET',
    'max_guest_photo_bytes' => 10 * 1024 * 1024,
];
```

## رفع الصور من الدعوة

قسم **صور من فرحتنا** يسمح للضيف بـ:

- كتابة اسمه اختياريًا.
- اختيار حتى 8 صور في المرة الواحدة.
- رفع JPG / PNG / WebP / HEIC.
- حد أقصى 10 MB للصورة.
- مشاهدة تقدم الرفع من نفس الصفحة.

كل صورة ترسل كطلب مستقل حتى لا يؤدي فشل صورة واحدة إلى فقدان بقية المجموعة.

يحفظ MySQL لكل عملية رفع:

- اسم الضيف إن وُجد.
- اسم الملف الأصلي.
- الاسم المستخدم داخل Drive.
- MIME type والحجم.
- Google Drive file ID والرابط عند النجاح.
- حالة النجاح أو الفشل ورسالة الخطأ عند الفشل.

## رفع صور معرض الدعوة من لوحة الإدارة

المسار القديم ما زال متاحًا من `/admin/` لرفع صور JPG / PNG / WebP إلى معرض الموقع نفسه.

هذه الصور تحفظ في:

`uploads/`

وتسجل بياناتها في:

`site_images`

وهي مستقلة عن صور الضيوف التي تحفظ في Google Drive.

## متطلبات PHP

يوصى بـ PHP 8.x مع الإضافات التالية مفعلة:

- PDO MySQL
- mbstring
- fileinfo
- cURL — موصى به لرفع الصور إلى Google Apps Script، ويوجد fallback عبر PHP streams إذا لم يكن متاحًا.

## التشغيل كواجهة ثابتة فقط

يمكن تشغيل نسخة المعاينة محليًا بدون PHP:

```bash
python -m http.server 8080
```

ثم افتح `http://localhost:8080`.

في المعاينة الثابتة لن تعمل عمليات MySQL أو Google Drive لأنها تحتاج Hostinger PHP.
