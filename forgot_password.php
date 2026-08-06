<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (isCustomerAuthenticated()) {
    safeRedirect('customer_dashboard.php');
}

$sent = isset($_GET['sent']);
$appDebug = env('APP_DEBUG', '0') === '1';
$smtpConfigured = trim(env('MAIL_HOST', '') ?? '') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    try {
        $db = getDb();
        requestCustomerPasswordReset($db, $email);
    } catch (Throwable $e) {
        // Do not reveal internal errors — still show the generic success message.
        auditLog('AUTH', 'Password reset request failed: ' . $e->getMessage());
    }

    safeRedirect('forgot_password.php?sent=1');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Forgot Password'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('account'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Log in', 'href' => 'customer_login.php'],
        ['label' => 'Forgot password'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;"><i class="fa-solid fa-key"></i></div>
                <h1 class="pg-brand" style="font-size:1.55rem;margin:0;">Forgot password</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.86rem;">
                    Enter your account email and we&rsquo;ll send a reset link if it&rsquo;s registered.
                </p>
            </div>

            <?php if ($appDebug || !$smtpConfigured): ?>
                <div class="pg-dev-ribbon" style="margin-top:1.1rem;">
                    Dev mode: reset links are written to <code class="pg-mono">dev_login.log</code>, not emailed.
                </div>
            <?php endif; ?>

            <?php if ($sent): ?>
                <div class="pg-notice pg-notice--ok" style="margin-top:1.1rem;">
                    <?php if ($appDebug || !$smtpConfigured): ?>
                        If that email is registered, a reset link was written to
                        <code class="pg-mono">dev_login.log</code>.
                    <?php else: ?>
                        If that email is registered, you&rsquo;ll receive a reset link shortly. Check your inbox and spam folder.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="POST" style="margin-top:1.15rem;display:grid;gap:0.85rem;">
                    <div class="pg-shop-field" style="margin:0;">
                        <label>Email</label>
                        <input type="email" name="email" required autocomplete="username" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="pg-btn pg-btn--gold" style="width:100%;">Send reset link</button>
                </form>
            <?php endif; ?>

            <p class="pg-muted" style="margin:1.1rem 0 0;text-align:center;font-size:0.82rem;">
                Remember your password?
                <a href="customer_login.php">Back to log in</a>
            </p>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
