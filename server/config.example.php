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

    // Guest photos stay in protected pending storage until an admin approves them.
    'max_guest_photo_bytes' => 10 * 1024 * 1024,

    // RSVP spam is only flagged for admin review; submissions are not auto-rejected.
    'spam_score_threshold' => 4,
];
