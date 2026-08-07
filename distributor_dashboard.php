<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$db = getDb();
ensureDistributorsSchema($db);
$distributor = requireDistributorAuth($db);
$distributorId = (string) $distributor['id'];
$distributorName = (string) $distributor['name'];
$distributorEmail = (string) $distributor['email'];

$events = getDistributorEvents($db, $distributorId);
$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($eventId <= 0 && $events !== []) {
    $eventId = (int) $events[0]['id'];
}

$validEvent = false;
foreach ($events as $ev) {
    if ((int) $ev['id'] === $eventId) {
        $validEvent = true;
        break;
    }
}

if (!$validEvent) {
    $eventId = 0;
}

$summary = $eventId > 0
    ? getDistributorEventSummary($db, $distributorId, $eventId)
    : ['total' => 0, 'active' => 0, 'sold_online' => 0, 'scans_used' => 0];
$tickets = $eventId > 0
    ? getDistributorTicketsForEvent($db, $distributorId, $eventId)
    : [];

function distributorTicketBenefits(PDO $db, string $ticketId, int $tierId): array
{
    $stmt = $db->prepare(
        'SELECT b.id, b.name, b.max_uses, COUNT(s.id) AS used
         FROM benefits b
         LEFT JOIN scans s ON s.benefit_id = b.id AND s.ticket_id = :ticket_id
         WHERE b.tier_id = :tier_id
         GROUP BY b.id, b.name, b.max_uses
         ORDER BY b.id'
    );
    $stmt->execute(['ticket_id' => $ticketId, 'tier_id' => $tierId]);

    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Distributor Dashboard'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('staff'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Distributor dashboard'],
    ]); ?>
<div class="pg-shop-wrap">
    <div class="pg-shop-top">
        <div>
            <p class="pg-eyebrow" style="margin:0;">Your inventory</p>
            <h1>Distributor Dashboard</h1>
            <p class="pg-muted" style="margin:0.3rem 0 0;font-size:0.84rem;">
                <?php echo htmlspecialchars($distributorName); ?>
                · <?php echo htmlspecialchars($distributorEmail); ?>
            </p>
        </div>
        <nav class="pg-shop-nav">
            <a class="pg-btn pg-btn--ghost pg-btn--sm" href="logout.php">Log out</a>
        </nav>
    </div>

    <?php if ($events === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-vault"></i></div>
            <h3>No tickets allocated yet</h3>
            <p>When the event admin assigns vault tickets to your company, they will appear here.</p>
        </div>
    <?php else: ?>
        <form method="GET" class="pg-shop-field" style="max-width:420px;margin-bottom:1.25rem;">
            <label for="event_id">Event</label>
            <select name="event_id" id="event_id" onchange="this.form.submit()">
                <?php foreach ($events as $ev): ?>
                    <option value="<?php echo (int) $ev['id']; ?>" <?php echo (int) $ev['id'] === $eventId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['name']); ?> (<?php echo (int) $ev['ticket_count']; ?> tickets)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="summary-grid">
            <div class="summary-card"><h3>Tickets Held</h3><div class="metric"><?php echo (int) $summary['total']; ?></div></div>
            <div class="summary-card"><h3>Still With You</h3><div class="metric"><?php echo (int) $summary['active']; ?></div></div>
            <div class="summary-card"><h3>Sold Online</h3><div class="metric"><?php echo (int) $summary['sold_online']; ?></div></div>
            <div class="summary-card"><h3>Benefit Scans</h3><div class="metric"><?php echo (int) $summary['scans_used']; ?></div></div>
        </div>

        <div class="pg-section-head" style="margin-top:1.25rem;">
            <div>
                <h3>Your tickets</h3>
                <p>Allocated inventory for this event — sold tickets show the buyer when known.</p>
            </div>
        </div>

        <?php if ($tickets === []): ?>
            <div class="pg-empty" style="padding:1.75rem 1rem;">
                <h3>No tickets for this event</h3>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Ticket ID</th>
                        <th>Tier</th>
                        <th>Status</th>
                        <th>Holder</th>
                        <th>Benefits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $row): ?>
                        <?php $benefits = distributorTicketBenefits($db, $row['id'], (int) $row['tier_id']); ?>
                        <tr>
                            <td><?php echo (int) $row['physical_number']; ?></td>
                            <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                            <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                            <td>
                                <?php if (!empty($row['customer_id'])): ?>
                                    <span class="pg-status-chip is-ready">Sold</span>
                                <?php else: ?>
                                    <span class="pg-status-chip"><?php echo htmlspecialchars($row['status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['customer_email'])): ?>
                                    <?php echo htmlspecialchars($row['customer_name'] ?: $row['customer_email']); ?>
                                <?php else: ?>
                                    <span class="pg-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;flex-wrap:wrap;gap:0.35rem;">
                                    <?php foreach ($benefits as $b): ?>
                                        <?php
                                        $used = (int) $b['used'];
                                        $max = (int) $b['max_uses'];
                                        $chipClass = $used >= $max ? 'is-full' : ($used > 0 ? 'is-partial' : '');
                                        ?>
                                        <span class="pg-chip <?php echo $chipClass; ?>">
                                            <?php echo htmlspecialchars(trim($b['name'])); ?>
                                            <?php echo $used; ?>/<?php echo $max; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
