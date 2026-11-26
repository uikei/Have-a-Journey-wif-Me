SET @database_name = DATABASE();

SET @migration = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @database_name
          AND TABLE_NAME = 'videos'
          AND COLUMN_NAME = 'video_storage_key'
    ),
    'SELECT 1',
    'ALTER TABLE videos ADD COLUMN video_storage_key VARCHAR(255) NULL'
);
PREPARE migration_statement FROM @migration;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @database_name
          AND TABLE_NAME = 'videos'
          AND COLUMN_NAME = 'cover_storage_key'
    ),
    'SELECT 1',
    'ALTER TABLE videos ADD COLUMN cover_storage_key VARCHAR(255) NULL'
);
PREPARE migration_statement FROM @migration;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

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
