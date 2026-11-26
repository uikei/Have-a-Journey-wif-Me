<?php
declare(strict_types=1);

const MAX_LOCAL_VIDEO_BYTES = 157286400;
const MAX_R2_VIDEO_BYTES = 21474836480;
const MAX_COVER_BYTES = 5242880;
const R2_PART_SIZE_BYTES = 67108864;
const ALLOWED_VIDEO_TYPES = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
];
const ALLOWED_COVER_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

final class ServiceUnavailableException extends RuntimeException
{
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$allowed_origin = getenv('JOURNEY_ALLOWED_ORIGIN') ?: '';
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$origin_is_allowed = $allowed_origin !== '' && $request_origin !== '' && hash_equals($allowed_origin, $request_origin);
if ($origin_is_allowed) {
    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    header('Access-Control-Expose-Headers: Accept-Ranges, Content-Range');
    header('Vary: Origin');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    if (!$origin_is_allowed) {
        send_json(['success' => false, 'error' => 'This website is not allowed to use the video API.'], 403);
    }
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'list' && $method === 'GET') {
        send_json(array_merge(['success' => true], list_videos()));
    }

    if ($action === 'upload' && $method === 'POST') {
        upload_video();
    }

    if ($action === 'upload-init' && $method === 'POST') {
        initialize_r2_upload();
    }

    if ($action === 'upload-part' && $method === 'POST') {
        sign_r2_upload_part();
    }

    if ($action === 'upload-complete' && $method === 'POST') {
        complete_r2_upload();
    }

    if ($action === 'upload-abort' && $method === 'POST') {
        abort_r2_upload();
    }

    if ($action === 'video' && $method === 'GET') {
        stream_media('video');
    }

    if ($action === 'thumbnail' && $method === 'GET') {
        stream_media('cover');
    }

    if ($action === 'delete' && $method === 'POST') {
        delete_video();
    }

    if (in_array($action, ['list', 'upload', 'upload-init', 'upload-part', 'upload-complete', 'upload-abort', 'video', 'thumbnail', 'delete'], true)) {
        header('Allow: ' . (in_array($action, ['upload', 'upload-init', 'upload-part', 'upload-complete', 'upload-abort', 'delete'], true) ? 'POST' : 'GET'));
        send_json(['success' => false, 'error' => 'That request method is not allowed.'], 405);
    }

    send_json(['success' => false, 'error' => 'That request is not available.'], 404);
} catch (PDOException $exception) {
    error_log('Video database error: ' . $exception->getMessage());
    send_json([
        'success' => false,
        'error' => 'The video database is unavailable. Import data/database.sql and check the database settings.',
    ], 503);
} catch (ServiceUnavailableException $exception) {
    error_log('Video storage service unavailable: ' . $exception->getMessage());
    send_json(['success' => false, 'error' => $exception->getMessage()], 503);
} catch (RuntimeException $exception) {
    send_json(['success' => false, 'error' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    error_log('Video site error: ' . $exception->getMessage());
    send_json(['success' => false, 'error' => 'Something went wrong on the server. Please try again.'], 500);
}

function database_connection(): PDO
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';
    return get_database_connection();
}

function uploads_directory(): string
{
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The video upload folder could not be created.');
    }
    return $directory;
}

function storage_driver(): string
{
    $driver = getenv('JOURNEY_STORAGE_DRIVER') ?: 'local';
    if (!in_array($driver, ['local', 'r2'], true)) {
        throw new ServiceUnavailableException('JOURNEY_STORAGE_DRIVER must be either local or r2.');
    }
    return $driver;
}

