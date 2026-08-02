<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PassGate Pro – Ticket QR Codes</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            .tier-section { break-inside: avoid-page; page-break-inside: avoid; }
            .qr-card { break-inside: avoid; page-break-inside: avoid; }
            body { background: white; }
        }
        .qr-card canvas, .qr-card img { margin: 0 auto; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen">
    <div class="max-w-6xl mx-auto p-6 space-y-6">
        <div class="no-print flex flex-wrap items-center justify-between gap-4 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
            <div>
                <h1 class="text-xl font-bold">Ticket QR Codes</h1>
                <p class="text-sm text-slate-500">
                    <?php echo htmlspecialchars($currentEvent['name'] ?? 'Event'); ?>
                    · <?php echo (int) $totalTickets; ?> ticket(s)
                </p>
            </div>
            <div class="flex flex-wrap gap-2 items-center">
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                    <label class="text-xs font-bold uppercase text-slate-400">Tier</label>
                    <select name="tier_id" onchange="this.form.submit()" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
                        <option value="">All tiers</option>
                        <?php foreach ($tiers as $tier): ?>
                            <option value="<?php echo (int) $tier['id']; ?>" <?php echo $filterTierId === (int) $tier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tier['name']); ?> (<?php echo (int) $tier['quantity']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button type="button" onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl px-4 py-2 text-sm">
                    Print / Save PDF
                </button>
                <a href="distributors.php?<?php echo adminEventQuery($eventId, 'events'); ?>" class="bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold rounded-xl px-4 py-2 text-sm">
                    Back to Admin
                </a>
            </div>
        </div>

        <?php if ($ticketsByTier === []): ?>
            <div class="bg-white rounded-2xl p-8 text-center text-slate-500 border border-slate-100">
                No tickets found for this event.
            </div>
        <?php else: ?>
            <?php foreach ($ticketsByTier as $tierName => $tickets): ?>
                <section class="tier-section bg-white rounded-2xl p-5 shadow-sm border border-slate-100 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h2 class="text-lg font-bold"><?php echo htmlspecialchars($tierName); ?></h2>
                        <span class="text-xs font-bold uppercase text-slate-400"><?php echo count($tickets); ?> tickets</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                        <?php foreach ($tickets as $ticket): ?>
                            <div class="qr-card border border-slate-200 rounded-xl p-3 text-center space-y-2 bg-slate-50">
                                <div class="qr-target flex justify-center min-h-[128px] items-center"
                                     data-ticket-id="<?php echo htmlspecialchars($ticket['id'], ENT_QUOTES); ?>"></div>
                                <div class="font-mono text-[11px] font-bold break-all leading-tight">
                                    <?php echo htmlspecialchars($ticket['id']); ?>
                                </div>
                                <div class="text-[10px] text-slate-500">
                                    #<?php echo (int) $ticket['physical_number']; ?>
                                    · $<?php echo number_format((float) $ticket['price'], 2); ?>
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
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M,
            });
        });
    </script>
</body>
</html>
