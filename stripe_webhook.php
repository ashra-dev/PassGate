<?php

declare(strict_types=1);

/**
 * Stripe webhook – assign tickets after successful checkout.
 *
 * Configure in Stripe Dashboard → Webhooks → checkout.session.completed
 * Local testing: stripe listen --forward-to localhost:8000/stripe_webhook.php
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/vendor/autoload.php';

$payload = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = trim(env('STRIPE_WEBHOOK_SECRET', '') ?? '');

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED);

http_response_code(200);
header('Content-Type: application/json');

if ($webhookSecret === '') {
    auditLog('STRIPE', 'Webhook received but STRIPE_WEBHOOK_SECRET not set');
    echo json_encode(['received' => true]);
    exit;
}

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
} catch (Throwable $e) {
    auditLog('STRIPE', 'Webhook signature failed: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

if ($event->type !== 'checkout.session.completed') {
    echo json_encode(['received' => true, 'ignored' => $event->type]);
    exit;
}

/** @var \Stripe\Checkout\Session $session */
$session = $event->data->object;

$eventId = (int) ($session->metadata['event_id'] ?? 0);
$tierId = (int) ($session->metadata['tier_id'] ?? 0);
$email = strtolower(trim($session->metadata['email'] ?? $session->customer_email ?? ''));

if ($eventId <= 0 || $tierId <= 0 || $email === '') {
    // Fallback: client_reference_id as "eventId:tierId"
    if (!empty($session->client_reference_id) && str_contains($session->client_reference_id, ':')) {
        [$eventId, $tierId] = array_map('intval', explode(':', $session->client_reference_id, 2));
    }
}

if ($eventId <= 0 || $tierId <= 0 || $email === '') {
    auditLog('STRIPE', "Webhook missing metadata for session {$session->id}");
    echo json_encode(['received' => true, 'error' => 'missing metadata']);
    exit;
}

$db = getDb();
$result = fulfillStripeCheckoutSession($db, $session);

if ($result['success'] && !empty($result['ticket_ids']) && shouldSendPurchaseEmail((string) ($result['message'] ?? ''))) {
    sendTicketPurchaseEmail(
        $result['email'],
        $result['ticket_ids'],
        (string) ($result['event_name'] ?? 'Event'),
        (string) ($result['tier_name'] ?? '')
    );
}

echo json_encode(['received' => true, 'fulfilled' => $result['success']]);
