<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$sessionId = trim($_GET['session_id'] ?? '');
$error = '';
$ticketId = null;

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

                    $customer = getCustomerByEmail($db, $customerEmail);
                    if ($customer !== null) {
                        establishCustomerSession($customer);
                    }

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
    <?php passgateRenderHead('PassGate – Purchase'); ?>
</head>
<body class="pg-body">
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth" style="text-align:center;">
            <div class="pg-notice pg-notice--error"><?php echo htmlspecialchars($error); ?></div>
            <div style="margin-top:1rem;display:grid;gap:0.55rem;">
                <a class="pg-btn pg-btn--gold" href="buy.php">Back to events</a>
                <a class="pg-btn pg-btn--ghost" href="customer_login.php">Customer login</a>
            </div>
        </div>
    </div>
</body>
</html>
