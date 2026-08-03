<?php

declare(strict_types=1);

/**
 * Build signed eSewa payment form payload (JSON API for buy.php).
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

$input = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
$eventId = (int) ($input['event_id'] ?? 0);
$tierId = (int) ($input['tier_id'] ?? 0);
$email = strtolower(trim($input['email'] ?? ''));

if ($eventId <= 0 || $tierId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid event, tier, or email.']);
    exit;
}

if (!isEsewaConfigured()) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'eSewa is not configured.']);
    exit;
}

try {
    $db = getDb();
    ensureCustomerSchema($db);

    $tierStmt = $db->prepare(
        'SELECT ti.*, e.name AS event_name FROM tiers ti JOIN events e ON e.id = ti.event_id
         WHERE ti.id = :tier_id AND ti.event_id = :event_id'
    );
    $tierStmt->execute(['tier_id' => $tierId, 'event_id' => $eventId]);
    $tier = $tierStmt->fetch();

    if (!$tier) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Tier not found.']);
        exit;
    }

    $available = countAvailableTicketsForTier($db, $tierId, $eventId);
    if ($available <= 0) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Sold out.']);
        exit;
    }

    $payload = buildEsewaPaymentForm(
        $eventId,
        $tierId,
        $email,
        (float) $tier['price'],
        (string) $tier['event_name'],
        (string) $tier['name']
    );

    echo json_encode(['status' => 'success', 'esewa_payload' => $payload]);
} catch (Throwable $e) {
    auditLog('ESEWA', 'Initiate error: ' . $e->getMessage());
    http_response_code(500);
    $message = env('APP_DEBUG', '0') === '1' ? $e->getMessage() : 'Unable to start eSewa payment.';
    echo json_encode(['status' => 'error', 'message' => $message]);
}
