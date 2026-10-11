<?php
/**
 * Shared MySQL connection for Vercel PHP runtime.
 * Credentials are read from Vercel Environment Variables.
 */
function db_connect(): mysqli
{
    $host = getenv('DB_HOST');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');
    $name = getenv('DB_NAME');
    $port = (int) (getenv('DB_PORT') ?: 3306);

    if (!$host || !$user || !$name) {
        http_response_code(500);
        die('Database environment variables are not configured.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli($host, $user, $password ?: '', $name, $port);

    if ($conn->connect_error) {
        http_response_code(500);
        die('Database connection failed.');
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}