function require_r2_storage(): void
{
    if (storage_driver() !== 'r2') {
        throw new RuntimeException('Direct uploads are not enabled on this server.');
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'r2_storage.php';
}

function list_videos(): array
{
    $query = database_connection()->query(
        "SELECT id, title, creator, description,
                COALESCE(cover_storage_key, cover_filename) IS NOT NULL AS has_cover,
                DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at
         FROM videos
         WHERE is_sample = 0
           AND (video_filename IS NOT NULL OR video_storage_key IS NOT NULL)
         ORDER BY created_at DESC
         LIMIT 200"
    );

    $videos = $query->fetchAll();
    foreach ($videos as &$video) {
        $video['has_cover'] = (bool) $video['has_cover'];
    }
    unset($video);

    return [
        'upload_mode' => storage_driver(),
        'videos' => $videos,
    ];
}

function upload_video(): void
{
    if (storage_driver() !== 'local') {
        throw new RuntimeException('This server uses resumable direct uploads. Refresh the page and try again.');
    }

    $video = inspect_uploaded_file(
        $_FILES['video'] ?? null,
        MAX_LOCAL_VIDEO_BYTES,
        ALLOWED_VIDEO_TYPES,
        'Please upload a valid MP4 or WebM video.',
        'video'
    );
    if ($video === null) {
        throw new RuntimeException('Choose a video to share first.');
    }

    $title = clean_text($_POST['title'] ?? '', 100);
    $creator = clean_text($_POST['creator'] ?? '', 40);
    $description = clean_text($_POST['description'] ?? '', 500);
    if ($title === '') {
        throw new RuntimeException('Add a title for your video.');
    }

    $cover = inspect_uploaded_file(
        $_FILES['thumbnail'] ?? null,
        MAX_COVER_BYTES,
        ALLOWED_COVER_TYPES,
        'Choose a JPEG, PNG, or WebP cover image under 5 MB.',
        'cover'
    );
    if ($cover !== null) {
        $image_info = getimagesize($cover['tmp_name']);
        if ($image_info === false || $image_info[0] > 4096 || $image_info[1] > 4096 || $image_info[0] * $image_info[1] > 16777216) {
            throw new RuntimeException('The cover image dimensions are too large or could not be read.');
        }
    }

    $pdo = database_connection();
    $id = bin2hex(random_bytes(16));
    $delete_token = bin2hex(random_bytes(32));
    $video_filename = $id . '.' . $video['extension'];
    $cover_filename = $cover === null ? null : $id . '.' . $cover['extension'];
    $video_path = uploads_directory() . DIRECTORY_SEPARATOR . $video_filename;
    $cover_path = $cover_filename === null ? null : uploads_directory() . DIRECTORY_SEPARATOR . $cover_filename;

    if (!move_uploaded_file($video['tmp_name'], $video_path)) {
        throw new RuntimeException('The server could not save your video. Please try again.');
    }

    if ($cover !== null && !move_uploaded_file($cover['tmp_name'], $cover_path)) {
        unlink($video_path);
        throw new RuntimeException('The server could not save your cover image. Please try again.');
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO videos
                (id, title, creator, description, video_filename, video_mime_type,
                 cover_filename, cover_mime_type, delete_token_hash, is_sample)
             VALUES
                (:id, :title, :creator, :description, :video_filename, :video_mime_type,
                 :cover_filename, :cover_mime_type, :delete_token_hash, 0)'
        );
        $statement->execute([
            'id' => $id,
            'title' => $title,
            'creator' => $creator !== '' ? $creator : 'A fellow traveler',
            'description' => $description,
            'video_filename' => $video_filename,
            'video_mime_type' => $video['mime_type'],
            'cover_filename' => $cover_filename,
            'cover_mime_type' => $cover['mime_type'] ?? null,
            'delete_token_hash' => hash('sha256', $delete_token),
        ]);
    } catch (Throwable $exception) {
        if (is_file($video_path)) {
            unlink($video_path);
        }
        if ($cover_path !== null && is_file($cover_path)) {
            unlink($cover_path);
        }
        throw $exception;
    }

    send_json([
        'success' => true,
        'id' => $id,
        'delete_token' => $delete_token,
    ], 201);
}

