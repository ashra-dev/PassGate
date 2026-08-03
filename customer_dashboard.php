<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

requireCustomerAuth();

$db = getDb();
ensureCustomerSchema($db);
$customerId = (int) $_SESSION['customer_id'];
$tickets = getCustomerTicketsWithDetails($db, $customerId);
$customerName = $_SESSION['customer_name'] ?? '';
$customerEmail = $_SESSION['customer_email'] ?? '';

$showNewBanner = isset($_GET['new']);
$highlightTicket = trim($_GET['ticket'] ?? '');
$hasHighlight = $highlightTicket !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – My Tickets'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('account'); ?>
<div class="pg-shop-wrap">
    <div class="pg-shop-top">
        <div>
            <p class="pg-eyebrow" style="margin:0;">Your tickets</p>
            <h1>My Tickets</h1>
            <p class="pg-muted" style="margin:0.3rem 0 0;font-size:0.84rem;">
                <?php echo htmlspecialchars($customerName ?: $customerEmail); ?>
            </p>
        </div>
        <nav class="pg-shop-nav">
            <a class="pg-btn pg-btn--ghost pg-btn--sm" href="customer_logout.php">Logout</a>
        </nav>
    </div>

    <?php if ($showNewBanner): ?>
        <div class="pg-notice pg-notice--ok">
            <strong>Payment successful!</strong> Your ticket is ready — show the QR at the gate.
        </div>
    <?php endif; ?>

    <div class="pg-stat-pill">
        <span class="pg-muted" style="font-size:0.84rem;font-weight:700;">Total tickets</span>
        <strong><?php echo count($tickets); ?></strong>
    </div>

    <?php if ($tickets === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-ticket"></i></div>
            <h3>No tickets yet</h3>
            <p>Buy a ticket online and it will show up here with a QR code.</p>
            <a href="buy.php" class="pg-btn pg-btn--gold">Browse events</a>
        </div>
    <?php else: ?>
        <?php foreach ($tickets as $ticket): ?>
            <?php
              $isNew = $highlightTicket !== '' && $ticket['id'] === $highlightTicket;
              $qrUrl = 'qr.php?id=' . urlencode($ticket['id']);
              $downloadUrl = $qrUrl . '&download=1';
              $gateway = trim((string) ($ticket['payment_gateway'] ?? ''));
            ?>
            <article class="pg-ticket-card <?php echo $isNew ? 'is-new' : ''; ?>">
                <div class="pg-ticket-card__top">
                    <div>
                        <h2><?php echo htmlspecialchars($ticket['event_name']); ?></h2>
                        <p class="pg-muted" style="margin:0.25rem 0 0;font-size:0.84rem;">
                            <?php echo htmlspecialchars($ticket['tier_name']); ?>
                            · #<?php echo (int) $ticket['physical_number']; ?>
                            <?php if ($gateway !== ''): ?>
                                · <?php echo htmlspecialchars(ucfirst($gateway)); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <code><?php echo htmlspecialchars($ticket['id']); ?></code>
                </div>

                <div class="pg-ticket-qr">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>"
                         alt="QR for <?php echo htmlspecialchars($ticket['id']); ?>"
                         width="144" height="144">
                    <div style="flex:1;min-width:12rem;">
                        <p class="pg-muted" style="margin:0 0 0.75rem;font-size:0.84rem;">
                            Scan at entry. Download to save on your phone.
                        </p>
                        <div style="display:flex;flex-wrap:wrap;gap:0.45rem;">
                            <button type="button" class="pg-btn pg-btn--process pg-btn--sm"
                                    onclick="openQrModal(<?php echo json_encode($ticket['id']); ?>)">
                                <i class="fa-solid fa-expand"></i> Enlarge
                            </button>
                            <a class="pg-btn pg-btn--gold pg-btn--sm" href="<?php echo htmlspecialchars($downloadUrl); ?>">
                                <i class="fa-solid fa-download"></i> Download QR
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (!empty($ticket['benefits'])): ?>
                    <div style="margin-top:1rem;">
                        <div class="pg-benefit-chips">
                            <?php foreach ($ticket['benefits'] as $benefit):
                                $used = (int) $benefit['used'];
                                $max = (int) $benefit['max_uses'];
                                $chipClass = '';
                                if ($used > 0 && $used < $max) {
                                    $chipClass = 'is-ok';
                                } elseif ($used >= $max && $max > 0) {
                                    $chipClass = 'is-full';
                                }
                            ?>
                                <span class="pg-chip <?php echo $chipClass; ?>">
                                    <?php echo htmlspecialchars(trim($benefit['name'])); ?>
                                    <?php echo $used; ?>/<?php echo $max; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="qr-modal" class="pg-modal-scrim" role="dialog" aria-modal="true">
    <div class="pg-modal-card" style="text-align:center;max-width:22rem;">
        <h3>Ticket QR</h3>
        <img id="qr-image" src="" alt="QR code" style="margin:1rem auto;width:17rem;height:17rem;border:1px solid #e2e8f0;border-radius:0.85rem;background:#fff;padding:0.4rem;">
        <p id="qr-ticket-id" class="pg-muted" style="font-family:var(--pg-mono);font-size:0.78rem;word-break:break-all;"></p>
        <a id="qr-download" href="#" class="pg-btn pg-btn--gold" style="width:100%;margin-top:0.5rem;">
            <i class="fa-solid fa-download"></i> Download
        </a>
        <button type="button" class="pg-btn pg-btn--ghost" style="width:100%;margin-top:0.45rem;" onclick="closeQrModal()">Close</button>
    </div>
</div>

<script>
function openQrModal(ticketId) {
  const url = 'qr.php?id=' + encodeURIComponent(ticketId);
  document.getElementById('qr-image').src = url;
  document.getElementById('qr-ticket-id').textContent = ticketId;
  document.getElementById('qr-download').href = url + '&download=1';
  document.getElementById('qr-modal').classList.add('is-open');
}
function closeQrModal() {
  document.getElementById('qr-modal').classList.remove('is-open');
}
<?php if ($hasHighlight): ?>
document.querySelector('.pg-ticket-card.is-new')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
<?php endif; ?>
</script>
</body>
</html>
