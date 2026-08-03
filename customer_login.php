<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

if (isCustomerAuthenticated()) {
    safeRedirect('customer_dashboard.php');
}

$error = '';
$registered = isset($_GET['registered']);
$purchaseSuccess = isset($_GET['purchase']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $db = getDb();
        $customer = authenticateCustomer($db, $email, $password);

        if ($customer === null) {
            auditLog('AUTH', "Failed customer login for {$email}");
            $error = 'Invalid email or password.';
        } else {
            establishCustomerSession($customer);
            auditLog('AUTH', "Customer login: {$email}");
            safeRedirect('customer_dashboard.php');
        }
    } catch (Throwable $e) {
        $error = 'Unable to process login.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Login – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-6 shadow-2xl border border-slate-100">
    <div class="text-center space-y-2">
      <h1 class="text-xl font-bold text-slate-900">Customer Login</h1>
      <p class="text-xs text-slate-400">View your purchased tickets and QR codes.</p>
    </div>

    <?php if ($registered): ?>
      <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-3 py-2">Account created. Please log in.</div>
    <?php endif; ?>
    <?php if ($purchaseSuccess): ?>
      <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-3 py-2">Payment successful! Check your email for your ticket QR. Log in to view all tickets.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl px-3 py-2"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5">Email</label>
        <input type="email" name="email" required autocomplete="username" class="w-full bg-slate-100 rounded-xl px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5">Password</label>
        <input type="password" name="password" required autocomplete="current-password" class="w-full bg-slate-100 rounded-xl px-3 py-2.5 text-sm">
      </div>
      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-3 text-sm">Log In</button>
    </form>

    <p class="text-center text-xs text-slate-400">
      <a href="customer_register.php" class="text-indigo-500 hover:underline">Create account</a>
      · <a href="buy.php" class="text-emerald-600 hover:underline">Buy tickets</a>
      · <a href="index.php" class="text-slate-500 hover:underline">Terminal</a>
    </p>
  </div>
</body>
</html>
