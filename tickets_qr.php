<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (
    !isset($_SESSION['distributor_authenticated'])
    || $_SESSION['distributor_authenticated'] !== true
    || ($_SESSION['distributor_role'] ?? '') !== 'admin'
) {
    safeRedirect('index.php');
}

$db = getDb();

if (!hasAnyEvents($db)) {
    safeRedirect('setup.php');
}

$eventId = resolveAdminEventId($db);
$currentEvent = getEventById($db, $eventId);
$tiers = getEventTiers($db, $eventId);

$filterTierId = isset($_GET['tier_id']) && ctype_digit((string) $_GET['tier_id'])
    ? (int) $_GET['tier_id']
    : null;

$ticketsByTier = getTicketsGroupedByTier($db, $eventId, $filterTierId);
$totalTickets = array_sum(array_map('count', $ticketsByTier));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    passgateRenderHead('PassGate – QR Codes', [
        'extra' => <<<'HTML'
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
@media print {
  .no-print { display: none !important; }
  .tier-section { break-inside: avoid-page; page-break-inside: avoid; }
  .qr-card { break-inside: avoid; page-break-inside: avoid; background: #fff !important; color: #111 !important; border-color: #ddd !important; }
  body.pg-admin { background: #fff !important; }
  .pg-admin-wrap { max-width: none; padding: 0; }
}
.qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.85rem; }
.qr-card {
  text-align: center;
  padding: 0.85rem;
  border-radius: var(--pg-radius-sm);
  border: 1px solid var(--pg-border);
  background: #fff;
}
.qr-card .qr-target { min-height: 128px; display: flex; align-items: center; justify-content: center; }
.qr-card canvas, .qr-card img { margin: 0 auto; background: #fff; border-radius: 0.35rem; padding: 0.25rem; }
</style>
HTML
    ]);
    ?>
</head>
<body class="pg-body pg-admin">
<div class="pg-admin-wrap">
    <div class="no-print event-bar" style="margin-bottom:1.25rem;">
        <div class="pg-admin-brand">
            <div class="pg-brand-mark" style="width:2.2rem;height:2.2rem;border-radius:0.7rem;font-size:0.85rem;">
                <i class="fa-solid fa-qrcode"></i>
            </div>
            <div>
                <p class="pg-eyebrow" style="margin:0;">Print sheets</p>
                <h1 style="margin:0;font-family:var(--pg-display);font-size:1.35rem;">Ticket QR Codes</h1>
                <p style="margin:0.2rem 0 0;font-size:0.8rem;color:var(--pg-text-muted);">
                    <?php echo htmlspecialchars($currentEvent['name'] ?? 'Event'); ?>
                    · <?php echo (int) $totalTickets; ?> ticket(s)
                </p>
            </div>
        </div>
        <div class="pg-admin-actions">
            <form method="GET" style="display:flex;align-items:center;gap:0.5rem;">
                <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                <label class="pg-label" style="margin:0;" for="tier_id">Tier</label>
                <select name="tier_id" id="tier_id" onchange="this.form.submit()">
                    <option value="">All tiers</option>
                    <?php foreach ($tiers as $tier): ?>
                        <option value="<?php echo (int) $tier['id']; ?>" <?php echo $filterTierId === (int) $tier['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tier['name']); ?> (<?php echo (int) $tier['quantity']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button type="button" onclick="window.print()" class="btn btn-primary">Print / Save PDF</button>
            <a href="distributors.php?<?php echo adminEventQuery($eventId, 'events'); ?>" class="btn btn-ghost">Back to Admin</a>
        </div>
    </div>

    <?php if ($ticketsByTier === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-qrcode"></i></div>
            <h3>No tickets yet</h3>
            <p>Create an event with tiers first, then print QR sheets from here.</p>
            <a href="setup.php" class="btn btn-primary">Create event</a>
        </div>
    <?php else: ?>
        <?php foreach ($ticketsByTier as $tierName => $tickets): ?>
            <section class="tier-section pg-card" style="padding:1.15rem;margin-bottom:1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;padding-bottom:0.65rem;border-bottom:1px solid var(--pg-border);">
                    <h2 style="margin:0;font-family:var(--pg-display);font-size:1.15rem;"><?php echo htmlspecialchars($tierName); ?></h2>
                    <span class="event-badge"><?php echo count($tickets); ?> tickets</span>
                </div>
                <div class="qr-grid">
                    <?php foreach ($tickets as $ticket): ?>
                        <div class="qr-card">
                            <div class="qr-target" data-ticket-id="<?php echo htmlspecialchars($ticket['id'], ENT_QUOTES); ?>"></div>
                            <div class="pg-mono" style="font-size:0.68rem;font-weight:700;word-break:break-all;margin-top:0.45rem;">
                                <?php echo htmlspecialchars($ticket['id']); ?>
                            </div>
                            <div style="font-size:0.65rem;color:var(--pg-text-faint);margin-top:0.25rem;">
                                #<?php echo (int) $ticket['physical_number']; ?>
                                · <?php echo formatPrice((float) $ticket['price']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('.qr-target').forEach((el) => {
        const ticketId = el.dataset.ticketId;
        if (!ticketId) return;
        el.innerHTML = '';
        new QRCode(el, {
            text: ticketId,
            width: 120,
            height: 120,
            colorDark: '#0B1220',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M,
        });
    });
</script>
</body>
</html>
