<?php
declare(strict_types=1);

function r2_client(): \Aws\S3\S3Client
{
    static $client = null;
    if ($client instanceof \Aws\S3\S3Client) {
        return $client;
    }

    $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoload)) {
        throw new ServiceUnavailableException('Install the PHP dependencies with Composer before enabling R2 storage.');
    }
    require_once $autoload;

    $account_id = getenv('R2_ACCOUNT_ID') ?: '';
    $access_key = getenv('R2_ACCESS_KEY_ID') ?: '';
    $secret_key = getenv('R2_SECRET_ACCESS_KEY') ?: '';
    $bucket = getenv('R2_BUCKET_NAME') ?: '';
    if (!preg_match('/\A[a-f0-9]{32}\z/i', $account_id) || $access_key === '' || $secret_key === '' || $bucket === '') {
        throw new ServiceUnavailableException('R2 storage is not configured on the PHP host.');
    }

    $client = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region' => 'auto',
        'endpoint' => 'https://' . $account_id . '.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => $access_key,
            'secret' => $secret_key,
        ],
    ]);

    return $client;
}

function r2_bucket_name(): string
{
    $bucket = getenv('R2_BUCKET_NAME') ?: '';
    if ($bucket === '') {
        throw new ServiceUnavailableException('R2 storage is not configured on the PHP host.');
    }
    return $bucket;
}

function r2_create_multipart_upload(string $key, string $mime_type): string
{
    $result = r2_client()->createMultipartUpload([
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
        'ContentType' => $mime_type,
    ]);

    $upload_id = $result->get('UploadId');
    if (!is_string($upload_id) || $upload_id === '') {
        throw new RuntimeException('R2 did not create the video upload session.');
    }

    return $upload_id;
}

function r2_presign_upload_part(string $key, string $upload_id, int $part_number): string
{
    $command = r2_client()->getCommand('UploadPart', [
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
        'UploadId' => $upload_id,
        'PartNumber' => $part_number,
    ]);
    $request = r2_client()->createPresignedRequest($command, '+30 minutes');

    return (string) $request->getUri();
}

function r2_presign_cover_upload(string $key, string $mime_type): string
{
    $command = r2_client()->getCommand('PutObject', [
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
        'ContentType' => $mime_type,
    ]);
    $request = r2_client()->createPresignedRequest($command, '+30 minutes');

    return (string) $request->getUri();
}

function r2_complete_multipart_upload(string $key, string $upload_id, array $parts): void
{
    r2_client()->completeMultipartUpload([
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
        'UploadId' => $upload_id,
        'MultipartUpload' => ['Parts' => $parts],
    ]);
}

function r2_abort_multipart_upload(string $key, string $upload_id): void
{
    r2_client()->abortMultipartUpload([
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
        'UploadId' => $upload_id,
    ]);
}

function r2_delete_object(string $key): void
{
    r2_client()->deleteObject([
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
    ]);
}

function r2_presign_read(string $key, string $mime_type): string
{
    $command = r2_client()->getCommand('GetObject', [
        'Bucket' => r2_bucket_name(),
        'Key' => $key,
        'ResponseContentType' => $mime_type,
        'ResponseCacheControl' => 'private, max-age=300',
    ]);
    $request = r2_client()->createPresignedRequest($command, '+15 minutes');

    return (string) $request->getUri();
}
