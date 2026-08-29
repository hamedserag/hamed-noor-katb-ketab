CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rsvp_responses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    guest_name VARCHAR(160) NOT NULL,
    attendance ENUM('yes','no') NOT NULL,
    guests TINYINT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(1500) NULL,
    user_agent VARCHAR(500) NULL,
    submission_hash CHAR(64) NULL,
    spam_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    spam_reasons VARCHAR(1000) NULL,
    is_spam TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_rsvp_created_at (created_at),
    KEY ix_rsvp_attendance (attendance),
    KEY ix_rsvp_spam (is_spam, created_at),
    KEY ix_rsvp_submission_hash (submission_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    caption VARCHAR(180) NULL,
    alt_text VARCHAR(220) NULL,
    width_px INT UNSIGNED NULL,
    height_px INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    moderation_status ENUM('approved','binned') NOT NULL DEFAULT 'approved',
    binned_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_images_file_name (file_name),
    KEY ix_site_images_display (is_active, sort_order, created_at),
    KEY ix_site_images_moderation (moderation_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_photo_uploads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    guest_name VARCHAR(160) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    drive_file_id VARCHAR(160) NULL,
    drive_url VARCHAR(700) NULL,
    storage_path VARCHAR(500) NULL,
    public_url VARCHAR(700) NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 0,
    moderation_status ENUM('pending','approved','binned') NOT NULL DEFAULT 'pending',
    status_before_bin ENUM('pending','approved') NULL,
    width_px INT UNSIGNED NULL,
    height_px INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    binned_at TIMESTAMP NULL DEFAULT NULL,
    upload_status ENUM('uploaded','failed') NOT NULL,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_guest_photo_uploads_status (upload_status),
    KEY ix_guest_photo_uploads_created_at (created_at),
    KEY ix_guest_photo_uploads_gallery (upload_status, is_visible, created_at),
    KEY ix_guest_photo_uploads_moderation (moderation_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
