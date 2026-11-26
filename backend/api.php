<?php
declare(strict_types=1);

const MAX_VIDEO_BYTES = 157286400;
const MAX_COVER_BYTES = 5242880;
const ALLOWED_VIDEO_TYPES = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
];
const ALLOWED_COVER_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

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
        send_json(['success' => true, 'videos' => list_videos()]);
    }

    if ($action === 'upload' && $method === 'POST') {
        upload_video();
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

    if (in_array($action, ['list', 'upload', 'video', 'thumbnail', 'delete'], true)) {
        header('Allow: ' . (in_array($action, ['upload', 'delete'], true) ? 'POST' : 'GET'));
        send_json(['success' => false, 'error' => 'That request method is not allowed.'], 405);
    }

    send_json(['success' => false, 'error' => 'That request is not available.'], 404);
} catch (PDOException $exception) {
    error_log('Video database error: ' . $exception->getMessage());
    send_json([
        'success' => false,
        'error' => 'The video database is unavailable. Import data/database.sql and check the database settings.',
    ], 503);
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

function list_videos(): array
{
    $query = database_connection()->query(
        "SELECT id, title, creator, description,
                cover_filename IS NOT NULL AS has_cover,
                DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS created_at
         FROM videos
         WHERE is_sample = 0 AND video_filename IS NOT NULL
         ORDER BY created_at DESC
         LIMIT 200"
    );

    $videos = $query->fetchAll();
    foreach ($videos as &$video) {
        $video['has_cover'] = (bool) $video['has_cover'];
    }
    unset($video);

    return $videos;
}

function upload_video(): void
{
    $video = inspect_uploaded_file(
        $_FILES['video'] ?? null,
        MAX_VIDEO_BYTES,
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
        'SELECT id, video_filename, video_mime_type, cover_filename, cover_mime_type, delete_token_hash
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
    send_json(['success' => true]);
}

function stream_media(string $kind): void
{
    $id = $_GET['id'] ?? '';
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) {
        send_json(['success' => false, 'error' => 'That media could not be found.'], 404);
    }

    $statement = database_connection()->prepare(
        'SELECT id, video_filename, video_mime_type, cover_filename, cover_mime_type
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
        $allowed_types = ALLOWED_VIDEO_TYPES;
    } else {
        $filename = $video['cover_filename'];
        $mime_type = $video['cover_mime_type'];
        $allowed_types = ALLOWED_COVER_TYPES;
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