function initialize_r2_upload(): void
{
    require_r2_storage();
    $payload = read_json_request();

    $title = clean_text($payload['title'] ?? '', 100);
    if ($title === '') {
        throw new RuntimeException('Add a title for your video.');
    }

    $creator = clean_text($payload['creator'] ?? '', 40);
    $description = clean_text($payload['description'] ?? '', 500);
    $file_name = $payload['file_name'] ?? '';
    $video_mime_type = $payload['video_mime_type'] ?? '';
    $video_size = $payload['video_size'] ?? 0;
    if (!is_string($file_name) || !is_string($video_mime_type) || !is_int($video_size)) {
        throw new RuntimeException('The video details are incomplete.');
    }

    $video_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if (
        $video_size < 1
        || $video_size > MAX_R2_VIDEO_BYTES
        || !isset(ALLOWED_VIDEO_TYPES[$video_mime_type])
        || ALLOWED_VIDEO_TYPES[$video_mime_type] !== $video_extension
    ) {
        throw new RuntimeException('Choose a valid MP4 or WebM video no larger than 20 GB.');
    }

    $cover_mime_type = $payload['cover_mime_type'] ?? null;
    $cover_size = $payload['cover_size'] ?? 0;
    if ($cover_mime_type !== null && (
        !is_string($cover_mime_type)
        || !isset(ALLOWED_COVER_TYPES[$cover_mime_type])
        || !is_int($cover_size)
        || $cover_size < 1
        || $cover_size > MAX_COVER_BYTES
    )) {
        throw new RuntimeException('Choose a valid JPEG, PNG, or WebP cover image under 5 MB.');
    }
    if ($cover_mime_type === null) {
        $cover_size = 0;
    }

    $pdo = database_connection();
    cleanup_expired_r2_upload_sessions($pdo);

    $video_id = bin2hex(random_bytes(16));
    $upload_token = bin2hex(random_bytes(32));
    $video_key = 'videos/' . $video_id . '.' . $video_extension;
    $cover_key = $cover_mime_type === null
        ? null
        : 'covers/' . $video_id . '.' . ALLOWED_COVER_TYPES[$cover_mime_type];
    $multipart_upload_id = r2_create_multipart_upload($video_key, $video_mime_type);

    try {
        $statement = $pdo->prepare(
            'INSERT INTO video_upload_sessions
                (video_id, multipart_upload_id, video_storage_key, video_mime_type, video_size,
                 title, creator, description, cover_storage_key, cover_mime_type,
                 upload_token_hash, expires_at)
             VALUES
                (:video_id, :multipart_upload_id, :video_storage_key, :video_mime_type, :video_size,
                 :title, :creator, :description, :cover_storage_key, :cover_mime_type,
                 :upload_token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))'
        );
        $statement->execute([
            'video_id' => $video_id,
            'multipart_upload_id' => $multipart_upload_id,
            'video_storage_key' => $video_key,
            'video_mime_type' => $video_mime_type,
            'video_size' => $video_size,
            'title' => $title,
            'creator' => $creator !== '' ? $creator : 'A fellow traveler',
            'description' => $description,
            'cover_storage_key' => $cover_key,
            'cover_mime_type' => $cover_mime_type,
            'upload_token_hash' => hash('sha256', $upload_token),
        ]);
    } catch (Throwable $exception) {
        try {
            r2_abort_multipart_upload($video_key, $multipart_upload_id);
        } catch (Throwable $abort_exception) {
            error_log('Could not abort unregistered R2 upload: ' . $abort_exception->getMessage());
        }
        throw $exception;
    }

    $cover_upload_url = $cover_key === null
        ? null
        : r2_presign_cover_upload($cover_key, $cover_mime_type);

    send_json([
        'success' => true,
        'video_id' => $video_id,
        'upload_token' => $upload_token,
        'part_size' => R2_PART_SIZE_BYTES,
        'cover_upload_url' => $cover_upload_url,
    ], 201);
}

function sign_r2_upload_part(): void
{
    require_r2_storage();
    $payload = read_json_request();
    $session = get_r2_upload_session($payload);
    $part_number = $payload['part_number'] ?? null;
    $part_count = (int) ceil((int) $session['video_size'] / R2_PART_SIZE_BYTES);
    if (!is_int($part_number) || $part_number < 1 || $part_number > $part_count) {
        throw new RuntimeException('The requested video part is invalid.');
    }

    $url = r2_presign_upload_part(
        $session['video_storage_key'],
        $session['multipart_upload_id'],
        $part_number
    );
    send_json(['success' => true, 'url' => $url]);
}

