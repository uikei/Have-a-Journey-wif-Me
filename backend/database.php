<?php
declare(strict_types=1);

function get_database_connection(): PDO
{
    $host = getenv('JOURNEY_DB_HOST') ?: '127.0.0.1';
    $name = getenv('JOURNEY_DB_NAME') ?: 'journey_video_diary';
    $user = getenv('JOURNEY_DB_USER') ?: 'root';
    $password = getenv('JOURNEY_DB_PASSWORD');
    if ($password === false) {
        $password = '';
    }

    $connection = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]
    );

    return $connection;
}
