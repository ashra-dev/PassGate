<?php

declare(strict_types=1);

/**
 * Load key=value pairs from a .env file into $_ENV / putenv().
 */
function loadEnv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");

        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

loadEnv(__DIR__ . '/.env');

// Align PHP timestamps with PostgreSQL session timezone when not set in php.ini
if (env('APP_TIMEZONE')) {
    date_default_timezone_set(env('APP_TIMEZONE'));
} elseif (ini_get('date.timezone') === '' || date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('Asia/Kathmandu');
}

/**
 * Read an environment variable with optional default.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return (string) $value;
}
