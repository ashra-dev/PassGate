<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

$ticketId = trim($_GET['ticket'] ?? '');
$gateway = trim($_GET['gateway'] ?? 'online');
$isLoggedIn = isCustomerAuthenticated();

if ($ticketId !== '' && $isLoggedIn) {
    safeRedirect('customer_dashboard.php?new=1&ticket=' . urlencode($ticketId));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Thank You – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl border border-slate-100 p-8 max-w-md w-full text-center space-y-5">
    <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto">
      <i class="fa-solid fa-circle-check text-emerald-600 text-2xl"></i>
    </div>
    <h1 class="text-xl font-bold text-slate-900">Payment successful!</h1>
    <p class="text-sm text-slate-600">
      Your ticket has been assigned<?php echo $gateway === 'esewa' ? ' via eSewa' : ''; ?>.
      <?php if ($ticketId !== ''): ?>
        Check your email for the QR code, or view it in your dashboard.
      <?php endif; ?>
    </p>
    <?php if ($ticketId !== ''): ?>
      <p class="font-mono text-xs bg-slate-100 rounded-lg px-3 py-2 break-all"><?php echo htmlspecialchars($ticketId); ?></p>
    <?php endif; ?>
    <div class="flex flex-col gap-2">
      <?php if ($isLoggedIn): ?>
        <a href="customer_dashboard.php<?php echo $ticketId !== '' ? '?new=1&ticket=' . urlencode($ticketId) : ''; ?>"
           class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-2.5 text-sm">View my tickets</a>
      <?php else: ?>
        <a href="customer_login.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-2.5 text-sm">Log in to view tickets</a>
        <a href="customer_register.php" class="text-indigo-600 text-sm font-semibold hover:underline">Create account</a>
      <?php endif; ?>
      <a href="buy.php" class="text-slate-500 text-sm hover:text-slate-700">Buy more tickets</a>
    </div>
  </div>
</body>
</html>
