<?php

declare(strict_types=1);

/**
 * Output a QR code for a ticket ID (SVG, no GD required).
 * Usage: qr.php?id=TICKET-ID[&download=1]
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

// If logged in as customer, only allow QR for own tickets
if (!empty($_SESSION['customer_authenticated']) && !empty($_SESSION['customer_id'])) {
    $db = getDb();
    ensureCustomerSchema($db);
    $stmt = $db->prepare(
        'SELECT 1 FROM customer_tickets
         WHERE ticket_id = :ticket_id AND customer_id = :customer_id'
    );
    $stmt->execute([
        'ticket_id'   => $ticketId,
        'customer_id' => (int) $_SESSION['customer_id'],
    ]);
    if ($stmt->fetch() === false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access denied.';
        exit;
    }
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
header('Cache-Control: public, max-age=3600');

if ($download) {
    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $ticketId) ?: 'ticket';
    header('Content-Disposition: attachment; filename="' . $safeName . '-qr.svg"');
}

echo $svg;
