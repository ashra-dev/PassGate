<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

if (isCustomerAuthenticated()) {
    safeRedirect('customer_dashboard.php');
}

$error = '';
$success = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db = getDb();
            registerCustomer($db, $name, $email, $password);
            auditLog('AUTH', "Customer registered: {$email}");
            safeRedirect('customer_login.php?registered=1');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-6 shadow-2xl border border-slate-100">
    <div class="text-center space-y-2">
      <h1 class="text-xl font-bold text-slate-900">Create Account</h1>
      <p class="text-xs text-slate-400">Register to view tickets you purchased online.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl px-3 py-2"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5">Full Name</label>
        <input type="text" name="name" required class="w-full bg-slate-100 rounded-xl px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5">Email</label>
        <input type="email" name="email" required class="w-full bg-slate-100 rounded-xl px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5">Password</label>
        <input type="password" name="password" required minlength="6" class="w-full bg-slate-100 rounded-xl px-3 py-2.5 text-sm">
      </div>
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5">Confirm Password</label>
        <input type="password" name="password_confirm" required minlength="6" class="w-full bg-slate-100 rounded-xl px-3 py-2.5 text-sm">
      </div>
      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-3 text-sm">Register</button>
    </form>

    <p class="text-center text-xs text-slate-400">
      <a href="customer_login.php" class="text-indigo-500 hover:underline">Already have an account?</a>
      · <a href="buy.php" class="text-emerald-600 hover:underline">Buy tickets</a>
    </p>
  </div>
</body>
</html>
