<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

if (
    !isset($_SESSION['distributor_authenticated'])
    || $_SESSION['distributor_authenticated'] !== true
    || ($_SESSION['distributor_role'] ?? '') !== 'admin'
) {
    header('Location: index.php');
    exit;
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
        $dId = 'DIST-' . random_int(1000, 9999);
        $dName = trim($_POST['d_name'] ?? '');
        $dEmail = strtolower(trim($_POST['d_email'] ?? ''));
        $dRole = trim($_POST['d_role'] ?? 'distributor') ?: 'distributor';

        $stmt = $db->prepare(
            'INSERT INTO distributors (id, name, email, role) VALUES (:id, :name, :email, :role)'
        );
        $stmt->execute(['id' => $dId, 'name' => $dName, 'email' => $dEmail, 'role' => $dRole]);
        auditLog('CRM', "Added distributor {$dName} ({$dId})");
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
             WHERE event_id = :event_id"
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
        $benefitId = (int) ($_POST['benefit_id'] ?? 0);
        $station = trim($_POST['station_name'] ?? '');

        if ($ticketId !== '' && $benefitId > 0) {
            processTicketScan($db, $ticketId, $station, null);
        }
        safeRedirect('distributors.php?' . $eventQuery('ledger'));
    }

    if (isset($_POST['global_action']) && $_POST['global_action'] === 'add_stall') {
        $sName = trim($_POST['s_name'] ?? '');
        $sEmail = strtolower(trim($_POST['s_email'] ?? ''));
        $sPassword = (string) ($_POST['s_password'] ?? '');
        $sPasswordConfirm = (string) ($_POST['s_password_confirm'] ?? '');

        if ($sPassword !== $sPasswordConfirm) {
            $_SESSION['stall_flash_error'] = 'Passwords do not match.';
        } else {
            try {
                $newId = createStall($db, $sName, $sEmail, $sPassword);
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
$summaryStmt = $db->prepare(
    "SELECT
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE allocated_distributor_id IS NOT NULL AND allocated_distributor_id <> '') AS allocated,
        COUNT(*) FILTER (WHERE allocated_distributor_id IS NULL OR allocated_distributor_id = '') AS unallocated
     FROM tickets WHERE event_id = :event_id"
);
$summaryStmt->execute(['event_id' => $eventId]);
$summary = $summaryStmt->fetch();

$distributors = $db->query('SELECT * FROM distributors ORDER BY name')->fetchAll();
$distributorMap = [];
foreach ($distributors as $d) {
    $distributorMap[$d['id']] = $d;
}

$ticketsStmt = $db->prepare(
    'SELECT t.*, ti.name AS tier_name, ti.price, e.name AS event_name
     FROM tickets t
     JOIN tiers ti ON ti.id = t.tier_id
     JOIN events e ON e.id = t.event_id
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
$stallFlashError = $_SESSION['stall_flash_error'] ?? '';
unset($_SESSION['stall_flash_error']);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PassGate Pro – Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 25px; background: #f8fafc; color: #0f172a; }
        .header-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .tab-bar { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; flex-wrap: wrap; }
        .tab-link { padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; background: #e2e8f0; color: #475569; }
        .tab-link.active { background: #2563eb; color: white; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: white; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .summary-card .metric { font-size: 26px; font-weight: bold; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 25px; }
        th, td { padding: 12px 15px; text-align: left; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        th { background-color: #1e293b; color: white; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer; color: white; text-decoration: none; display: inline-block; }
        .btn-warning { background: #ea580c; } .btn-danger { background: #dc2626; } .btn-primary { background: #2563eb; } .btn-success { background: #16a34a; }
        .confirm-window { background: #fff; padding: 25px; border: 2px dashed #2563eb; border-radius: 10px; max-width: 700px; margin: 0 auto; }
        .scroller-box { background: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; max-height: 280px; overflow-y: scroll; font-family: monospace; border-radius: 6px; margin: 15px 0; }
        .badge-count { background: #eff6ff; color: #1e40af; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 11px; border: 1px solid #bfdbfe; }
        .preview-group-row { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 12px; }
        .preview-type-title { background: #1e293b; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; color: white; margin-bottom: 10px; text-transform: uppercase; display: inline-block; }
        .preview-column-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .preview-pill { background: #e0f2fe; color: #0369a1; padding: 6px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; border: 1px solid #bae6fd; text-align: center; }
        .chart-box { background: white; padding: 20px; border-radius: 10px; border: 1px solid #e2e8f0; max-width: 500px; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .event-bar { background:white; padding:15px 20px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
        .event-bar select { padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:14px; min-width:220px; }
        .event-badge { background:#eff6ff; color:#1e40af; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:bold; }
    </style>
</head>
<body>

<div class="header-container">
    <h2>PassGate Pro – Admin</h2>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="setup.php" class="btn btn-success">+ New Event</a>
        <a href="tickets_qr.php?<?php echo adminEventQuery($eventId); ?>" class="btn btn-primary">QR Codes</a>
        <form method="POST" onsubmit="return confirm('Unallocate all tickets for this event?');">
            <input type="hidden" name="global_action" value="reset_allocations">
            <button type="submit" class="btn" style="background:#475569;">Reset Allocations</button>
        </form>
        <form method="POST" onsubmit="return confirm('Reset all scans for this event?');">
            <input type="hidden" name="global_action" value="reset_scans">
            <button type="submit" class="btn btn-warning">Reset Scans</button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this entire event and all its tickets?');">
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
            <label for="event_id" style="font-size:13px;font-weight:600;">Switch event:</label>
            <select name="event_id" id="event_id" onchange="this.form.submit()">
                <?php foreach ($allEvents as $ev): ?>
                    <option value="<?php echo (int) $ev['id']; ?>" <?php echo (int) $ev['id'] === $eventId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['name']); ?> (<?php echo (int) $ev['ticket_count']; ?> tickets)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($terminalEventId === $eventId): ?>
            <span style="font-size:12px;color:#16a34a;font-weight:600;">● Terminal active</span>
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
<div class="tab-bar">
    <a href="?<?php echo adminEventQuery($eventId, 'events'); ?>" class="tab-link <?php echo $active_tab === 'events' ? 'active' : ''; ?>">Events</a>
    <a href="?<?php echo adminEventQuery($eventId, 'dashboard'); ?>" class="tab-link <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
    <a href="?<?php echo adminEventQuery($eventId, 'ledger'); ?>" class="tab-link <?php echo $active_tab === 'ledger' ? 'active' : ''; ?>">Ledger</a>
    <a href="?<?php echo adminEventQuery($eventId, 'distributors'); ?>" class="tab-link <?php echo $active_tab === 'distributors' ? 'active' : ''; ?>">Distributors</a>
    <a href="?<?php echo adminEventQuery($eventId, 'stalls'); ?>" class="tab-link <?php echo $active_tab === 'stalls' ? 'active' : ''; ?>">Stalls</a>
    <a href="?<?php echo adminEventQuery($eventId, 'allocation'); ?>" class="tab-link <?php echo $active_tab === 'allocation' ? 'active' : ''; ?>">Allocation</a>
    <a href="?<?php echo adminEventQuery($eventId, 'analytics'); ?>" class="tab-link <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>">Analytics</a>
    <a href="tickets_qr.php?<?php echo adminEventQuery($eventId); ?>" class="tab-link">QR Codes</a>
</div>
<?php endif; ?>

<?php if ($active_tab === 'events'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3>All Events</h3>
        <a href="setup.php" class="btn btn-success">+ Create Event</a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Event</th>
                <th>Created</th>
                <th>Tickets</th>
                <th>Scans</th>
                <th>Terminal</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($allEvents === []): ?>
                <tr><td colspan="6">No events yet. <a href="setup.php">Create your first event</a>.</td></tr>
            <?php else: ?>
                <?php foreach ($allEvents as $ev): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($ev['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars(substr((string) $ev['created_at'], 0, 16)); ?></td>
                        <td><?php echo (int) $ev['ticket_count']; ?></td>
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
    <div class="summary-grid">
        <div class="summary-card" style="border-top:4px solid #2563eb;"><h3>Total Tickets</h3><div class="metric"><?php echo (int) $summary['total']; ?></div></div>
        <div class="summary-card" style="border-top:4px solid #16a34a;"><h3>Allocated</h3><div class="metric" style="color:#16a34a;"><?php echo (int) $summary['allocated']; ?></div></div>
        <div class="summary-card" style="border-top:4px solid #ea580c;"><h3>Unallocated</h3><div class="metric" style="color:#ea580c;"><?php echo (int) $summary['unallocated']; ?></div></div>
    </div>

    <div class="two-col">
        <div>
            <h3>Scan Activity (Last 24h)</h3>
            <table>
                <thead><tr><th>Time</th><th>Ticket</th><th>Station</th><th>Stall</th></tr></thead>
                <tbody>
                    <?php if ($recentScans === []): ?>
                        <tr><td colspan="4">No scans in the last 24 hours.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentScans as $scan): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($scan['scanned_at']); ?></td>
                                <td><code><?php echo htmlspecialchars($scan['ticket_id']); ?></code></td>
                                <td><?php echo htmlspecialchars($scan['station_type']); ?></td>
                                <td><?php echo htmlspecialchars($scan['stall_name'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div>
            <h3>Scans by Station</h3>
            <div class="chart-box">
                <canvas id="stationChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <h3>Distributor Performance</h3>
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
    <script>
        new Chart(document.getElementById('stationChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{ data: <?php echo json_encode($chartData); ?>, backgroundColor: ['#2563eb','#16a34a','#ea580c','#9333ea','#0891b2'] }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    </script>

<?php elseif ($active_tab === 'ledger'): ?>
    <div class="summary-grid">
        <div class="summary-card"><h3>Total</h3><div class="metric"><?php echo (int) $summary['total']; ?></div></div>
        <div class="summary-card"><h3>Allocated / Unallocated</h3><div class="metric"><?php echo (int) $summary['allocated']; ?> / <?php echo (int) $summary['unallocated']; ?></div></div>
    </div>
    <table>
        <thead><tr><th>Ticket ID</th><th>Physical #</th><th>Tier</th><th>Price</th><th>Handler</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($tickets as $row): ?>
                <?php $benefits = ticketBenefits($db, $row['id'], (int) $row['tier_id']); ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                    <td><strong>#<?php echo (int) $row['physical_number']; ?></strong></td>
                    <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                    <td><?php echo formatPrice((float) $row['price']); ?></td>
                    <td><?php echo empty($row['allocated_distributor_id']) ? '<em>Vault Pool</em>' : htmlspecialchars($row['allocated_distributor_name']); ?></td>
                    <td>
                        <span class="text-xs text-slate-500">
                            <?php
                            $parts = [];
                            foreach ($benefits as $b) {
                                $parts[] = htmlspecialchars(trim($b['name'])) . ' ' . (int) $b['used'] . '/' . (int) $b['max_uses'];
                            }
                            echo implode(' · ', $parts);
                            ?>
                        </span>
                        <button type="button" class="btn btn-primary view-benefits-btn" style="margin-top:6px;padding:4px 10px;font-size:11px;"
                                data-ticket-id="<?php echo htmlspecialchars($row['id'], ENT_QUOTES); ?>">
                            View Benefits
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($active_tab === 'distributors'): ?>
    <div style="background:white;padding:20px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:25px;">
        <h4>Add Distributor</h4>
        <form method="POST" style="display:flex;gap:15px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="global_action" value="add_distributor">
            <div style="flex:1;min-width:150px;"><label>Company</label><input type="text" name="d_name" required style="width:100%;padding:8px;"></div>
            <div style="flex:1;min-width:150px;"><label>Email</label><input type="email" name="d_email" required style="width:100%;padding:8px;"></div>
            <div style="flex:1;min-width:120px;"><label>Role</label><select name="d_role" style="width:100%;padding:8px;"><option value="distributor">Distributor</option><option value="admin">Admin</option></select></div>
            <button type="submit" class="btn btn-success">Save</button>
        </form>
    </div>
    <table>
        <thead><tr><th>ID</th><th>Company</th><th>Email</th><th>Role</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($distributors as $d): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($d['id']); ?></code></td>
                    <td><strong><?php echo htmlspecialchars($d['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($d['email']); ?></td>
                    <td><?php echo htmlspecialchars($d['role']); ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Delete this distributor?');">
                            <input type="hidden" name="global_action" value="delete_distributor">
                            <input type="hidden" name="d_id" value="<?php echo htmlspecialchars($d['id']); ?>">
                            <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($active_tab === 'stalls'): ?>
    <?php if ($stallFlashError !== ''): ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
            <?php echo htmlspecialchars($stallFlashError); ?>
        </div>
    <?php endif; ?>

    <div style="background:white;padding:20px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:25px;">
        <h4>Add New Stall</h4>
        <p style="font-size:13px;color:#64748b;margin-bottom:12px;">Stall name should match a benefit name (e.g. VIP Entry, Food Stand). Multiple stalls can share one email — each stall must have a <strong>different password</strong> (email + password identifies the stall at login).</p>
        <form method="POST" style="display:flex;gap:15px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="global_action" value="add_stall">
            <div style="flex:1;min-width:150px;"><label>Stall Name</label><input type="text" name="s_name" required style="width:100%;padding:8px;" placeholder="VIP Entry"></div>
            <div style="flex:1;min-width:150px;"><label>Email</label><input type="email" name="s_email" required style="width:100%;padding:8px;"></div>
            <div style="flex:1;min-width:120px;"><label>Password</label><input type="password" name="s_password" required minlength="6" style="width:100%;padding:8px;"></div>
            <div style="flex:1;min-width:120px;"><label>Confirm Password</label><input type="password" name="s_password_confirm" required minlength="6" style="width:100%;padding:8px;"></div>
            <button type="submit" class="btn btn-success">Add Stall</button>
        </form>
    </div>

    <table>
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Created At</th><th>Actions</th></tr></thead>
        <tbody>
            <?php if ($stalls === []): ?>
                <tr><td colspan="5">No stalls yet. Add one above.</td></tr>
            <?php else: ?>
                <?php foreach ($stalls as $stall): ?>
                    <tr>
                        <td><?php echo (int) $stall['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($stall['name']); ?></strong></td>
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
            <?php endif; ?>
        </tbody>
    </table>

    <div id="reset-stall-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
        <div style="background:white;padding:24px;border-radius:12px;max-width:400px;width:90%;">
            <h3 id="reset-stall-title">Reset Password</h3>
            <form method="POST" class="space-y-3" style="margin-top:16px;">
                <input type="hidden" name="global_action" value="reset_stall_password">
                <input type="hidden" name="stall_id" id="reset-stall-id" value="">
                <div><label>New Password</label><input type="password" name="new_password" required minlength="6" style="width:100%;padding:8px;margin-top:4px;"></div>
                <div><label>Confirm Password</label><input type="password" name="new_password_confirm" required minlength="6" style="width:100%;padding:8px;margin-top:4px;"></div>
                <div style="display:flex;gap:10px;margin-top:16px;">
                    <button type="submit" class="btn btn-success">Update Password</button>
                    <button type="button" class="btn" style="background:#64748b;" onclick="closeResetStallModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openResetStallModal(id, name) {
            document.getElementById('reset-stall-id').value = id;
            document.getElementById('reset-stall-title').innerText = 'Reset Password — ' + name;
            document.getElementById('reset-stall-modal').style.display = 'flex';
        }
        function closeResetStallModal() {
            document.getElementById('reset-stall-modal').style.display = 'none';
        }
    </script>

<?php elseif ($active_tab === 'allocation'): ?>
    <?php if ($is_allocation_confirm_stage):
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
            <h2>Confirm Allocation</h2>
            <p><strong>Distributor:</strong> <?php echo htmlspecialchars($dName); ?></p>
            <p><strong>Tickets:</strong> <?php echo count($tTkts); ?></p>
            <div class="scroller-box">
                <?php foreach ($grouped as $type => $list): ?>
                    <div class="preview-group-row">
                        <div class="preview-type-title"><?php echo htmlspecialchars($type); ?></div>
                        <div class="preview-column-grid">
                            <?php foreach ($list as $pId): ?><span class="preview-pill">#<?php echo (int) $pId; ?></span><?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="POST" style="display:inline;"><input type="hidden" name="global_action" value="allocate_tickets_confirm"><button type="submit" class="btn btn-success">Confirm</button></form>
            <a href="?<?php echo adminEventQuery($eventId, 'allocation'); ?>" class="btn" style="background:#64748b;">Cancel</a>
        </div>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="global_action" value="allocate_tickets_preview">
            <div style="background:white;padding:20px;border-radius:8px;margin-bottom:20px;">
                <label><strong>Select Distributor</strong></label>
                <select name="alloc_dist_id" required style="width:100%;padding:10px;margin-top:5px;">
                    <option value="">-- Choose --</option>
                    <?php foreach ($distributors as $d): ?><option value="<?php echo htmlspecialchars($d['id']); ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div style="max-height:400px;overflow-y:scroll;border:1px solid #cbd5e1;border-radius:8px;background:white;">
                <table style="box-shadow:none;">
                    <thead><tr><th></th><th>Ticket ID</th><th>Physical #</th><th>Tier</th></tr></thead>
                    <tbody>
                        <?php foreach ($tickets as $row): ?>
                            <?php if (empty($row['allocated_distributor_id'])): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_tickets[]" value="<?php echo htmlspecialchars($row['id']); ?>"></td>
                                    <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                                    <td>#<?php echo (int) $row['physical_number']; ?></td>
                                    <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;margin-top:15px;">Preview Allocation</button>
        </form>
    <?php endif; ?>

<?php elseif ($active_tab === 'analytics'): ?>
    <?php $filterDist = $_GET['filter_distributor'] ?? 'ALL'; ?>
    <form method="GET" style="margin-bottom:15px;">
        <input type="hidden" name="tab" value="analytics">
        <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
        <select name="filter_distributor" onchange="this.form.submit()">
            <option value="ALL">All distributors</option>
            <?php foreach ($distributors as $d): ?>
                <option value="<?php echo htmlspecialchars($d['id']); ?>" <?php echo $filterDist === $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <table>
        <thead><tr><th>Ticket</th><th>Physical #</th><th>Tier</th><th>Handler</th><th>Benefits</th></tr></thead>
        <tbody>
            <?php foreach ($tickets as $row): ?>
                <?php if ($filterDist === 'ALL' || $row['allocated_distributor_id'] === $filterDist): ?>
                    <?php $benefits = ticketBenefits($db, $row['id'], (int) $row['tier_id']); ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($row['id']); ?></code></td>
                        <td>#<?php echo (int) $row['physical_number']; ?></td>
                        <td><?php echo htmlspecialchars($row['tier_name']); ?></td>
                        <td><?php echo empty($row['allocated_distributor_id']) ? 'Vault Pool' : htmlspecialchars($row['allocated_distributor_name']); ?></td>
                        <td>
                            <?php foreach ($benefits as $b): ?>
                                <div><?php echo htmlspecialchars(trim($b['name'])); ?>: [<?php echo (int) $b['used']; ?>/<?php echo (int) $b['max_uses']; ?>]</div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- Benefits modal + toast (Ledger) -->
<div id="admin-toast" class="hidden fixed top-6 inset-x-0 z-[100] flex justify-center px-4 pointer-events-none">
    <div id="admin-toast-inner" class="flex items-center gap-3 bg-white border shadow-xl px-5 py-3 rounded-2xl max-w-sm w-full text-sm font-semibold"></div>
</div>

<div id="benefits-modal" class="hidden fixed inset-0 z-[90] flex items-center justify-center p-4 bg-slate-900/60">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="text-lg font-bold text-slate-900">Ticket Benefits</h3>
            <button type="button" id="benefits-modal-close" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4">
            <div id="benefits-modal-meta" class="grid grid-cols-2 gap-3 text-sm"></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-slate-800 text-white text-left">
                            <th class="px-3 py-2 rounded-tl-lg">Benefit</th>
                            <th class="px-3 py-2">Max</th>
                            <th class="px-3 py-2">Used</th>
                            <th class="px-3 py-2 rounded-tr-lg">Action</th>
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
        inner.className = 'flex items-center gap-3 border shadow-xl px-5 py-3 rounded-2xl max-w-sm w-full text-sm font-semibold '
            + (type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                : type === 'warning' ? 'bg-amber-50 border-amber-200 text-amber-800'
                : 'bg-rose-50 border-rose-200 text-rose-800');
        inner.textContent = message;
        wrap.classList.remove('hidden');
        setTimeout(() => wrap.classList.add('hidden'), 3500);
    }

    function renderBenefitsModal(data) {
        const ticket = data.ticket;
        const benefits = data.benefits || [];
        activeModalTicketId = ticket.id;

        document.getElementById('benefits-modal-meta').innerHTML = `
            <div><span class="block text-[10px] uppercase font-bold text-slate-400">Ticket ID</span><span class="font-mono font-bold">${escapeHtml(ticket.id)}</span></div>
            <div><span class="block text-[10px] uppercase font-bold text-slate-400">Physical #</span><span class="font-bold">#${ticket.physical_number}</span></div>
            <div><span class="block text-[10px] uppercase font-bold text-slate-400">Tier</span><span class="font-semibold">${escapeHtml(ticket.tier_name || '')}</span></div>
            <div><span class="block text-[10px] uppercase font-bold text-slate-400">Event</span><span class="font-semibold">${escapeHtml(ticket.event_name || '')}</span></div>`;

        const tbody = document.getElementById('benefits-modal-body');
        tbody.innerHTML = benefits.map(b => {
            const used = parseInt(b.used, 10);
            const max = parseInt(b.max_uses, 10);
            const full = used >= max;
            const name = String(b.name).trim();
            return `<tr class="border-b border-slate-100">
                <td class="px-3 py-2 font-medium">${escapeHtml(name)}</td>
                <td class="px-3 py-2 font-mono">${max}</td>
                <td class="px-3 py-2 font-mono font-bold benefit-used-cell" data-benefit-name="${escapeAttr(name)}">${used}</td>
                <td class="px-3 py-2">
                    <button type="button" class="simulate-scan-btn px-3 py-1 rounded-lg text-xs font-bold text-white ${full ? 'bg-slate-300 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700'}"
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
</body>
</html>
