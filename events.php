<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$isCustomer = isCustomerAuthenticated();
$events = [];

try {
    $db = getDb();
    $events = getEventsAvailableForPurchase($db);
} catch (Throwable $e) {
    // DB may not be ready
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Upcoming events'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('events'); ?>

    <div class="pg-shop-wrap">
        <div class="pg-shop-top">
            <div>
                <p class="pg-eyebrow" style="margin:0;">Browse</p>
                <h1>Upcoming events</h1>
                <p class="pg-muted" style="margin:0.35rem 0 0;font-size:0.86rem;">
                    Anyone can view these. Sign in when you’re ready to buy.
                </p>
            </div>
            <?php if ($isCustomer): ?>
                <a class="pg-btn pg-btn--gold pg-btn--sm" href="buy.php">Buy tickets</a>
            <?php else: ?>
                <a class="pg-btn pg-btn--gold pg-btn--sm" href="customer_register.php?next=buy.php">Sign up to buy</a>
            <?php endif; ?>
        </div>

        <?php if ($events === []): ?>
            <div class="pg-empty">
                <div class="pg-empty__icon"><i class="fa-solid fa-calendar"></i></div>
                <h3>No events listed yet</h3>
                <p>When an event has tickets in the vault, it will show up here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <section class="pg-event-block">
                    <div class="pg-event-block__head">
                        <h2><?php echo htmlspecialchars($event['name']); ?></h2>
                    </div>
                    <div class="pg-event-block__body">
                        <?php foreach ($event['tiers'] as $tier): ?>
                            <div class="pg-tier-card">
                                <div style="display:flex;justify-content:space-between;gap:0.75rem;align-items:flex-start;flex-wrap:wrap;">
                                    <div>
                                        <h3><?php echo htmlspecialchars($tier['name']); ?></h3>
                                        <p class="pg-tier-price"><?php echo formatPrice($tier['price']); ?></p>
                                        <p class="pg-tier-meta"><?php echo (int) $tier['available']; ?> tickets left</p>
                                    </div>
                                    <span class="pg-empty__icon" style="margin:0;width:2.5rem;height:2.5rem;font-size:1rem;" aria-hidden="true">
                                        <i class="fa-solid fa-ticket"></i>
                                    </span>
                                </div>
                                <?php if ($tier['benefits'] !== []): ?>
                                    <ul class="pg-benefit-list">
                                        <?php foreach ($tier['benefits'] as $benefit): ?>
                                            <li>
                                                <i class="fa-solid fa-check"></i>
                                                <?php echo htmlspecialchars($benefit['name']); ?>
                                                (×<?php echo (int) $benefit['max_uses']; ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
