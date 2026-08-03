<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (isStallAuthenticated()) {
    safeRedirect('terminal.php');
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
            safeRedirect('terminal.php');
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
  <?php passgateRenderHead('PassGate – Stall Login'); ?>
</head>
<body class="pg-body">
  <div class="pg-auth-stage">
    <div class="pg-card pg-card--auth">
      <div style="text-align:center;margin-bottom:1.15rem;">
        <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
          <i class="fa-solid fa-store"></i>
        </div>
        <p class="pg-eyebrow" style="margin:0 0 0.35rem;">Station Gate</p>
        <h1 class="pg-brand" style="font-size:1.7rem;margin:0;">Stall Login</h1>
        <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.8rem;">Unlock the scan terminal for your stand.</p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="pg-alert" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="POST">
        <label class="pg-label" for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="username" class="pg-input"
               placeholder="stall@event.com"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        <label class="pg-label" for="password" style="margin-top:0.85rem;">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password" class="pg-input">
        <div style="margin-top:1.1rem;">
          <button type="submit" class="pg-btn pg-btn--gold">Log In</button>
        </div>
      </form>

      <p class="pg-links" style="margin-top:1rem;">
        <a href="terminal.php">Back to terminal</a>
      </p>
    </div>
  </div>
</body>
</html>
