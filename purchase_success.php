<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

$sessionId = trim($_GET['session_id'] ?? '');
$error = '';
$ticketId = null;
$eventName = '';
$tierName = '';
$customerEmail = '';

if ($sessionId === '') {
    $error = 'Missing payment session.';
} else {
    $stripe = getStripeClient();
    if ($stripe === null) {
        $error = 'Payment system not configured.';
    } else {
        try {
            $checkoutSession = $stripe->checkout->sessions->retrieve($sessionId);

            if (($checkoutSession->payment_status ?? '') !== 'paid') {
                $error = 'Payment was not completed.';
            } else {
                $db = getDb();
                $result = fulfillStripeCheckoutSession($db, $checkoutSession);

                if (!$result['success'] || $result['ticket_id'] === null) {
                    $error = $result['message'] ?? 'Could not assign ticket.';
                } else {
                    $ticketId = $result['ticket_id'];
                    $eventName = $result['event_name'] ?? '';
                    $tierName = $result['tier_name'] ?? '';
                    $customerEmail = $result['email'] ?? '';

                    // Auto-login so dashboard shows the new ticket immediately
                    $customer = getCustomerByEmail($db, $customerEmail);
                    if ($customer !== null) {
                        establishCustomerSession($customer);
                    }

                    // Email QR (webhook may also send – idempotent enough)
                    if ($eventName !== '') {
                        sendTicketPurchaseEmail($customerEmail, $ticketId, $eventName, $tierName);
                    }

                    safeRedirect('customer_dashboard.php?new=1&ticket=' . urlencode($ticketId));
                }
            }
        } catch (Throwable $e) {
            auditLog('STRIPE', 'Purchase success page error: ' . $e->getMessage());
            $error = env('APP_DEBUG', '0') === '1' ? $e->getMessage() : 'Unable to verify payment.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl p-8 max-w-md w-full text-center space-y-4">
    <div class="text-rose-600 font-semibold"><?php echo htmlspecialchars($error); ?></div>
    <a href="buy.php" class="text-indigo-600 hover:underline">Back to events</a>
  </div>
</body>
</html>