function complete_r2_upload(): void
{
    require_r2_storage();
    $payload = read_json_request();
    $session = get_r2_upload_session($payload);
    $parts = $payload['parts'] ?? null;
    $part_count = (int) ceil((int) $session['video_size'] / R2_PART_SIZE_BYTES);
    if (!is_array($parts) || count($parts) !== $part_count) {
        throw new RuntimeException('The video upload is incomplete. Please retry it.');
    }

    $completed_parts = [];
    foreach (array_values($parts) as $index => $part) {
        if (!is_array($part) || ($part['part_number'] ?? null) !== $index + 1 || !is_string($part['etag'] ?? null)) {
            throw new RuntimeException('The uploaded video parts are invalid. Please retry the upload.');
        }
        $etag = trim($part['etag'], "\" \t\n\r\0\x0B");
        if (!preg_match('/\A[a-f0-9-]{8,128}\z/i', $etag)) {
            throw new RuntimeException('R2 returned an invalid video part receipt.');
        }
        $completed_parts[] = [
            'PartNumber' => $index + 1,
            'ETag' => '"' . $etag . '"',
        ];
    }

    r2_complete_multipart_upload(
        $session['video_storage_key'],
        $session['multipart_upload_id'],
        $completed_parts
    );

    $pdo = database_connection();
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO videos
                (id, title, creator, description, video_filename, video_mime_type,
                 cover_filename, cover_mime_type, video_storage_key, cover_storage_key,
                 delete_token_hash, is_sample)
             VALUES
                (:id, :title, :creator, :description, NULL, :video_mime_type,
                 NULL, :cover_mime_type, :video_storage_key, :cover_storage_key,
                 :delete_token_hash, 0)'
        );
        $insert->execute([
            'id' => $session['video_id'],
            'title' => $session['title'],
            'creator' => $session['creator'],
            'description' => $session['description'],
            'video_mime_type' => $session['video_mime_type'],
            'cover_mime_type' => $session['cover_mime_type'],
            'video_storage_key' => $session['video_storage_key'],
            'cover_storage_key' => $session['cover_storage_key'],
            'delete_token_hash' => $session['upload_token_hash'],
        ]);

        $remove_session = $pdo->prepare('DELETE FROM video_upload_sessions WHERE video_id = :video_id');
        $remove_session->execute(['video_id' => $session['video_id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            r2_delete_object($session['video_storage_key']);
            if ($session['cover_storage_key'] !== null) {
                r2_delete_object($session['cover_storage_key']);
            }
        } catch (Throwable $cleanup_exception) {
            error_log('Could not clean up an R2 upload after a database error: ' . $cleanup_exception->getMessage());
        }
        $remove_session = $pdo->prepare('DELETE FROM video_upload_sessions WHERE video_id = :video_id');
        $remove_session->execute(['video_id' => $session['video_id']]);
        throw $exception;
    }

    send_json(['success' => true, 'id' => $session['video_id']]);
}

function abort_r2_upload(): void
{
    require_r2_storage();
    $payload = read_json_request();
    $session = get_r2_upload_session($payload, false);
    if ($session === null) {
        send_json(['success' => true]);
    }

    try {
        r2_abort_multipart_upload($session['video_storage_key'], $session['multipart_upload_id']);
        if ($session['cover_storage_key'] !== null) {
            r2_delete_object($session['cover_storage_key']);
        }
    } catch (Throwable $exception) {
        error_log('Could not abort R2 upload ' . $session['video_id'] . ': ' . $exception->getMessage());
        throw new RuntimeException('The unfinished upload could not be cancelled. Please try again later.');
    }

    $statement = database_connection()->prepare('DELETE FROM video_upload_sessions WHERE video_id = :video_id');
    $statement->execute(['video_id' => $session['video_id']]);
    send_json(['success' => true]);
}

function get_r2_upload_session(array $payload, bool $must_exist = true): ?array
{
    $video_id = $payload['video_id'] ?? '';
    $upload_token = $payload['upload_token'] ?? '';
    if (!is_string($video_id) || !preg_match('/\A[a-f0-9]{32}\z/', $video_id)) {
        throw new RuntimeException('The upload session is invalid.');
    }
    if (!is_string($upload_token) || !preg_match('/\A[a-f0-9]{64}\z/', $upload_token)) {
        throw new RuntimeException('The upload session token is invalid.');
    }

    $statement = database_connection()->prepare(
        'SELECT video_id, multipart_upload_id, video_storage_key, video_mime_type, video_size,
                title, creator, description, cover_storage_key, cover_mime_type,
                upload_token_hash
         FROM video_upload_sessions
         WHERE video_id = :video_id AND expires_at > UTC_TIMESTAMP()'
    );
    $statement->execute(['video_id' => $video_id]);
    $session = $statement->fetch();
    if ($session === false) {
        if (!$must_exist) {
            return null;
        }
        throw new RuntimeException('This upload session has expired. Please start the upload again.');
    }
    if (!hash_equals($session['upload_token_hash'], hash('sha256', $upload_token))) {
        throw new RuntimeException('The upload session token is invalid.');
    }

    return $session;
}

