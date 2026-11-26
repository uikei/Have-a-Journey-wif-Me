CREATE DATABASE IF NOT EXISTS journey_video_diary
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE journey_video_diary;

CREATE TABLE IF NOT EXISTS videos (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(100) NOT NULL,
    creator VARCHAR(40) NOT NULL DEFAULT 'A fellow traveler',
    description VARCHAR(500) NOT NULL DEFAULT '',
    video_filename VARCHAR(80) NULL,
    video_mime_type VARCHAR(32) NULL,
    cover_filename VARCHAR(80) NULL,
    cover_mime_type VARCHAR(32) NULL,
    video_storage_key VARCHAR(255) NULL,
    cover_storage_key VARCHAR(255) NULL,
    delete_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    is_sample TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_videos_public_created (is_sample, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_upload_sessions (
    video_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    multipart_upload_id VARCHAR(1024) NOT NULL,
    video_storage_key VARCHAR(255) NOT NULL,
    video_mime_type VARCHAR(32) NOT NULL,
    video_size BIGINT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    creator VARCHAR(40) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    cover_storage_key VARCHAR(255) NULL,
    cover_mime_type VARCHAR(32) NULL,
    upload_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (video_id),
    KEY idx_upload_sessions_expiration (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO videos
    (id, title, creator, description, is_sample, created_at)
VALUES
    ('00000000000000000000000000000001', 'Sample row: weekend walk', 'Sample traveler',
     'Example database record for testing the schema. This placeholder has no video file and is hidden from the public gallery.',
     1, '2026-01-15 10:00:00'),
    ('00000000000000000000000000000002', 'Sample row: seaside morning', 'Sample traveler',
     'A second example record for testing. Upload a real video to create a playable gallery card.',
     1, '2026-01-16 10:00:00');
