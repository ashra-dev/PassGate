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
    if (isDistributorAuthenticated() && ($_SESSION['distributor_role'] ?? '') !== 'admin') {
        safeRedirect('distributor_dashboard.php');
    }
    safeRedirect('distributor_login.php');
}

$db = getDb();

if (!hasAnyEvents($db)) {
    safeRedirect('setup.php');
}

$eventId = resolveAdminEventId($db);
$currentEvent = getEventById($db, $eventId);
$allEvents = getAllEventsWithStats($db);
$eventQuery = static fn (string $tab = '') => adminEventQuery($eventId, $tab);

$is_allocation_confirm_stage = false;
$active_tab = $_GET['tab'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $timestamp = date('Y-m-d H:i:s');
    $userIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'add_distributor') {
        $dName = trim($_POST['d_name'] ?? '');
        $dEmail = strtolower(trim($_POST['d_email'] ?? ''));
        $dRole = trim($_POST['d_role'] ?? 'distributor') ?: 'distributor';
        $dPassword = (string) ($_POST['d_password'] ?? '');
        $dPasswordConfirm = (string) ($_POST['d_password_confirm'] ?? '');

        if ($dPassword !== $dPasswordConfirm) {
            $_SESSION['distributor_flash_error'] = 'Passwords do not match.';
        } else {
            try {
                ensureDistributorsSchema($db);
                $newId = createDistributor($db, $dName, $dEmail, $dPassword, $dRole);
                auditLog('CRM', "Added distributor {$dName} ({$newId})");
            } catch (Throwable $e) {
                $_SESSION['distributor_flash_error'] = $e->getMessage();
            }
        }
        safeRedirect('distributors.php?' . $eventQuery('distributors'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'reset_distributor_password') {
        $targetId = trim($_POST['d_id'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirm'] ?? '');

        if ($newPassword !== $confirmPassword) {
            $_SESSION['distributor_flash_error'] = 'Passwords do not match.';
        } else {
            try {
                resetDistributorPassword($db, $targetId, $newPassword);
                auditLog('CRM', "Reset password for distributor {$targetId}");
            } catch (Throwable $e) {
                $_SESSION['distributor_flash_error'] = $e->getMessage();
            }
        }
        safeRedirect('distributors.php?' . $eventQuery('distributors'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'delete_distributor') {
        $targetId = $_POST['d_id'] ?? '';
        $db->prepare(
            'UPDATE tickets SET allocated_distributor_id = NULL, allocated_distributor_name = \'\'
             WHERE allocated_distributor_id = :id AND event_id = :event_id'
        )->execute(['id' => $targetId, 'event_id' => $eventId]);
        $db->prepare('DELETE FROM distributors WHERE id = :id')->execute(['id' => $targetId]);
        auditLog('CRM-PURGE', "Deleted distributor {$targetId}");
        safeRedirect('distributors.php?' . $eventQuery('distributors'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'delete_event') {
        $targetEventId = (int) ($_POST['event_id'] ?? 0);
        if ($targetEventId > 0 && getEventById($db, $targetEventId) !== null) {
            $db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $targetEventId]);
            auditLog('WIPE', "Deleted event id {$targetEventId}");
            unset($_SESSION['selected_event_id'], $_SESSION['terminal_event_id']);
            if (!hasAnyEvents($db)) {
                safeRedirect('setup.php');
            }
            safeRedirect('distributors.php?tab=events');
        }
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'set_terminal_event') {
        $_SESSION['terminal_event_id'] = $eventId;
        auditLog('ADMIN', "Terminal set to event id {$eventId}");
        safeRedirect('distributors.php?' . $eventQuery('events'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'allocate_tickets_preview') {
        $_SESSION['alloc_target_dist'] = $_POST['alloc_dist_id'] ?? '';
        $_SESSION['alloc_selected_tickets'] = $_POST['selected_tickets'] ?? [];
        if (empty($_SESSION['alloc_target_dist']) || empty($_SESSION['alloc_selected_tickets'])) {
            safeRedirect('distributors.php?' . $eventQuery('allocation'));
        }
        $is_allocation_confirm_stage = true;
        $active_tab = 'allocation';
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'allocate_tickets_confirm') {
        $targetDistId = $_SESSION['alloc_target_dist'] ?? '';
        $selectedIds = $_SESSION['alloc_selected_tickets'] ?? [];

        if ($targetDistId !== '' && $selectedIds !== []) {
            $distStmt = $db->prepare('SELECT name FROM distributors WHERE id = :id');
            $distStmt->execute(['id' => $targetDistId]);
            $distName = $distStmt->fetchColumn() ?: 'Unknown';

            $update = $db->prepare(
                'UPDATE tickets SET allocated_distributor_id = :dist_id, allocated_distributor_name = :dist_name
                 WHERE id = :ticket_id'
            );
            foreach ($selectedIds as $ticketId) {
                $update->execute([
                    'dist_id'   => $targetDistId,
                    'dist_name' => $distName,
                    'ticket_id' => $ticketId,
                ]);
            }
            auditLog('ALLOCATION', count($selectedIds) . " tickets allocated to {$distName}");
        }
        unset($_SESSION['alloc_target_dist'], $_SESSION['alloc_selected_tickets']);
        safeRedirect('distributors.php?' . $eventQuery('ledger'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'reset_allocations') {
        $db->prepare(
            "UPDATE tickets SET allocated_distributor_id = NULL, allocated_distributor_name = 'Vault Pool'
             WHERE event_id = :event_id AND customer_id IS NULL"
        )->execute(['event_id' => $eventId]);
        auditLog('CLEANSE', "Allocations reset for event {$eventId}");
        safeRedirect('distributors.php?' . $eventQuery('dashboard'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'reset_scans') {
        $db->prepare(
            'DELETE FROM scans WHERE ticket_id IN (SELECT id FROM tickets WHERE event_id = :event_id)'
        )->execute(['event_id' => $eventId]);
        auditLog('CLEANSE', "Scans reset for event {$eventId}");
        safeRedirect('distributors.php?' . $eventQuery('dashboard'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'delete_registry') {
        $db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        auditLog('WIPE', "Event {$eventId} wiped");
        unset($_SESSION['selected_event_id'], $_SESSION['terminal_event_id']);
        if (!hasAnyEvents($db)) {
            safeRedirect('setup.php');
        }
        safeRedirect('distributors.php?tab=events');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'scan_benefit') {
        $ticketId = $_POST['ticket_id'] ?? '';
        $station = trim($_POST['station_name'] ?? '');

        if ($ticketId !== '' && $station !== '') {
            processTicketScan($db, $ticketId, $station, null);
        }
        safeRedirect('distributors.php?' . $eventQuery('ledger'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'add_stall') {
        $sName = trim($_POST['s_name'] ?? '');
        $sEmail = strtolower(trim($_POST['s_email'] ?? ''));
        $sPassword = (string) ($_POST['s_password'] ?? '');
        $sPasswordConfirm = (string) ($_POST['s_password_confirm'] ?? '');
        $sCategory = resolveCategorySelection(
            trim($_POST['s_category'] ?? ''),
            trim($_POST['s_category_custom'] ?? '')
        );

        if ($sPassword !== $sPasswordConfirm) {
            $_SESSION['stall_flash_error'] = 'Passwords do not match.';
        } else {
            try {
                $newId = createStall($db, $sName, $sEmail, $sPassword, $sCategory);
                auditLog('CRM', "Added stall {$sName} (id {$newId})");
            } catch (Throwable $e) {
                $_SESSION['stall_flash_error'] = $e->getMessage();
            }
        }
        safeRedirect('distributors.php?' . $eventQuery('stalls'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'reset_stall_password') {
        $stallId = (int) ($_POST['stall_id'] ?? 0);
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirm'] ?? '');

        if ($newPassword !== $confirmPassword) {
            $_SESSION['stall_flash_error'] = 'Passwords do not match.';
        } else {
            try {
                resetStallPassword($db, $stallId, $newPassword);
                auditLog('CRM', "Reset password for stall id {$stallId}");
            } catch (Throwable $e) {
                $_SESSION['stall_flash_error'] = $e->getMessage();
            }
        }
        safeRedirect('distributors.php?' . $eventQuery('stalls'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'delete_stall') {
        $stallId = (int) ($_POST['stall_id'] ?? 0);
        try {
            deleteStall($db, $stallId);
            auditLog('CRM-PURGE', "Deleted stall id {$stallId}");
        } catch (Throwable $e) {
            $_SESSION['stall_flash_error'] = $e->getMessage();
        }
        safeRedirect('distributors.php?' . $eventQuery('stalls'));
    }
}

// --- Data queries (scoped to selected event) ---
$summary = getEventTicketSummary($db, $eventId);

$distributors = $db->query('SELECT * FROM distributors ORDER BY name')->fetchAll();
$distributorMap = [];
foreach ($distributors as $d) {
    $distributorMap[$d['id']] = $d;
}

$ticketsStmt = $db->prepare(
    'SELECT t.*, ti.name AS tier_name, ti.price, e.name AS event_name,
            c.email AS customer_email, c.name AS customer_name,
            ct.payment_gateway, ct.payment_reference, ct.payment_id
     FROM tickets t
     JOIN tiers ti ON ti.id = t.tier_id
     JOIN events e ON e.id = t.event_id
     LEFT JOIN customers c ON c.id = t.customer_id
     LEFT JOIN customer_tickets ct ON ct.ticket_id = t.id
     WHERE t.event_id = :event_id
     ORDER BY t.physical_number'
);
$ticketsStmt->execute(['event_id' => $eventId]);
$tickets = $ticketsStmt->fetchAll();

$recentScansStmt = $db->prepare(
    'SELECT s.scanned_at, s.station_type, t.id AS ticket_id, b.name AS benefit_name,
            st.name AS stall_name
     FROM scans s
     JOIN tickets t ON t.id = s.ticket_id
     JOIN benefits b ON b.id = s.benefit_id
     LEFT JOIN stalls st ON st.id = s.stall_id
     WHERE t.event_id = :event_id AND s.scanned_at >= NOW() - INTERVAL \'24 hours\'
     ORDER BY s.scanned_at DESC
     LIMIT 50'
);
$recentScansStmt->execute(['event_id' => $eventId]);
$recentScans = $recentScansStmt->fetchAll();

$stalls = getAllStalls($db);
$benefitCategories = getBenefitCategoryOptions($db);
$scansByCategory = getScansByCategory($db, $eventId);
$customers = getAllCustomersWithStats($db);
$stallFlashError = $_SESSION['stall_flash_error'] ?? '';
unset($_SESSION['stall_flash_error']);
$distributorFlashError = $_SESSION['distributor_flash_error'] ?? '';
unset($_SESSION['distributor_flash_error']);

$stationChartStmt = $db->prepare(
    'SELECT s.station_type, COUNT(*) AS cnt
     FROM scans s
     JOIN tickets t ON t.id = s.ticket_id
     WHERE t.event_id = :event_id
     GROUP BY s.station_type ORDER BY cnt DESC'
);
$stationChartStmt->execute(['event_id' => $eventId]);
$stationChart = $stationChartStmt->fetchAll();

$distributorPerformanceStmt = $db->prepare(
    'SELECT d.id, d.name,
            COUNT(DISTINCT t.id) AS tickets_held,
            COUNT(s.id) AS scans_used,
            COALESCE(SUM(b.max_uses), 0) AS total_benefit_capacity
     FROM distributors d
     LEFT JOIN tickets t ON t.allocated_distributor_id = d.id AND t.event_id = :event_id
     LEFT JOIN tiers ti ON ti.id = t.tier_id
     LEFT JOIN benefits b ON b.tier_id = ti.id
     LEFT JOIN scans s ON s.ticket_id = t.id
     GROUP BY d.id, d.name
     ORDER BY d.name'
);
$distributorPerformanceStmt->execute(['event_id' => $eventId]);
$distributorPerformance = $distributorPerformanceStmt->fetchAll();

$terminalEventId = (int) ($_SESSION['terminal_event_id'] ?? 0);

function ticketBenefits(PDO $db, string $ticketId, int $tierId): array
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

$chartLabels = array_column($stationChart, 'station_type');
$chartData = array_map('intval', array_column($stationChart, 'cnt'));
$onlineSalesCount = (int) $summary['sold_online'];
$distributorSalesCount = (int) $summary['allocated'];
$gatewaySales = getOnlineSalesByGateway($db, $eventId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    passgateRenderHead('PassGate – Admin', [
        'extra' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
    ]);
    ?>
</head>
<body class="pg-body pg-admin">
<div class="pg-admin-wrap">

<div class="pg-admin-top">
    <div class="pg-admin-brand">
        <div class="pg-brand-mark" style="width:2.4rem;height:2.4rem;border-radius:0.8rem;font-size:0.95rem;">
            <i class="fa-solid fa-ticket"></i>
        </div>
        <div>
            <p class="pg-eyebrow" style="margin:0;">Event control</p>
            <h1>PassGate</h1>
            <p>Distributor Admin · Vault &amp; stations</p>
        </div>
    </div>
    <div class="pg-admin-actions">
        <a href="index.php" class="btn btn-ghost">Home</a>
        <a href="buy.php" class="btn btn-ghost">Buy page</a>
        <a href="setup.php" class="btn btn-success">+ New Event</a>
        <a href="tickets_qr.php?<?php echo adminEventQuery($eventId); ?>" class="btn btn-primary">QR Codes</a>
        <form method="POST" onsubmit="return confirm('Unallocate all tickets for this event?');" style="display:inline;">
            <input type="hidden" name="global_action" value="reset_allocations">
            <button type="submit" class="btn btn-ghost">Reset Allocations</button>
        </form>
        <form method="POST" onsubmit="return confirm('Reset all scans for this event?');" style="display:inline;">
            <input type="hidden" name="global_action" value="reset_scans">
            <button type="submit" class="btn btn-warning">Reset Scans</button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this entire event and all its tickets?');" style="display:inline;">
            <input type="hidden" name="global_action" value="delete_registry">
            <button type="submit" class="btn btn-danger">Delete Event</button>
        </form>
        <a href="logout.php" class="btn btn-danger">Logout</a>
    </div>
</div>

<?php if ($active_tab !== 'events' && !$is_allocation_confirm_stage): ?>
<div class="event-bar">
    <div>
        <span class="event-badge">Viewing event</span>
        <strong style="margin-left:8px;"><?php echo htmlspecialchars($currentEvent['name'] ?? 'Unknown'); ?></strong>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;align-items:center;gap:10px;">
            <?php if ($active_tab !== 'dashboard'): ?><input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>"><?php endif; ?>
            <label for="event_id">Switch event</label>
            <select name="event_id" id="event_id" onchange="this.form.submit()">
                <?php foreach ($allEvents as $ev): ?>
                    <option value="<?php echo (int) $ev['id']; ?>" <?php echo (int) $ev['id'] === $eventId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['name']); ?> (<?php echo (int) $ev['ticket_count']; ?> tickets)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($terminalEventId === $eventId): ?>
            <span class="pg-status-chip is-ready">Terminal active</span>
        <?php else: ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="global_action" value="set_terminal_event">
                <button type="submit" class="btn btn-primary" style="padding:6px 10px;font-size:12px;">Use for Terminal</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_allocation_confirm_stage): ?>
<nav class="tab-bar" aria-label="Admin sections">
    <a href="?<?php echo adminEventQuery($eventId, 'events'); ?>" class="tab-link <?php echo $active_tab === 'events' ? 'active' : ''; ?>">Events</a>
    <a href="?<?php echo adminEventQuery($eventId, 'dashboard'); ?>" class="tab-link <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
    <a href="?<?php echo adminEventQuery($eventId, 'ledger'); ?>" class="tab-link <?php echo $active_tab === 'ledger' ? 'active' : ''; ?>">Ledger</a>
    <a href="?<?php echo adminEventQuery($eventId, 'distributors'); ?>" class="tab-link <?php echo $active_tab === 'distributors' ? 'active' : ''; ?>">Distributors</a>
    <a href="?<?php echo adminEventQuery($eventId, 'stalls'); ?>" class="tab-link <?php echo $active_tab === 'stalls' ? 'active' : ''; ?>">Stalls</a>
    <a href="?<?php echo adminEventQuery($eventId, 'allocation'); ?>" class="tab-link <?php echo $active_tab === 'allocation' ? 'active' : ''; ?>">Allocation</a>
    <a href="?<?php echo adminEventQuery($eventId, 'analytics'); ?>" class="tab-link <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>">Analytics</a>
    <a href="?<?php echo adminEventQuery($eventId, 'customers'); ?>" class="tab-link <?php echo $active_tab === 'customers' ? 'active' : ''; ?>">Customers</a>
    <a href="tickets_qr.php?<?php echo adminEventQuery($eventId); ?>" class="tab-link">QR Codes</a>
</nav>
<?php endif; ?>

<?php if ($active_tab === 'events'): ?>
    <div class="pg-section-head">
        <div>
            <h3>All Events</h3>
            <p>Create events, open reports, and choose which event the terminal uses.</p>
        </div>
        <a href="setup.php" class="btn btn-success">+ Create Event</a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Event</th>
                <th>Created</th>
                <th>Tickets</th>
                <th>Available</th>
                <th>Sold Online</th>
                <th>Scans</th>
                <th>Terminal</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($allEvents === []): ?>
                <tr><td colspan="8">
                    <div class="pg-empty" style="border:none;box-shadow:none;padding:2rem 1rem;">
                        <div class="pg-empty__icon"><i class="fa-solid fa-calendar-plus"></i></div>
                        <h3>No events yet</h3>
                        <p>Create your first event to generate the ticket vault and start allocating passes.</p>
                        <a href="setup.php" class="btn btn-primary">Create event</a>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($allEvents as $ev): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($ev['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr((string) $ev['created_at'], 0, 16)); ?></td>
                        <td><?php echo (int) $ev['ticket_count']; ?></td>
                        <td><?php echo (int) $ev['vault_available']; ?></td>
                        <td><?php echo (int) $ev['sold_online']; ?></td>
                        <td><?php echo (int) $ev['scan_count']; ?></td>
                        <td><?php echo (int) $ev['id'] === $terminalEventId ? '● Active' : '—'; ?></td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="?<?php echo adminEventQuery((int) $ev['id'], 'dashboard'); ?>" class="btn btn-primary" style="padding:4px 10px;font-size:11px;">View Report</a>
                            <a href="tickets_qr.php?<?php echo adminEventQuery((int) $ev['id']); ?>" class="btn btn-success" style="padding:4px 10px;font-size:11px;">QR Codes</a>
                            <form method="POST" onsubmit="return confirm('Delete this event permanently?');" style="display:inline;">
                                <input type="hidden" name="global_action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:11px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

<?php elseif ($active_tab === 'dashboard'): ?>
    <div class="pg-section-head">
        <div>
            <h3>Dashboard</h3>
            <p>Live vault status and recent station activity for this event.</p>
        </div>
    </div>
    <div class="summary-grid">
        <div class="summary-card"><h3>Total Tickets</h3><div class="metric"><?php echo (int) $summary['total']; ?></div></div>
        <div class="summary-card"><h3>Vault Available</h3><div class="metric"><?php echo (int) $summary['vault_available']; ?></div></div>
        <div class="summary-card"><h3>Allocated</h3><div class="metric"><?php echo (int) $summary['allocated']; ?></div></div>
        <div class="summary-card"><h3>Sold Online</h3><div class="metric"><?php echo (int) $summary['sold_online']; ?></div></div>
    </div>

    <div class="two-col">
        <div>
            <h3>Scan Activity (Last 24h)</h3>
            <?php if ($recentScans === []): ?>
                <div class="pg-empty" style="padding:1.75rem 1rem;">
                    <div class="pg-empty__icon"><i class="fa-solid fa-qrcode"></i></div>
                    <h3>No scans yet</h3>
                    <p>When stalls redeem benefits, recent scans show up here.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Time</th><th>Ticket</th><th>Station</th><th>Stall</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentScans as $scan): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($scan['scanned_at']); ?></td>
                                <td><code><?php echo htmlspecialchars($scan['ticket_id']); ?></code></td>
                                <td><?php echo htmlspecialchars($scan['station_type']); ?></td>
                                <td><?php echo htmlspecialchars($scan['stall_name'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div>
            <h3>Scans by Station</h3>
            <div class="chart-box">
                <canvas id="stationChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <div class="pg-section-head" style="margin-top:0.5rem;">
        <div>
            <h3>Distributor Performance</h3>
            <p>How allocated inventory is converting into benefit redemptions.</p>
        </div>
    </div>
    <?php if ($distributorPerformance === []): ?>
        <div class="pg-empty" style="padding:1.75rem 1rem;">
            <div class="pg-empty__icon"><i class="fa-solid fa-users"></i></div>
            <h3>No distributor activity</h3>
            <p>Add distributors and allocate vault tickets to see performance here.</p>
            <a href="?<?php echo adminEventQuery($eventId, 'distributors'); ?>" class="btn btn-primary">Add distributor</a>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>Distributor</th><th>Tickets Held</th><th>Scans Used</th><th>Benefits Consumed</th></tr></thead>
            <tbody>
                <?php foreach ($distributorPerformance as $perf): ?>
                    <?php
                    $capacity = (int) $perf['total_benefit_capacity'];
                    $used = (int) $perf['scans_used'];
                    $pct = $capacity > 0 ? round(($used / $capacity) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($perf['name']); ?></strong></td>
                        <td><?php echo (int) $perf['tickets_held']; ?></td>
                        <td><?php echo $used; ?></td>
                        <td><span class="badge-count"><?php echo $pct; ?>%</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <script>
        new Chart(document.getElementById('stationChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chartLabels ?: ['No scans']); ?>,
                datasets: [{ data: <?php echo json_encode($chartData ?: [1]); ?>, backgroundColor: <?php echo $chartData === [] ? "['#e2e8f0']" : "['#2563eb','#16a34a','#ea580c','#9333ea','#0891b2']"; ?> }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    </script>

<?php elseif ($active_tab === 'ledger'): ?>
    <div class="pg-section-head">
        <div>
            <h3>Ticket Ledger</h3>
            <p>Every vault ticket — who holds it and benefit usage so far.</p>
        </div>
    </div>
    <div class="summary-grid">
        <div class="summary-card"><h3>Total</h3><div class="metric"><?php echo (int) $summary['total']; ?></div></div>
        <div class="summary-card"><h3>Available / Allocated / Sold</h3><div class="metric"><?php echo (int) $summary['vault_available']; ?> / <?php echo (int) $summary['allocated']; ?> / <?php echo (int) $summary['sold_online']; ?></div></div>
    </div>
    <?php if ($tickets === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-vault"></i></div>
            <h3>Vault is empty</h3>
            <p>Create an event with tiers to generate tickets in the Vault Pool.</p>
            <a href="setup.php" class="btn btn-primary">Create event</a>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>Ticket ID</th><th>Physical #</th><th>Tier</th><th>Price</th><th>Handler</th><th>Sold To</th><th>Payment</th><th>Benefits</th></tr></thead>
            <tbody>
                <?php foreach ($tickets as $row): ?>
                    <?php $benefits = ticketBenefits($db, $row['id'], (int) $row['tier_id']); ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                        <td><strong>#<?php echo (int) $row['physical_number']; ?></strong></td>
                        <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                        <td><?php echo formatPrice((float) $row['price']); ?></td>
                        <td>
                            <?php if (!empty($row['customer_id'])): ?>
                                <strong style="color:var(--pg-ok);">Online Sale</strong>
                            <?php elseif (empty($row['allocated_distributor_id'])): ?>
                                <em>Vault Pool</em>
                            <?php else: ?>
                                <?php echo htmlspecialchars($row['allocated_distributor_name']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['customer_email'])): ?>
                                <?php echo htmlspecialchars($row['customer_email']); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['customer_id'])): ?>
                                <strong><?php echo htmlspecialchars(ucfirst((string) ($row['payment_gateway'] ?: 'online'))); ?></strong>
                                <?php if (!empty($row['payment_reference'])): ?>
                                    <br><code style="font-size:0.65rem;"><?php echo htmlspecialchars($row['payment_reference']); ?></code>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="pg-benefit-chips">
                                <?php foreach ($benefits as $b):
                                    $used = (int) $b['used'];
                                    $max = (int) $b['max_uses'];
                                    $chipClass = '';
                                    if ($used > 0 && $used < $max) {
                                        $chipClass = 'is-ok';
                                    } elseif ($used >= $max && $max > 0) {
                                        $chipClass = 'is-full';
                                    }
                                ?>
                                    <span class="pg-chip <?php echo $chipClass; ?>">
                                        <?php echo htmlspecialchars(trim($b['name'])); ?>
                                        <?php echo $used; ?>/<?php echo $max; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-primary view-benefits-btn" style="padding:4px 10px;font-size:11px;"
                                    data-ticket-id="<?php echo htmlspecialchars($row['id'], ENT_QUOTES); ?>">
                                View Benefits
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($active_tab === 'distributors'): ?>
    <div class="pg-section-head">
        <div>
            <h3>Distributors</h3>
            <p>Companies that receive ticket ranges from the Vault Pool.</p>
        </div>
    </div>
    <?php if ($distributorFlashError !== ''): ?>
        <div class="pg-alert" style="margin-bottom:1rem;">
            <?php echo htmlspecialchars($distributorFlashError); ?>
        </div>
    <?php endif; ?>
    <div class="pg-form-panel">
        <h4>Add Distributor</h4>
        <p class="pg-form-hint">Set a login password — distributors sign in at <code class="pg-mono">distributor_login.php</code>.</p>
        <form method="POST" class="pg-form-grid">
            <input type="hidden" name="global_action" value="add_distributor">
            <div class="pg-field"><label>Company</label><input type="text" name="d_name" required placeholder="Acme Tickets"></div>
            <div class="pg-field"><label>Email</label><input type="email" name="d_email" required placeholder="ops@company.com"></div>
            <div class="pg-field"><label>Role</label><select name="d_role"><option value="distributor">Distributor</option><option value="admin">Admin</option></select></div>
            <div class="pg-field"><label>Password</label><input type="password" name="d_password" required minlength="6"></div>
            <div class="pg-field"><label>Confirm Password</label><input type="password" name="d_password_confirm" required minlength="6"></div>
            <button type="submit" class="btn btn-success">Save</button>
        </form>
    </div>
    <?php if ($distributors === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-building"></i></div>
            <h3>No distributors yet</h3>
            <p>Add a company above, then allocate vault tickets on the Allocation tab.</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>ID</th><th>Company</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($distributors as $d): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($d['id']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($d['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['email']); ?></td>
                        <td><span class="badge-count"><?php echo htmlspecialchars($d['role']); ?></span></td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn btn-primary" style="padding:4px 8px;font-size:11px;"
                                    onclick="openResetDistributorModal(<?php echo json_encode($d['id']); ?>, <?php echo json_encode($d['name']); ?>)">
                                Reset Password
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this distributor?');">
                                <input type="hidden" name="global_action" value="delete_distributor">
                                <input type="hidden" name="d_id" value="<?php echo htmlspecialchars($d['id']); ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div id="reset-distributor-modal" class="pg-modal-scrim" role="dialog" aria-modal="true">
        <div class="pg-modal-card">
            <h3 id="reset-distributor-title">Reset Password</h3>
            <form method="POST" style="margin-top:1rem;">
                <input type="hidden" name="global_action" value="reset_distributor_password">
                <input type="hidden" name="d_id" id="reset-distributor-id" value="">
                <div class="pg-field" style="margin-bottom:0.75rem;">
                    <label style="display:block;margin-bottom:0.35rem;font-size:0.7rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;">New Password</label>
                    <input type="password" name="new_password" required minlength="6" style="width:100%;box-sizing:border-box;">
                </div>
                <div class="pg-field" style="margin-bottom:0.75rem;">
                    <label style="display:block;margin-bottom:0.35rem;font-size:0.7rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;">Confirm Password</label>
                    <input type="password" name="new_password_confirm" required minlength="6" style="width:100%;box-sizing:border-box;">
                </div>
                <div style="display:flex;gap:0.6rem;margin-top:1rem;">
                    <button type="submit" class="btn btn-success">Update Password</button>
                    <button type="button" class="btn btn-ghost" onclick="closeResetDistributorModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openResetDistributorModal(id, name) {
            document.getElementById('reset-distributor-id').value = id;
            document.getElementById('reset-distributor-title').innerText = 'Reset Password — ' + name;
            document.getElementById('reset-distributor-modal').classList.add('is-open');
        }
        function closeResetDistributorModal() {
            document.getElementById('reset-distributor-modal').classList.remove('is-open');
        }
    </script>

<?php elseif ($active_tab === 'stalls'): ?>
    <div class="pg-section-head">
        <div>
            <h3>Stalls</h3>
            <p>Station logins for ticket scanning at each stall.</p>
        </div>
    </div>
    <?php if ($stallFlashError !== ''): ?>
        <div class="pg-alert" style="margin-bottom:1rem;">
            <?php echo htmlspecialchars($stallFlashError); ?>
        </div>
    <?php endif; ?>

    <div class="pg-form-panel">
        <h4>Add New Stall</h4>
        <p class="pg-form-hint">Pick a category that matches the benefits this stall serves. Categories come from event benefits; you can add a new one if needed.</p>
        <form method="POST" class="pg-form-grid" id="add-stall-form">
            <input type="hidden" name="global_action" value="add_stall">
            <div class="pg-field"><label>Stall Name</label><input type="text" name="s_name" required placeholder="Bar Station"></div>
            <div class="pg-field pg-category-picker">
                <label for="s_category">Category</label>
                <select name="s_category" id="s_category" class="pg-category-custom-select" required>
                    <option value="">Select category…</option>
                    <?php foreach ($benefitCategories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars(ucfirst($cat)); ?></option>
                    <?php endforeach; ?>
                    <option value="__new__">+ Add new category…</option>
                </select>
                <div class="pg-category-picker__custom" id="s_category_custom_wrap" data-custom-wrap>
                    <label for="s_category_custom" class="pg-category-picker__label" style="margin-top:0.25rem;">New category name</label>
                    <input type="text" name="s_category_custom" id="s_category_custom" class="pg-category-custom"
                           maxlength="50" placeholder="e.g. drink, food, merch" disabled>
                </div>
            </div>
            <div class="pg-field"><label>Email</label><input type="email" name="s_email" required placeholder="stall@event.com"></div>
            <div class="pg-field"><label>Password</label><input type="password" name="s_password" required minlength="6"></div>
            <div class="pg-field"><label>Confirm Password</label><input type="password" name="s_password_confirm" required minlength="6"></div>
            <button type="submit" class="btn btn-success">Add Stall</button>
        </form>
    </div>

    <script src="assets/js/category-picker.js"></script>
    <script>
    (function () {
      const INITIAL_CATEGORIES = <?php echo json_encode($benefitCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      PGCategories.init(INITIAL_CATEGORIES);

      const stallForm = document.getElementById('add-stall-form');
      const stallSelect = document.getElementById('s_category');
      if (stallSelect) {
        PGCategories.rebuildSelect(stallSelect, stallSelect.value || '');
      }

      PGCategories.bindForm(stallForm, '#s_category');

      stallForm?.addEventListener('submit', (e) => {
        if (!PGCategories.commitPending('#s_category')) {
          e.preventDefault();
          alert('Enter a name for the new category.');
        }
      });
    })();
    </script>

    <?php if ($stalls === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-store"></i></div>
            <h3>No stalls yet</h3>
            <p>Add a stall account — staff log in on the terminal to scan tickets at that station.</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Email</th><th>Created At</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($stalls as $stall): ?>
                    <tr>
                        <td><?php echo (int) $stall['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($stall['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($stall['category'] !== '' ? ucfirst((string) $stall['category']) : '—'); ?></td>
                        <td><?php echo htmlspecialchars($stall['email']); ?></td>
                        <td><?php echo htmlspecialchars($stall['created_at']); ?></td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn btn-primary" style="padding:4px 8px;font-size:11px;"
                                    onclick="openResetStallModal(<?php echo (int) $stall['id']; ?>, <?php echo json_encode($stall['name']); ?>)">
                                Reset Password
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this stall? Scan history will be kept but unattributed.');">
                                <input type="hidden" name="global_action" value="delete_stall">
                                <input type="hidden" name="stall_id" value="<?php echo (int) $stall['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div id="reset-stall-modal" class="pg-modal-scrim" role="dialog" aria-modal="true">
        <div class="pg-modal-card">
            <h3 id="reset-stall-title">Reset Password</h3>
            <form method="POST" style="margin-top:1rem;">
                <input type="hidden" name="global_action" value="reset_stall_password">
                <input type="hidden" name="stall_id" id="reset-stall-id" value="">
                <div class="pg-field" style="margin-bottom:0.75rem;">
                    <label style="display:block;margin-bottom:0.35rem;font-size:0.7rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;">New Password</label>
                    <input type="password" name="new_password" required minlength="6" style="width:100%;box-sizing:border-box;">
                </div>
                <div class="pg-field" style="margin-bottom:0.75rem;">
                    <label style="display:block;margin-bottom:0.35rem;font-size:0.7rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;">Confirm Password</label>
                    <input type="password" name="new_password_confirm" required minlength="6" style="width:100%;box-sizing:border-box;">
                </div>
                <div style="display:flex;gap:0.6rem;margin-top:1rem;">
                    <button type="submit" class="btn btn-success">Update Password</button>
                    <button type="button" class="btn btn-ghost" onclick="closeResetStallModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openResetStallModal(id, name) {
            document.getElementById('reset-stall-id').value = id;
            document.getElementById('reset-stall-title').innerText = 'Reset Password — ' + name;
            document.getElementById('reset-stall-modal').classList.add('is-open');
        }
        function closeResetStallModal() {
            document.getElementById('reset-stall-modal').classList.remove('is-open');
        }
    </script>

<?php elseif ($active_tab === 'allocation'): ?>
    <?php
    $vaultTickets = array_values(array_filter(
        $tickets,
        static fn ($row) => empty($row['allocated_distributor_id']) && empty($row['customer_id'])
    ));
    if ($is_allocation_confirm_stage):
        $tDist = $_SESSION['alloc_target_dist'] ?? '';
        $tTkts = $_SESSION['alloc_selected_tickets'] ?? [];
        $dName = $distributorMap[$tDist]['name'] ?? 'Unknown';
        $grouped = [];
        foreach ($tickets as $row) {
            if (in_array($row['id'], $tTkts, true)) {
                $grouped[$row['tier_name']][] = $row['physical_number'];
            }
        }
    ?>
        <div class="confirm-window">
            <p class="pg-eyebrow" style="margin:0 0 0.35rem;">Allocation preview</p>
            <h2 style="margin:0 0 0.75rem;">Confirm Allocation</h2>
            <p><strong>Distributor:</strong> <?php echo htmlspecialchars($dName); ?></p>
            <p><strong>Tickets:</strong> <?php echo count($tTkts); ?></p>
            <div class="scroller-box">
                <?php foreach ($grouped as $type => $list): ?>
                    <div class="preview-group-row" style="padding:0.75rem;margin-bottom:0.55rem;">
                        <div class="preview-type-title"><?php echo htmlspecialchars($type); ?></div>
                        <div class="preview-column-grid">
                            <?php foreach ($list as $pId): ?><span class="preview-pill">#<?php echo (int) $pId; ?></span><?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:0.6rem;flex-wrap:wrap;margin-top:0.5rem;">
                <form method="POST" style="display:inline;"><input type="hidden" name="global_action" value="allocate_tickets_confirm"><button type="submit" class="btn btn-success">Confirm</button></form>
                <a href="?<?php echo adminEventQuery($eventId, 'allocation'); ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </div>
    <?php else: ?>
        <div class="pg-section-head">
            <div>
                <h3>Allocation</h3>
                <p>Move tickets from the Vault Pool to a distributor. Preview before confirming.</p>
            </div>
            <span class="badge-count"><?php echo count($vaultTickets); ?> in vault</span>
        </div>
        <?php if ($vaultTickets === []): ?>
            <div class="pg-empty">
                <div class="pg-empty__icon"><i class="fa-solid fa-check"></i></div>
                <h3>Vault fully allocated</h3>
                <p>All tickets for this event are already assigned. Reset allocations if you need to reassign.</p>
                <a href="?<?php echo adminEventQuery($eventId, 'ledger'); ?>" class="btn btn-primary">Open ledger</a>
            </div>
        <?php elseif ($distributors === []): ?>
            <div class="pg-empty">
                <div class="pg-empty__icon"><i class="fa-solid fa-user-plus"></i></div>
                <h3>Add a distributor first</h3>
                <p>You need at least one distributor before allocating vault tickets.</p>
                <a href="?<?php echo adminEventQuery($eventId, 'distributors'); ?>" class="btn btn-primary">Add distributor</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="global_action" value="allocate_tickets_preview">
                <div class="pg-form-panel">
                    <h4>Select Distributor</h4>
                    <p class="pg-form-hint">Choose who receives the tickets you select below.</p>
                    <select name="alloc_dist_id" required style="width:100%;max-width:28rem;">
                        <option value="">— Choose distributor —</option>
                        <?php foreach ($distributors as $d): ?><option value="<?php echo htmlspecialchars($d['id']); ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="pg-alloc-pick">
                    <table>
                        <thead><tr><th></th><th>Ticket ID</th><th>Physical #</th><th>Tier</th></tr></thead>
                        <tbody>
                            <?php foreach ($vaultTickets as $row): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_tickets[]" value="<?php echo htmlspecialchars($row['id']); ?>"></td>
                                    <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                                    <td>#<?php echo (int) $row['physical_number']; ?></td>
                                    <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;padding:0.9rem;margin-top:1rem;">Preview Allocation</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($active_tab === 'analytics'): ?>
    <div class="summary-grid" style="margin-bottom:1.25rem;">
        <div class="summary-card"><h3>Online Sales</h3><div class="metric"><?php echo $onlineSalesCount; ?></div></div>
        <div class="summary-card"><h3>Distributor Allocations</h3><div class="metric"><?php echo $distributorSalesCount; ?></div></div>
        <div class="summary-card"><h3>Vault Available</h3><div class="metric"><?php echo (int) $summary['vault_available']; ?></div></div>
    </div>
    <h3>Sales by Payment Gateway</h3>
    <table style="margin-bottom:1.25rem;">
        <thead><tr><th>Gateway</th><th>Tickets Sold</th><th>Revenue</th></tr></thead>
        <tbody>
            <?php if ($gatewaySales === []): ?>
                <tr><td colspan="3">No online sales yet.</td></tr>
            <?php else: ?>
                <?php foreach ($gatewaySales as $gw): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(ucfirst($gw['payment_gateway'])); ?></strong></td>
                        <td><?php echo (int) $gw['sale_count']; ?></td>
                        <td><?php echo formatPrice($gw['revenue']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <h3 style="margin-top:1.5rem;">Scans by Benefit Category</h3>
    <table style="margin-bottom:1.25rem;">
        <thead><tr><th>Category</th><th>Scans</th></tr></thead>
        <tbody>
            <?php if ($scansByCategory === []): ?>
                <tr><td colspan="2">No scans recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($scansByCategory as $catRow): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(ucfirst($catRow['category'])); ?></strong></td>
                        <td><?php echo (int) $catRow['scan_count']; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $filterDist = $_GET['filter_distributor'] ?? 'ALL';
    $analyticsRows = array_values(array_filter(
        $tickets,
        static fn ($row) => $filterDist === 'ALL' || ($row['allocated_distributor_id'] ?? '') === $filterDist
    ));
    ?>
    <div class="pg-section-head">
        <div>
            <h3>Analytics</h3>
            <p>Benefit usage per ticket — filter by distributor when needed.</p>
        </div>
    </div>
    <form method="GET" class="pg-filter-bar">
        <input type="hidden" name="tab" value="analytics">
        <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
        <label for="filter_distributor">Distributor</label>
        <select name="filter_distributor" id="filter_distributor" onchange="this.form.submit()">
            <option value="ALL">All distributors</option>
            <?php foreach ($distributors as $d): ?>
                <option value="<?php echo htmlspecialchars($d['id']); ?>" <?php echo $filterDist === $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($analyticsRows === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-chart-pie"></i></div>
            <h3>No tickets to show</h3>
            <p>Try another distributor filter, or allocate tickets from the vault first.</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>Ticket</th><th>Physical #</th><th>Tier</th><th>Handler</th><th>Benefits</th></tr></thead>
            <tbody>
                <?php foreach ($analyticsRows as $row): ?>
                    <?php $benefits = ticketBenefits($db, $row['id'], (int) $row['tier_id']); ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                        <td>#<?php echo (int) $row['physical_number']; ?></td>
                        <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                        <td>
                            <?php if (!empty($row['customer_id'])): ?>
                                Online Sale
                            <?php elseif (empty($row['allocated_distributor_id'])): ?>
                                Vault Pool
                            <?php else: ?>
                                <?php echo htmlspecialchars($row['allocated_distributor_name']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="pg-benefit-chips">
                                <?php foreach ($benefits as $b):
                                    $used = (int) $b['used'];
                                    $max = (int) $b['max_uses'];
                                    $chipClass = '';
                                    if ($used > 0 && $used < $max) {
                                        $chipClass = 'is-ok';
                                    } elseif ($used >= $max && $max > 0) {
                                        $chipClass = 'is-full';
                                    }
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

<?php elseif ($active_tab === 'customers'): ?>
    <div class="pg-section-head">
        <div>
            <h3>Customers</h3>
            <p>Online purchasers. <a href="buy.php">Public buy page</a></p>
        </div>
    </div>
    <?php if ($customers === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-user"></i></div>
            <h3>No customers yet</h3>
            <p>Customers appear here after Stripe or eSewa purchases.</p>
        </div>
    <?php else: ?>
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Tickets</th><th>Joined</th></tr></thead>
            <tbody>
                <?php foreach ($customers as $cust): ?>
                    <tr>
                        <td><?php echo (int) $cust['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($cust['name'] ?: '—'); ?></strong></td>
                        <td><?php echo htmlspecialchars($cust['email']); ?></td>
                        <td><?php echo (int) $cust['ticket_count']; ?></td>
                        <td><?php echo htmlspecialchars(substr((string) $cust['created_at'], 0, 16)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<!-- Benefits modal + toast (Ledger) -->
<div id="admin-toast" class="pg-toast-wrap hidden">
    <div id="admin-toast-inner" class="pg-toast is-visible" style="pointer-events:auto;"></div>
</div>

<div id="benefits-modal" class="pg-overlay hidden" style="z-index:90;">
    <div class="pg-card" style="width:100%;max-width:40rem;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--pg-border);">
            <h3 style="margin:0;font-family:var(--pg-display);">Ticket Benefits</h3>
            <button type="button" id="benefits-modal-close" class="pg-btn pg-btn--ghost pg-btn--sm">&times;</button>
        </div>
        <div style="padding:1rem 1.25rem;overflow:auto;flex:1;">
            <div id="benefits-modal-meta" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;font-size:0.85rem;margin-bottom:1rem;"></div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Benefit</th>
                            <th>Max</th>
                            <th>Used</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="benefits-modal-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    localStorage.setItem('passgate_auth', 'admin');

    const API_BASE = window.location.origin + window.location.pathname.replace(/[^/]+$/, '');
    let activeModalTicketId = null;

    function showAdminToast(message, type) {
        const wrap = document.getElementById('admin-toast');
        const inner = document.getElementById('admin-toast-inner');
        if (!wrap || !inner) return;
        const color = type === 'success' ? 'var(--pg-ok)' : type === 'warning' ? 'var(--pg-warn)' : 'var(--pg-deny)';
        inner.style.borderColor = color;
        inner.style.color = 'var(--pg-text)';
        inner.textContent = message;
        wrap.classList.remove('hidden');
        setTimeout(() => wrap.classList.add('hidden'), 3500);
    }

    function renderBenefitsModal(data) {
        const ticket = data.ticket;
        const benefits = data.benefits || [];
        activeModalTicketId = ticket.id;

        document.getElementById('benefits-modal-meta').innerHTML = `
            <div><span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Ticket ID</span><span class="pg-mono" style="font-weight:700;">${escapeHtml(ticket.id)}</span></div>
            <div><span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Physical #</span><span style="font-weight:700;">#${ticket.physical_number}</span></div>
            <div><span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Tier</span><span style="font-weight:600;">${escapeHtml(ticket.tier_name || '')}</span></div>
            <div><span class="pg-section-title" style="display:block;margin-bottom:0.2rem;">Event</span><span style="font-weight:600;">${escapeHtml(ticket.event_name || '')}</span></div>`;

        const tbody = document.getElementById('benefits-modal-body');
        tbody.innerHTML = benefits.map(b => {
            const used = parseInt(b.used, 10);
            const max = parseInt(b.max_uses, 10);
            const full = used >= max;
            const name = String(b.name).trim();
            return `<tr>
                <td style="font-weight:600;">${escapeHtml(name)}</td>
                <td class="pg-mono">${max}</td>
                <td class="pg-mono" style="font-weight:700;" data-benefit-name="${escapeAttr(name)}">${used}</td>
                <td>
                    <button type="button" class="simulate-scan-btn btn ${full ? 'btn-ghost' : 'btn-success'}"
                        data-benefit-name="${escapeAttr(name)}" ${full ? 'disabled' : ''}>Simulate Scan</button>
                </td>
            </tr>`;
        }).join('');

        tbody.querySelectorAll('.simulate-scan-btn').forEach(btn => {
            btn.addEventListener('click', () => simulateBenefitScan(btn.dataset.benefitName));
        });
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escapeAttr(str) { return escapeHtml(str); }

    async function loadBenefitsModal(ticketId) {
        const res = await fetch(`${API_BASE}api.php?action=ticket&id=${encodeURIComponent(ticketId)}`);
        const json = await res.json();
        if (!res.ok || json.status !== 'success') {
            throw new Error(json.message || 'Unable to load ticket');
        }
        renderBenefitsModal(json.data);
    }

    async function openBenefitsModal(ticketId) {
        try {
            await loadBenefitsModal(ticketId);
            document.getElementById('benefits-modal').classList.remove('hidden');
        } catch (e) {
            showAdminToast(e.message || 'Failed to load ticket', 'error');
        }
    }

    async function simulateBenefitScan(benefitName) {
        if (!activeModalTicketId || !benefitName) return;
        try {
            const res = await fetch(`${API_BASE}api.php?action=scan`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: activeModalTicketId, station: benefitName })
            });
            const data = await res.json();
            if (data.status === 'granted') {
                showAdminToast(`${benefitName}: scan recorded (${data.used}/${data.max})`, 'success');
            } else if (data.status === 'already_scanned' || data.status === 'limit_reached') {
                showAdminToast(`${benefitName}: limit reached (${data.used}/${data.max})`, 'warning');
            } else {
                throw new Error(data.message || 'Scan failed');
            }
            await loadBenefitsModal(activeModalTicketId);
        } catch (e) {
            showAdminToast(e.message || 'Scan failed', 'error');
        }
    }

    document.querySelectorAll('.view-benefits-btn').forEach(btn => {
        btn.addEventListener('click', () => openBenefitsModal(btn.dataset.ticketId));
    });
    document.getElementById('benefits-modal-close')?.addEventListener('click', () => {
        document.getElementById('benefits-modal').classList.add('hidden');
    });
    document.getElementById('benefits-modal')?.addEventListener('click', (e) => {
        if (e.target.id === 'benefits-modal') document.getElementById('benefits-modal').classList.add('hidden');
    });
</script>
</div>
</body>
</html>
