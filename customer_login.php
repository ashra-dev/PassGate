<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$next = passgateSafeNextUrl($_GET['next'] ?? ($_POST['next'] ?? null), 'customer_dashboard.php');

if (isCustomerAuthenticated()) {
    safeRedirect($next);
}

$error = '';
$registered = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next = passgateSafeNextUrl($_POST['next'] ?? null, 'customer_dashboard.php');

    try {
        $db = getDb();
        ensureCustomerSchema($db);
        $customer = authenticateCustomer($db, $email, $password);

        if ($customer === null) {
            auditLog('AUTH', "Failed customer login for {$email}");
            $error = 'Invalid email or password. New here? Create an account first.';
        } else {
            establishCustomerSession($customer);
            auditLog('AUTH', "Customer login: {$email}");
            safeRedirect($next);
        }
    } catch (Throwable $e) {
        $error = 'Unable to process login.';
    }
}

$goingToBuy = $next === 'buy.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Log in'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('account'); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;"><i class="fa-solid fa-user"></i></div>
                <h1 class="pg-brand" style="font-size:1.55rem;margin:0;">Log in</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.86rem;">
                    <?php if ($goingToBuy): ?>
                        Log in to continue to buy tickets.
                    <?php else: ?>
                        Returning customers — open your saved tickets.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($registered): ?>
                <div class="pg-notice pg-notice--ok" style="margin-top:1.1rem;">Account ready. Log in to continue.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="pg-notice pg-notice--error" style="margin-top:1.1rem;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" style="margin-top:1.15rem;display:grid;gap:0.85rem;">
                <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                <div class="pg-shop-field" style="margin:0;">
                    <label>Email</label>
                    <input type="email" name="email" required autocomplete="username" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <div class="pg-shop-field" style="margin:0;">
                    <label>Password</label>
                    <input type="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="pg-btn pg-btn--gold" style="width:100%;">
                    <?php echo $goingToBuy ? 'Log in & continue' : 'Log in'; ?>
                </button>
            </form>

            <p class="pg-muted" style="margin:1.1rem 0 0;text-align:center;font-size:0.82rem;">
                New here?
                <a href="customer_register.php?next=<?php echo urlencode($next); ?>">Create account</a>
            </p>
        </div>
    </div>
</body>
</html>
