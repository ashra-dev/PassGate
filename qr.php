<?php

declare(strict_types=1);

/**
 * Output a QR code for a ticket ID (SVG, no GD required).
 * Usage: qr.php?id=TICKET-ID[&download=1]
 *
 * Requires the logged-in customer to own the ticket.
 */

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$ticketId = trim($_GET['id'] ?? '');
$download = isset($_GET['download']);

if ($ticketId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing ticket id.';
    exit;
}

$db = getDb();
ensureCustomerSchema($db);

if (!isCustomerAuthenticated()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Login required.';
    exit;
}

$customer = getAuthenticatedCustomer($db);
if ($customer === null || !customerOwnsTicket($db, (int) $customer['id'], $ticketId)) {
    auditLog('AUTH', 'Denied QR access for ticket ' . $ticketId);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied.';
    exit;
}

$options = new QROptions([
    'outputType'     => QRCode::OUTPUT_MARKUP_SVG,
    'outputBase64'   => false,
    'imageBase64'    => false,
    'scale'          => 6,
    'svgViewBoxSize' => 256,
]);

$svg = (new QRCode($options))->render($ticketId);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

if ($download) {
    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $ticketId) ?: 'ticket';
    header('Content-Disposition: attachment; filename="' . $safeName . '-qr.svg"');
}

echo $svg;
