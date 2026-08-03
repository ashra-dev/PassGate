<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$ticketId = trim($_GET['ticket'] ?? '');
$gateway = trim($_GET['gateway'] ?? 'online');
$isLoggedIn = isCustomerAuthenticated();

if ($ticketId !== '' && $isLoggedIn) {
    safeRedirect('customer_dashboard.php?new=1&ticket=' . urlencode($ticketId));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Thank You'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('buy'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Buy tickets', 'href' => 'buy.php'],
        ['label' => 'Thank you'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth" style="text-align:center;">
            <div class="pg-brand-mark" style="margin:0 auto 0.85rem;background:linear-gradient(145deg,#34d399,#0ca678);">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h1 class="pg-brand" style="font-size:1.55rem;margin:0;">Payment successful</h1>
            <p class="pg-muted" style="margin:0.75rem 0 0;font-size:0.9rem;line-height:1.45;">
                Your ticket is ready<?php echo $gateway === 'esewa' ? ' via eSewa' : ($gateway === 'dev' ? ' (test purchase)' : ''); ?>.
            </p>
            <?php if ($ticketId !== ''): ?>
                <p style="margin:1rem 0 0;"><code><?php echo htmlspecialchars($ticketId); ?></code></p>
            <?php endif; ?>
            <div style="margin-top:1.25rem;display:grid;gap:0.55rem;">
                <?php if ($isLoggedIn): ?>
                    <a class="pg-btn pg-btn--gold" href="customer_dashboard.php<?php echo $ticketId !== '' ? '?new=1&ticket=' . urlencode($ticketId) : ''; ?>">View my tickets</a>
                <?php else: ?>
                    <a class="pg-btn pg-btn--gold" href="customer_login.php?next=customer_dashboard.php">Log in to view tickets</a>
                <?php endif; ?>
                <a class="pg-btn pg-btn--ghost" href="buy.php">Buy more tickets</a>
            </div>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
