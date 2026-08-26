<?php
return [
    // Hostinger MySQL values. Copy this file to config.local.php on the server.
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'CHANGE_ME',
    'db_user' => 'CHANGE_ME',
    'db_password' => 'CHANGE_ME',

    // Maximum uploaded gallery image size in bytes (10 MB by default).
    'max_upload_bytes' => 10 * 1024 * 1024,

    // Guest wedding photos -> Hostinger PHP -> Google Apps Script -> Google Drive.
    // Never commit the real secret to GitHub; put it only in server/config.local.php.
    'guest_photo_upload_url' => 'https://script.google.com/macros/s/PASTE_DEPLOYMENT_ID/exec',
    'guest_photo_upload_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
    'max_guest_photo_bytes' => 10 * 1024 * 1024,
];
