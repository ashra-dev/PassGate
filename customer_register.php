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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $next = passgateSafeNextUrl($_POST['next'] ?? null, 'customer_dashboard.php');

    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db = getDb();
            ensureCustomerSchema($db);
            registerCustomer($db, $name, $email, $password);
            $customer = authenticateCustomer($db, $email, $password);
            if ($customer !== null) {
                establishCustomerSession($customer);
                auditLog('AUTH', "Customer registered + signed in: {$email}");
                safeRedirect($next);
            }
            auditLog('AUTH', "Customer registered: {$email}");
            safeRedirect('customer_login.php?registered=1&next=' . urlencode($next));
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$goingToBuy = $next === 'buy.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Create Account'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('account'); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;"><i class="fa-solid fa-user-plus"></i></div>
                <h1 class="pg-brand" style="font-size:1.55rem;margin:0;">Create account</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.86rem;line-height:1.45;">
                    <?php if ($goingToBuy): ?>
                        Sign up, then continue to buy tickets.
                    <?php else: ?>
                        Sign up to buy tickets and manage your passes.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="pg-notice pg-notice--error" style="margin-top:1.1rem;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" style="margin-top:1.15rem;display:grid;gap:0.85rem;">
                <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                <div class="pg-shop-field" style="margin:0;">
                    <label>Full name</label>
                    <input type="text" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>
                <div class="pg-shop-field" style="margin:0;">
                    <label>Email</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <div class="pg-shop-field" style="margin:0;">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="pg-shop-field" style="margin:0;">
                    <label>Confirm password</label>
                    <input type="password" name="password_confirm" required minlength="6">
                </div>
                <button type="submit" class="pg-btn pg-btn--gold" style="width:100%;">
                    <?php echo $goingToBuy ? 'Create account & continue' : 'Create account'; ?>
                </button>
            </form>

            <p class="pg-muted" style="margin:1.1rem 0 0;text-align:center;font-size:0.82rem;">
                Already have an account?
                <a href="customer_login.php?next=<?php echo urlencode($next); ?>">Log in</a>
            </p>
        </div>
    </div>
</body>
</html>
