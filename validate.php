<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$result = null;
$error = null;
$submittedId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedId = trim($_POST['ticket_id'] ?? '');

    if ($submittedId === '') {
        $error = 'Please enter a Ticket ID.';
    } else {
        try {
            $db = getDb();
            $result = getTicketDetails($db, $submittedId);

            if ($result === null) {
                $error = 'Ticket ID not found in the registry.';
                $result = null;
            }
        } catch (Throwable $e) {
            $error = 'Unable to look up ticket. Please try again later.';
            auditLog('VALIDATE', 'Lookup error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Check ticket status'); ?>
</head>
<body class="pg-body">
<div class="pg-auth-stage" style="align-items:flex-start;padding-top:2.5rem;">
    <div style="width:100%;max-width:28rem;">
        <div style="text-align:center;margin-bottom:1.25rem;">
            <div class="pg-brand-mark" style="margin:0 auto 0.75rem;font-size:1.15rem;">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <p class="pg-eyebrow" style="margin:0 0 0.35rem;">Staff tool</p>
            <h1 class="pg-brand" style="font-size:1.75rem;margin:0;">Check ticket status</h1>
            <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.84rem;line-height:1.45;">
                Read-only status check. This does <strong>not</strong> redeem benefits —
                use the scanner terminal for that.
            </p>
        </div>

        <form method="POST" class="pg-card" style="padding:1.25rem;">
            <label class="pg-label" for="ticket_id">Ticket ID</label>
            <input type="text" id="ticket_id" name="ticket_id" class="pg-input"
                   value="<?php echo htmlspecialchars($submittedId); ?>"
                   placeholder="e.g. E1-TES-VIP-1"
                   required autofocus>
            <button type="submit" class="pg-btn pg-btn--gold" style="margin-top:1rem;">Check status</button>
        </form>

        <?php if ($error): ?>
            <div class="pg-alert" style="margin-top:1rem;">
                <i class="fa-solid fa-circle-xmark"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($result): ?>
            <?php
            $ticket = $result['ticket'];
            $benefits = $result['benefits'];
            $totalMax = array_sum(array_column($benefits, 'max_uses'));
            $totalUsed = array_sum(array_column($benefits, 'used'));
            $isFullyUsed = $totalMax > 0 && $totalUsed >= $totalMax;
            $displayStatus = $isFullyUsed ? 'Used' : ($ticket['status'] ?? 'Active');
            $statusClass = $displayStatus === 'Active' ? 'is-ready' : 'is-locked';
            ?>
            <div class="pg-card" style="padding:1.25rem;margin-top:1rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:1rem;">
                    <span class="pg-mono" style="font-weight:700;font-size:1rem;word-break:break-all;"><?php echo htmlspecialchars($ticket['id']); ?></span>
                    <span class="pg-status-chip <?php echo $statusClass; ?>"><?php echo htmlspecialchars($displayStatus); ?></span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;font-size:0.85rem;">
                    <div>
                        <span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Event</span>
                        <span style="font-weight:600;"><?php echo htmlspecialchars($ticket['event_name']); ?></span>
                    </div>
                    <div>
                        <span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Tier</span>
                        <span style="font-weight:600;"><?php echo htmlspecialchars($ticket['tier_name']); ?></span>
                    </div>
                    <div>
                        <span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Physical #</span>
                        <span style="font-weight:600;">#<?php echo (int) $ticket['physical_number']; ?></span>
                    </div>
                    <div>
                        <span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Allocated to</span>
                        <span style="font-weight:600;">
                            <?php
                            if (!empty($ticket['allocated_distributor_name'])) {
                                echo htmlspecialchars($ticket['allocated_distributor_name']);
                            } elseif (!empty($ticket['distributor_company'])) {
                                echo htmlspecialchars($ticket['distributor_company']);
                            } else {
                                echo 'Vault Pool';
                            }
                            ?>
                        </span>
                    </div>
                </div>

                <h3 class="pg-section-title" style="margin:1.1rem 0 0.55rem;">Benefits</h3>
                <div style="display:grid;gap:0.5rem;">
                    <?php foreach ($benefits as $benefit): ?>
                        <?php
                        $used = (int) $benefit['used'];
                        $max = (int) $benefit['max_uses'];
                        $isFull = $used >= $max;
                        $pct = $max > 0 ? min(100, (int) round(($used / $max) * 100)) : 0;
                        ?>
                        <div class="pg-benefit<?php echo $isFull ? ' is-full' : ''; ?>">
                            <div style="display:flex;justify-content:space-between;gap:0.5rem;">
                                <span style="font-weight:600;"><?php echo htmlspecialchars(trim($benefit['name'])); ?></span>
                                <span class="pg-mono" style="font-size:0.72rem;font-weight:700;"><?php echo $used; ?>/<?php echo $max; ?></span>
                            </div>
                            <div class="pg-bar"><div class="pg-bar__fill<?php echo $isFull ? ' is-full' : ''; ?>" style="width:<?php echo $pct; ?>%"></div></div>
                            <?php if ($isFull): ?>
                                <span style="font-size:0.65rem;font-weight:700;color:var(--pg-deny);">Fully used</span>
                            <?php else: ?>
                                <span style="font-size:0.65rem;color:var(--pg-ok);"><?php echo $max - $used; ?> left</span>
                            <?php endif; ?>
                            <?php if (!empty($benefit['last_scan'])): ?>
                                <span class="pg-faint" style="display:block;font-size:0.65rem;margin-top:0.2rem;">Last: <?php echo htmlspecialchars(substr((string) $benefit['last_scan'], 0, 16)); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <p class="pg-links" style="margin-top:1.25rem;">
            <a href="terminal.php">Back to scanner</a>
            · <a href="index.php">Home</a>
        </p>
    </div>
</div>
</body>
</html>
