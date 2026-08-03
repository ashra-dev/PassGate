<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

requireCustomerAuth();

$db = getDb();
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Tickets – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen">
  <header class="bg-indigo-600 text-white px-4 py-4 flex items-center justify-between">
    <div>
      <h1 class="font-bold text-lg">My Tickets</h1>
      <p class="text-indigo-200 text-xs"><?php echo htmlspecialchars($customerName ?: $customerEmail); ?></p>
    </div>
    <div class="flex items-center gap-3 text-sm">
      <a href="buy.php" class="text-indigo-100 hover:text-white">Buy more</a>
      <a href="customer_logout.php" class="bg-indigo-700 px-3 py-1.5 rounded-lg font-bold">Logout</a>
    </div>
  </header>

  <main class="max-w-3xl mx-auto p-4 space-y-4">
    <?php if ($showNewBanner): ?>
      <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-start gap-3">
        <i class="fa-solid fa-circle-check text-emerald-600 mt-0.5"></i>
        <div>
          <p class="font-bold text-emerald-900">Payment successful!</p>
          <p class="text-sm text-emerald-700 mt-1">Your ticket is ready. Show the QR code at the gate or download it below.</p>
        </div>
      </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-100 p-4 flex justify-between items-center">
      <span class="text-sm text-slate-500">Total tickets</span>
      <span class="text-2xl font-bold text-indigo-600"><?php echo count($tickets); ?></span>
    </div>

    <?php if ($tickets === []): ?>
      <div class="bg-white rounded-2xl border border-slate-100 p-8 text-center text-slate-500">
        <p class="mb-4">You have no tickets yet.</p>
        <a href="buy.php" class="inline-block bg-indigo-600 text-white font-bold px-5 py-2 rounded-xl">Browse events</a>
      </div>
    <?php else: ?>
      <?php foreach ($tickets as $ticket): ?>
        <?php
          $isNew = $highlightTicket !== '' && $ticket['id'] === $highlightTicket;
          $qrUrl = 'qr.php?id=' . urlencode($ticket['id']);
          $downloadUrl = $qrUrl . '&download=1';
        ?>
        <article class="bg-white rounded-2xl border p-5 space-y-4 <?php echo $isNew ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-slate-100'; ?>">
          <div class="flex flex-wrap justify-between gap-2 border-b border-slate-100 pb-3">
            <div>
              <h2 class="font-bold text-slate-900"><?php echo htmlspecialchars($ticket['event_name']); ?></h2>
              <p class="text-sm text-slate-500"><?php echo htmlspecialchars($ticket['tier_name']); ?> · #<?php echo (int) $ticket['physical_number']; ?></p>
            </div>
            <code class="text-xs font-mono bg-slate-100 px-2 py-1 rounded-lg"><?php echo htmlspecialchars($ticket['id']); ?></code>
          </div>

          <div class="flex flex-col sm:flex-row gap-4 items-center sm:items-start">
            <div class="bg-slate-50 rounded-xl p-3 shrink-0">
              <img src="<?php echo htmlspecialchars($qrUrl); ?>"
                   alt="QR code for ticket <?php echo htmlspecialchars($ticket['id']); ?>"
                   class="w-36 h-36 border border-slate-200 rounded-lg bg-white"
                   width="144" height="144">
            </div>
            <div class="flex-1 w-full space-y-3">
              <p class="text-xs text-slate-500 text-center sm:text-left">Scan this at entry. Download to save on your phone.</p>
              <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                <button type="button"
                        onclick="openQrModal('<?php echo htmlspecialchars($ticket['id'], ENT_QUOTES); ?>')"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-4 py-2 rounded-xl">
                  <i class="fa-solid fa-expand mr-1"></i> Enlarge
                </button>
                <a href="<?php echo htmlspecialchars($downloadUrl); ?>"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold px-4 py-2 rounded-xl inline-flex items-center">
                  <i class="fa-solid fa-download mr-1"></i> Download QR
                </a>
              </div>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-[10px] uppercase text-slate-400">
                  <th class="pb-2">Benefit</th>
                  <th class="pb-2">Used</th>
                  <th class="pb-2">Max</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ticket['benefits'] as $benefit): ?>
                  <?php $full = (int) $benefit['used'] >= (int) $benefit['max_uses']; ?>
                  <tr class="border-t border-slate-50">
                    <td class="py-2 font-medium"><?php echo htmlspecialchars(trim($benefit['name'])); ?></td>
                    <td class="py-2 font-mono <?php echo $full ? 'text-rose-600' : ''; ?>"><?php echo (int) $benefit['used']; ?></td>
                    <td class="py-2 font-mono"><?php echo (int) $benefit['max_uses']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <div id="qr-modal" class="hidden fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl p-6 max-w-sm w-full text-center space-y-4">
      <h3 class="font-bold text-lg">Ticket QR Code</h3>
      <img id="qr-image" src="" alt="QR code" class="mx-auto border border-slate-200 rounded-xl" width="280" height="280">
      <p id="qr-ticket-id" class="font-mono text-xs break-all text-slate-600"></p>
      <a id="qr-download" href="#" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm px-5 py-2 rounded-xl">
        <i class="fa-solid fa-download mr-1"></i> Download QR
      </a>
      <button type="button" onclick="closeQrModal()" class="block w-full mt-2 text-slate-500 text-sm">Close</button>
    </div>
  </div>

  <script>
    function openQrModal(ticketId) {
      const url = 'qr.php?id=' + encodeURIComponent(ticketId);
      const downloadUrl = url + '&download=1';
      document.getElementById('qr-image').src = url;
      document.getElementById('qr-ticket-id').textContent = ticketId;
      document.getElementById('qr-download').href = downloadUrl;
      document.getElementById('qr-modal').classList.remove('hidden');
    }
    function closeQrModal() {
      document.getElementById('qr-modal').classList.add('hidden');
    }
    <?php if ($hasHighlight): ?>
    document.querySelector('[class*="ring-emerald"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    <?php endif; ?>
  </script>
</body>
</html>
