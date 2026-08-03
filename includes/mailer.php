<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Whether to skip SMTP and write magic links to dev_login.log instead.
 */
function shouldUseDevMailFallback(): bool
{
    $mailHost = trim(env('MAIL_HOST', '') ?? '');
    $appDebug = env('APP_DEBUG', '0') === '1';

    return $mailHost === '' || $appDebug;
}

/**
 * Write a magic login link to the local dev log (never used in production).
 */
function writeDevLoginLink(string $email, string $link): void
{
    $devLog = __DIR__ . '/../dev_login.log';
    $line = '[' . date('Y-m-d H:i:s') . "] {$email}\n{$link}\n\n";
    file_put_contents($devLog, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Send an email via SMTP using PHPMailer.
 *
 * @return array{success: bool, error: string|null}
 */
function sendSmtpEmail(
    string $to,
    string $subject,
    string $textBody,
    string $htmlBody
): array {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = env('MAIL_HOST', '');
        $mail->Port = (int) env('MAIL_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = env('MAIL_USERNAME', '');
        $mail->Password = env('MAIL_PASSWORD', '');

        $encryption = strtolower(trim(env('MAIL_ENCRYPTION', 'tls') ?? 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $from = env('MAIL_FROM', 'noreply@passgate.local');
        $fromName = env('MAIL_FROM_NAME', 'PassGate');

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 10;
        $mail->SMTPKeepAlive = false;

        $mail->send();

        return ['success' => true, 'error' => null];
    } catch (MailerException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
