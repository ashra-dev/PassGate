<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PassGate Pro – Ticket Validation</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen">
    <div class="max-w-lg mx-auto p-6 space-y-6">
        <div class="text-center space-y-2 pt-8">
            <i class="fa-solid fa-ticket text-4xl text-indigo-600"></i>
            <h1 class="text-2xl font-bold">Ticket Validation</h1>
            <p class="text-sm text-slate-500">Enter a ticket ID to verify its status and benefits.</p>
        </div>

        <form method="POST" class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100 space-y-4">
            <label class="block text-xs uppercase font-bold text-slate-400">Ticket ID</label>
            <input type="text" name="ticket_id" value="<?php echo htmlspecialchars($submittedId); ?>"
                   placeholder="e.g. WOR-ASH-1"
                   class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500"
                   required autofocus>
            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-3 text-sm">
                Validate Ticket
            </button>
        </form>

        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl p-4 text-sm">
                <i class="fa-solid fa-circle-xmark mr-1"></i>
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
            ?>
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="font-mono font-bold text-lg"><?php echo htmlspecialchars($ticket['id']); ?></span>
                    <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $displayStatus === 'Active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                        <?php echo htmlspecialchars($displayStatus); ?>
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400">Event</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($ticket['event_name']); ?></span>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400">Tier</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($ticket['tier_name']); ?></span>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400">Physical #</span>
                        <span class="font-semibold">#<?php echo (int) $ticket['physical_number']; ?></span>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400">Allocated To</span>
                        <span class="font-semibold">
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

                <div>
                    <h3 class="text-xs uppercase font-bold text-slate-400 mb-2">Benefits</h3>
                    <div class="overflow-x-auto rounded-xl border border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-800 text-white text-left">
                                    <th class="px-4 py-2.5 font-bold">Benefit</th>
                                    <th class="px-4 py-2.5 font-bold">Max</th>
                                    <th class="px-4 py-2.5 font-bold">Used</th>
                                    <th class="px-4 py-2.5 font-bold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($benefits as $benefit): ?>
                                    <?php
                                    $used = (int) $benefit['used'];
                                    $max = (int) $benefit['max_uses'];
                                    $isFull = $used >= $max;
                                    ?>
                                    <tr class="border-t border-slate-100 <?php echo $isFull ? 'bg-rose-50' : 'bg-white'; ?>">
                                        <td class="px-4 py-3 font-medium"><?php echo htmlspecialchars(trim($benefit['name'])); ?></td>
                                        <td class="px-4 py-3 font-mono"><?php echo $max; ?></td>
                                        <td class="px-4 py-3 font-mono font-bold"><?php echo $used; ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($isFull): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-700">Fully used</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700"><?php echo $max - $used; ?> left</span>
                                            <?php endif; ?>
                                            <?php if (!empty($benefit['last_scan'])): ?>
                                                <span class="block text-[10px] text-slate-400 mt-1">Last: <?php echo htmlspecialchars(substr((string) $benefit['last_scan'], 0, 16)); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <p class="text-center text-xs text-slate-400"><a href="index.php" class="text-indigo-500 hover:underline">Terminal Login</a></p>
    </div>
</body>
</html>
