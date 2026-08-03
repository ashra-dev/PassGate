<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$isCustomer = false;
$customerEmail = '';
try {
    $db = getDb();
    ensureCustomerSchema($db);
    $verifiedCustomer = getAuthenticatedCustomer($db);
    if ($verifiedCustomer !== null) {
        $isCustomer = true;
        $customerEmail = (string) $verifiedCustomer['email'];
    }
} catch (Throwable $e) {
    // DB may not be ready yet
}

$eventCount = 0;
$buyReady = false;
try {
    if (!isset($db)) {
        $db = getDb();
    }
    $events = getEventsAvailableForPurchase($db);
    $buyReady = $events !== [];
    $eventCount = count($events);
} catch (Throwable $e) {
    // DB may not be ready yet
}

if ($isCustomer) {
    $primaryHref = 'buy.php';
    $primaryLabel = 'Buy tickets';
    $secondaryHref = 'customer_dashboard.php';
    $secondaryLabel = 'My tickets';
} else {
    $primaryHref = 'customer_register.php?next=buy.php';
    $primaryLabel = 'Create account';
    $secondaryHref = 'customer_login.php?next=buy.php';
    $secondaryLabel = 'Log in';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Event access'); ?>
</head>
<body class="pg-body pg-landing">
    <?php passgateRenderPublicNav('home'); ?>

    <?php if ($isCustomer && $customerEmail !== ''): ?>
        <div class="pg-notice pg-notice--info" style="max-width:68rem;margin:0 auto 0;padding:0.75rem 1.25rem;">
            Signed in as <strong><?php echo htmlspecialchars($customerEmail); ?></strong>.
            <a href="customer_dashboard.php" style="margin-left:0.35rem;">My tickets</a>
            · <a href="customer_logout.php">Log out</a>
        </div>
    <?php endif; ?>

    <main class="pg-landing-hero">
        <div class="pg-landing-hero__copy">
            <p class="pg-eyebrow" style="margin:0 0 0.75rem;">Event access &amp; tickets</p>
            <h1 class="pg-landing-brand">PassGate</h1>
            <p class="pg-landing-lead">Buy a pass online, then show your QR at the gate.</p>
            <div class="pg-landing-ctas">
                <a class="pg-btn pg-btn--gold" href="<?php echo htmlspecialchars($primaryHref); ?>">
                    <?php echo htmlspecialchars($primaryLabel); ?>
                </a>
                <a class="pg-btn pg-btn--ghost" href="<?php echo htmlspecialchars($secondaryHref); ?>">
                    <?php echo htmlspecialchars($secondaryLabel); ?>
                </a>
                <a class="pg-btn pg-btn--ghost" href="events.php" title="View upcoming events">
                    <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                    View events
                </a>
            </div>
            <?php if ($buyReady): ?>
                <p class="pg-landing-meta"><?php echo (int) $eventCount; ?> event<?php echo $eventCount === 1 ? '' : 's'; ?> available to browse</p>
            <?php endif; ?>
        </div>
        <div class="pg-landing-hero__visual" aria-hidden="true">
            <div class="pg-landing-orb pg-landing-orb--a"></div>
            <div class="pg-landing-orb pg-landing-orb--b"></div>
            <div class="pg-landing-ticket">
                <div class="pg-landing-ticket__code"></div>
                <div class="pg-landing-ticket__lines">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </main>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
