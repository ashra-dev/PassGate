<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Return a shared PDO connection to PostgreSQL.
 */
function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '5432');
    $name = env('DB_NAME', 'passgate');
    $user = env('DB_USER', 'postgres');
    $pass = env('DB_PASSWORD', '');

    $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
