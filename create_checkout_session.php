<?php

declare(strict_types=1);

// API endpoint – never append PHP warnings to JSON output
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

$stripe = getStripeClient();
if ($stripe === null) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Payment system not configured.']);
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

    $appUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
    $currency = getStripeCurrency();
    $unitAmount = (int) round((float) $tier['price'] * 100);

    if ($unitAmount < 50) {
        $unitAmount = 50; // Stripe minimum
    }

    $session = $stripe->checkout->sessions->create([
        'mode'                => 'payment',
        'customer_email'      => $email,
        'client_reference_id' => "{$eventId}:{$tierId}",
        'metadata'            => [
            'event_id' => (string) $eventId,
            'tier_id'  => (string) $tierId,
            'email'    => $email,
        ],
        'line_items'          => [[
            'quantity'   => 1,
            'price_data' => [
                'currency'     => $currency,
                'unit_amount'  => $unitAmount,
                'product_data' => [
                    'name'        => $tier['event_name'] . ' – ' . $tier['name'],
                    'description' => 'PassGate event ticket',
                ],
            ],
        ]],
        'success_url' => $appUrl . '/purchase_success.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $appUrl . '/buy.php?error=' . urlencode('Payment cancelled'),
    ]);

    auditLog('STRIPE', "Checkout session {$session->id} for {$email} event {$eventId} tier {$tierId}");

    echo json_encode(['status' => 'success', 'url' => $session->url]);
} catch (Throwable $e) {
    auditLog('STRIPE', 'Checkout error: ' . $e->getMessage());
    http_response_code(500);
    $message = 'Unable to start checkout.';
    if (env('APP_DEBUG', '0') === '1') {
        $message = $e->getMessage();
    } elseif (str_contains($e->getMessage(), 'Invalid API Key')) {
        $message = 'Stripe API keys are invalid. Add real test keys from dashboard.stripe.com to your .env file.';
    }
    echo json_encode(['status' => 'error', 'message' => $message]);
}
