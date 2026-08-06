<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$next = passgateSafeNextUrl($_GET['next'] ?? null, 'distributor_dashboard.php');

if (isDistributorAuthenticated()) {
    safeRedirect(distributorLoginRedirectPath((string) ($_SESSION['distributor_role'] ?? '')));
}

$error = '';
$loggedOut = isset($_GET['logged_out']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next = passgateSafeNextUrl($_POST['next'] ?? null, 'distributor_dashboard.php');

    try {
        $db = getDb();
        ensureDistributorsSchema($db);
        $distributor = authenticateDistributor($db, $email, $password);

        if ($distributor === null) {
            auditLog('AUTH', "Failed distributor login for {$email}");
            $error = 'Invalid email or password.';
        } else {
            establishDistributorSession($distributor);
            auditLog('AUTH', "Distributor login: {$distributor['name']} ({$email})");
            safeRedirect(distributorLoginRedirectPath((string) $distributor['role']));
        }
    } catch (Throwable $e) {
        auditLog('AUTH', 'Distributor login error: ' . $e->getMessage());
        $error = 'Unable to process login request.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Distributor Login'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderStaffNav('distributor-login'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Distributor login'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;margin-bottom:1.15rem;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
                    <i class="fa-solid fa-building"></i>
                </div>
                <p class="pg-eyebrow" style="margin:0 0 0.35rem;">Distributor portal</p>
                <h1 class="pg-brand" style="font-size:1.7rem;margin:0;">Distributor login</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.84rem;line-height:1.45;">
                    Ticket distributors — sign in with the email and password from your event admin.
                </p>
            </div>

            <?php if ($loggedOut): ?>
                <div class="pg-notice pg-notice--ok" style="margin-bottom:1rem;">You have been logged out.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="pg-alert" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                <label class="pg-label" for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" class="pg-input"
                       placeholder="ops@company.com"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <label class="pg-label" for="password" style="margin-top:0.85rem;">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" class="pg-input">
                <div style="margin-top:1.1rem;">
                    <button type="submit" class="pg-btn pg-btn--gold" style="width:100%;">Log in</button>
                </div>
            </form>

            <p class="pg-links" style="margin-top:1rem;text-align:center;font-size:0.82rem;">
                Event admin?
                <a href="admin_login.php">Admin login</a>
                · Stall staff?
                <a href="staff_login.php">Staff login</a>
            </p>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
