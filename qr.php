<?php

declare(strict_types=1);

/**
 * Output a PNG QR code for a ticket ID.
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
    header('Content-Type: text/plain');
    echo 'Missing ticket id.';
    exit;
}

// If logged in as customer, only allow QR for own tickets
if (!empty($_SESSION['customer_authenticated']) && !empty($_SESSION['customer_id'])) {
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT 1 FROM tickets t
         JOIN customer_tickets ct ON ct.ticket_id = t.id
         WHERE t.id = :ticket_id AND ct.customer_id = :customer_id'
    );
    $stmt->execute([
        'ticket_id'   => $ticketId,
        'customer_id' => (int) $_SESSION['customer_id'],
    ]);
    if ($stmt->fetch() === false) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Access denied.';
        exit;
    }
}

$options = new QROptions([
    'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
    'scale'        => 6,
    'imageBase64'  => false,
]);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');

if ($download) {
    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $ticketId) ?: 'ticket';
    header('Content-Disposition: attachment; filename="' . $safeName . '-qr.png"');
}

echo (new QRCode($options))->render($ticketId);
