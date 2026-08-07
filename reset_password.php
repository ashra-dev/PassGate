<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (isCustomerAuthenticated()) {
    safeRedirect('customer_dashboard.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$email = strtolower(trim((string) ($_GET['email'] ?? $_POST['email'] ?? '')));

$error = '';
$tokenValid = false;

if ($token !== '' && $email !== '') {
    try {
        $db = getDb();
        $tokenValid = findValidPasswordReset($db, $email, $token) !== null;
        if (!$tokenValid && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $error = 'This reset link is invalid or has expired. Request a new one below.';
        }
    } catch (Throwable $e) {
        $error = 'Unable to validate reset link.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Missing reset link. Use the link from your email or request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
        $tokenValid = true;
    } elseif ($token === '' || $email === '') {
        $error = 'Missing reset link. Request a new password reset.';
    } else {
        try {
            $db = getDb();
            if (resetCustomerPassword($db, $email, $token, $password)) {
                safeRedirect('customer_login.php?reset=1');
            }
            $error = 'This reset link is invalid or has expired. Request a new one below.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            $tokenValid = true;
        } catch (Throwable $e) {
            $error = 'Unable to reset password. Please try again.';
            $tokenValid = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Reset Password'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('account'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Log in', 'href' => 'customer_login.php'],
        ['label' => 'Reset password'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;"><i class="fa-solid fa-lock"></i></div>
                <h1 class="pg-brand" style="font-size:1.55rem;margin:0;">Reset password</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.86rem;">
                    Choose a new password for your account.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="pg-notice pg-notice--error" style="margin-top:1.1rem;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($tokenValid): ?>
                <form method="POST" style="margin-top:1.15rem;display:grid;gap:0.85rem;">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <div class="pg-shop-field" style="margin:0;">
                        <label>New password</label>
                        <input type="password" name="password" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="pg-shop-field" style="margin:0;">
                        <label>Confirm password</label>
                        <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password">
                    </div>
                    <button type="submit" class="pg-btn pg-btn--gold" style="width:100%;">Update password</button>
                </form>
            <?php else: ?>
                <p class="pg-muted" style="margin:1.1rem 0 0;text-align:center;font-size:0.82rem;">
                    <a href="forgot_password.php">Request a new reset link</a>
                </p>
            <?php endif; ?>

            <p class="pg-muted" style="margin:1.1rem 0 0;text-align:center;font-size:0.82rem;">
                <a href="customer_login.php">Back to log in</a>
            </p>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
