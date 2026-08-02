<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

if (isStallAuthenticated()) {
    safeRedirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $db = getDb();
        ensureStallsSchema($db);
        $stall = authenticateStall($db, $email, $password);

        if ($stall === null) {
            auditLog('AUTH', "Failed stall login (page) for {$email}");
            $error = 'Invalid email or password.';
        } else {
            establishStallSession($stall);
            auditLog('AUTH', "Stall login success (page): {$stall['name']} ({$email})");
            safeRedirect('index.php');
        }
    } catch (Throwable $e) {
        auditLog('AUTH', 'Stall login page error: ' . $e->getMessage());
        $error = 'Unable to process login request.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stall Login – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com" defer></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-6 shadow-2xl border border-slate-100">
    <div class="text-center space-y-2">
      <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto text-xl">
        <i class="fa-solid fa-store"></i>
      </div>
      <h1 class="text-xl font-bold text-slate-900">Stall Login</h1>
      <p class="text-xs text-slate-400">Sign in to unlock the scan terminal for your stand.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl px-3 py-2">
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1">Email</label>
        <input type="email" name="email" required autocomplete="username"
               class="w-full bg-slate-100 text-sm rounded-xl px-3 py-2.5 border-none"
               placeholder="stall@event.com"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1">Password</label>
        <input type="password" name="password" required autocomplete="current-password"
               class="w-full bg-slate-100 text-sm rounded-xl px-3 py-2.5 border-none">
      </div>
      <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl py-3 text-sm transition-all shadow-md">
        Log In
      </button>
    </form>

    <p class="text-center text-xs text-slate-400">
      <a href="index.php" class="text-indigo-500 hover:underline">Back to terminal</a>
    </p>
  </div>
</body>
</html>
