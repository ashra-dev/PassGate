<?php

declare(strict_types=1);

/**
 * CLI helper: send magic-link email without blocking the web request.
 *
 * Usage: php send_mail_async.php user@example.com TOKEN
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php send_mail_async.php <email> <token>\n");
    exit(1);
}

require_once __DIR__ . '/includes/functions.php';

$email = $argv[1];
$token = $argv[2];

$sent = sendMagicLinkEmail($email, $token);
exit($sent ? 0 : 1);