function cleanup_expired_r2_upload_sessions(PDO $pdo): void
{
    $statement = $pdo->query(
        'SELECT video_id, multipart_upload_id, video_storage_key, cover_storage_key
         FROM video_upload_sessions
         WHERE expires_at <= UTC_TIMESTAMP()'
    );
    foreach ($statement->fetchAll() as $session) {
        try {
            r2_abort_multipart_upload($session['video_storage_key'], $session['multipart_upload_id']);
            if ($session['cover_storage_key'] !== null) {
                r2_delete_object($session['cover_storage_key']);
            }
            $delete = $pdo->prepare('DELETE FROM video_upload_sessions WHERE video_id = :video_id');
            $delete->execute(['video_id' => $session['video_id']]);
        } catch (Throwable $exception) {
            error_log('Could not clean up expired R2 upload ' . $session['video_id'] . ': ' . $exception->getMessage());
        }
    }
}

function read_json_request(): array
{
    $body = file_get_contents('php://input');
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('The upload request is empty.');
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The upload request is invalid.');
    }
    if (!is_array($payload)) {
        throw new RuntimeException('The upload request is invalid.');
    }
    return $payload;
}

function inspect_uploaded_file($file, int $maximum_size, array $allowed_types, string $invalid_message, string $kind): ?array
{
    if ($file === null || !is_array($file)) {
        return null;
    }

    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE && $kind === 'cover') {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The upload is larger than the server limit.',
            UPLOAD_ERR_FORM_SIZE => 'The upload is larger than the allowed limit.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a video to share first.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload.',
            UPLOAD_ERR_EXTENSION => 'The server stopped this upload.',
        ];
        throw new RuntimeException($messages[$error] ?? 'The upload could not be completed.');
    }

    if (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The uploaded file could not be verified.');
    }
    if ($file['size'] < 1 || $file['size'] > $maximum_size) {
        $limit = $kind === 'video' ? '150 MB' : '5 MB';
        throw new RuntimeException('Choose a ' . $kind . ' smaller than ' . $limit . '.');
    }

    $file_info = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $file_info->file($file['tmp_name']);
    if (!is_string($mime_type) || !isset($allowed_types[$mime_type])) {
        throw new RuntimeException($invalid_message);
    }

    return [
        'tmp_name' => $file['tmp_name'],
        'mime_type' => $mime_type,
        'extension' => $allowed_types[$mime_type],
    ];
}

function delete_video(): void
{
    $id = $_POST['id'] ?? '';
    $delete_token = $_POST['delete_token'] ?? '';
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) {
        send_json(['success' => false, 'error' => 'That video could not be found.'], 404);
    }
    if (!is_string($delete_token) || !preg_match('/\A[a-f0-9]{64}\z/', $delete_token)) {
        send_json(['success' => false, 'error' => 'This browser does not have permission to delete that video.'], 403);
    }

    $pdo = database_connection();
    $statement = $pdo->prepare(
        'SELECT id, video_filename, video_mime_type, cover_filename, cover_mime_type,
                video_storage_key, cover_storage_key, delete_token_hash
         FROM videos
         WHERE id = :id AND is_sample = 0'
    );
    $statement->execute(['id' => $id]);
    $video = $statement->fetch();
    if ($video === false) {
        send_json(['success' => false, 'error' => 'That video could not be found.'], 404);
    }
    if (!is_string($video['delete_token_hash']) || !hash_equals($video['delete_token_hash'], hash('sha256', $delete_token))) {
        send_json(['success' => false, 'error' => 'Only the browser that uploaded this video can delete it.'], 403);
    }

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM videos WHERE id = :id AND delete_token_hash = :token_hash AND is_sample = 0');
        $delete->execute([
            'id' => $id,
            'token_hash' => $video['delete_token_hash'],
        ]);
        if ($delete->rowCount() !== 1) {
            $pdo->rollBack();
            send_json(['success' => false, 'error' => 'That video could not be found.'], 404);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    remove_media_file($id, $video['video_filename'], $video['video_mime_type'], ALLOWED_VIDEO_TYPES);
    remove_media_file($id, $video['cover_filename'], $video['cover_mime_type'], ALLOWED_COVER_TYPES);
    remove_r2_object($video['video_storage_key']);
    remove_r2_object($video['cover_storage_key']);
    send_json(['success' => true]);
}

