<?php

declare(strict_types=1);

/**
 * eSewa success callback – verify payment and assign ticket.
 *
 * eSewa redirects here with: ?data=<base64-encoded JSON>
 */

session_start();

require_once __DIR__ . '/includes/functions.php';

$encodedData = trim($_GET['data'] ?? '');
$error = '';

if ($encodedData === '') {
    safeRedirect('buy.php?error=' . urlencode('No payment data received from eSewa.'));
}

if (!isEsewaConfigured()) {
    safeRedirect('buy.php?error=' . urlencode('eSewa is not configured.'));
}

try {
    $decodedJson = base64_decode($encodedData, true);
    if ($decodedJson === false) {
        throw new RuntimeException('Invalid Base64 data from eSewa.');
    }

    $callbackData = json_decode($decodedJson, true);
    if (!is_array($callbackData)) {
        throw new RuntimeException('Invalid JSON from eSewa.');
    }

    $secretKey = trim(env('ESEWA_SECRET_KEY', '') ?? '');
    if (!verifyEsewaResponseSignature($callbackData, $secretKey)) {
        auditLog('ESEWA', 'Callback signature verification failed for ' . ($callbackData['transaction_uuid'] ?? 'unknown'));
        throw new RuntimeException('Payment signature verification failed.');
    }

    $db = getDb();
    $result = fulfillEsewaPayment($db, $callbackData);

    if (!$result['success'] || $result['ticket_id'] === null) {
        throw new RuntimeException($result['message'] ?? 'Could not assign ticket.');
    }

    $customerEmail = $result['email'];
    $ticketId = $result['ticket_id'];
    $eventName = $result['event_name'] ?? 'Event';
    $tierName = $result['tier_name'] ?? '';

    if (($result['message'] ?? '') === 'Ticket assigned.') {
        sendTicketPurchaseEmail($customerEmail, $ticketId, $eventName, $tierName);
    }

    $customer = getCustomerByEmail($db, $customerEmail);
    if ($customer !== null) {
        establishCustomerSession($customer);
    }

    auditLog('ESEWA', "Payment complete – ticket {$ticketId} for {$customerEmail}");

    safeRedirect('thankyou.php?ticket=' . urlencode($ticketId) . '&gateway=esewa');
} catch (Throwable $e) {
    auditLog('ESEWA', 'Callback error: ' . $e->getMessage());
    $errorMsg = env('APP_DEBUG', '0') === '1' ? $e->getMessage() : 'Payment verification failed.';
    safeRedirect('buy.php?error=' . urlencode($errorMsg));
}
