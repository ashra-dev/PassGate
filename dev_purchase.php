<?php

declare(strict_types=1);

/**
 * Local debug checkout – assigns a vault ticket without Stripe/eSewa.
 * Only available when APP_DEBUG=1.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!isDevCheckoutEnabled()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Dev checkout is disabled.']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
$eventId = (int) ($input['event_id'] ?? 0);
$tierId = (int) ($input['tier_id'] ?? 0);
$quantityRaw = $input['quantity'] ?? 1;

if (!isCustomerAuthenticated()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Please log in before buying.']);
    exit;
}

$email = strtolower(trim((string) ($_SESSION['customer_email'] ?? '')));

if ($eventId <= 0 || $tierId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid event, tier, or email.']);
    exit;
}

try {
    $db = getDb();
    ensureCustomerSchema($db);

    $available = countAvailableTicketsForTier($db, $tierId, $eventId);
    if ($available <= 0) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Sold out.']);
        exit;
    }

    try {
        $quantity = parsePurchaseQuantity($quantityRaw, $available);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }

    $paymentId = 'DEV-' . bin2hex(random_bytes(8));
    $result = assignMultipleTickets($db, $eventId, $tierId, $email, 'dev', $paymentId, $quantity, $paymentId);

    if (!$result['success'] || ($result['ticket_ids'] ?? []) === []) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => $result['message'] ?? 'Could not assign ticket.']);
        exit;
    }

    $ticketIds = $result['ticket_ids'];
    $ticketId = $result['ticket_id'];
    $eventName = $result['event_name'] ?? 'Event';
    $tierName = $result['tier_name'] ?? '';

    if (shouldSendPurchaseEmail((string) ($result['message'] ?? ''))) {
        sendTicketPurchaseEmail($email, $ticketIds, $eventName, $tierName);
    }

    $customer = getCustomerByEmail($db, $email);
    if ($customer !== null) {
        establishCustomerSession($customer);
    }

    auditLog('DEV-PAY', 'Simulated purchase – ' . count($ticketIds) . " ticket(s) for {$email}");

    echo json_encode([
        'status' => 'success',
        'url'    => 'customer_dashboard.php?new=1&count=' . count($ticketIds),
    ]);
} catch (Throwable $e) {
    auditLog('DEV-PAY', 'Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