function stream_media(string $kind): void
{
    $id = $_GET['id'] ?? '';
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) {
        send_json(['success' => false, 'error' => 'That media could not be found.'], 404);
    }

    $statement = database_connection()->prepare(
        'SELECT id, video_filename, video_mime_type, cover_filename, cover_mime_type,
                video_storage_key, cover_storage_key
         FROM videos
         WHERE id = :id AND is_sample = 0'
    );
    $statement->execute(['id' => $id]);
    $video = $statement->fetch();
    if ($video === false) {
        send_json(['success' => false, 'error' => 'That media could not be found.'], 404);
    }

    if ($kind === 'video') {
        $filename = $video['video_filename'];
        $mime_type = $video['video_mime_type'];
        $storage_key = $video['video_storage_key'];
        $allowed_types = ALLOWED_VIDEO_TYPES;
    } else {
        $filename = $video['cover_filename'];
        $mime_type = $video['cover_mime_type'];
        $storage_key = $video['cover_storage_key'];
        $allowed_types = ALLOWED_COVER_TYPES;
    }

    if (is_string($storage_key) && $storage_key !== '') {
        $expected_prefix = $kind === 'video' ? 'videos/' : 'covers/';
        $expected_extension = is_string($mime_type) ? ($allowed_types[$mime_type] ?? null) : null;
        if ($expected_extension === null || $storage_key !== $expected_prefix . $id . '.' . $expected_extension) {
            send_json(['success' => false, 'error' => 'That media could not be found.'], 404);
        }

        require_once __DIR__ . DIRECTORY_SEPARATOR . 'r2_storage.php';
        header('Location: ' . r2_presign_read($storage_key, $mime_type), true, 302);
        exit;
    }

    if (!is_string($filename) || !is_string($mime_type) || !isset($allowed_types[$mime_type])) {
        send_json(['success' => false, 'error' => 'That media could not be found.'], 404);
    }

    $expected_filename = $id . '.' . $allowed_types[$mime_type];
    if ($filename !== $expected_filename) {
        send_json(['success' => false, 'error' => 'That media could not be found.'], 404);
    }
    $path = uploads_directory() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path) || !is_readable($path)) {
        send_json(['success' => false, 'error' => 'That media is not available right now.'], 404);
    }

    stream_file($path, $mime_type, $kind === 'video');
}

function stream_file(string $path, string $mime_type, bool $supports_ranges): void
{
    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new RuntimeException('The media file could not be read.');
    }

    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = $supports_ranges ? ($_SERVER['HTTP_RANGE'] ?? '') : '';
    if ($range !== '') {
        if (!preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            header('Content-Range: bytes */' . $size);
            send_json(['success' => false, 'error' => 'The requested video range is invalid.'], 416);
        }
        if ($matches[1] === '') {
            $length = min((int) $matches[2], $size);
            $start = $size - $length;
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $end : min((int) $matches[2], $end);
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            send_json(['success' => false, 'error' => 'The requested video range is not available.'], 416);
        }
        $status = 206;
    }

    http_response_code($status);
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . ($end - $start + 1));
    header('Cache-Control: public, max-age=3600');
    if ($supports_ranges) {
        header('Accept-Ranges: bytes');
    }
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The media file could not be read.');
    }
    try {
        if ($start > 0 && fseek($handle, $start) !== 0) {
            throw new RuntimeException('The video could not be read.');
        }
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    } finally {
        fclose($handle);
    }
    exit;
}

function remove_media_file(string $id, ?string $filename, ?string $mime_type, array $allowed_types): void
{
    if ($filename === null || $mime_type === null || !isset($allowed_types[$mime_type]) || $filename !== $id . '.' . $allowed_types[$mime_type]) {
        return;
    }

    $path = uploads_directory() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path) && !unlink($path)) {
        error_log('Could not remove deleted video media: ' . $filename);
    }
}

function remove_r2_object(?string $key): void
{
    if ($key === null || $key === '') {
        return;
    }

    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'r2_storage.php';
        r2_delete_object($key);
    } catch (Throwable $exception) {
        error_log('Could not remove deleted R2 media object: ' . $exception->getMessage());
    }
}

function clean_text($value, int $maximum_length): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim(strip_tags($value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maximum_length, 'UTF-8');
    }
    return substr($value, 0, $maximum_length);
}

function send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
