<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$next = passgateSafeNextUrl($_GET['next'] ?? null, 'terminal.php');

if (isStallAuthenticated()) {
    safeRedirect($next);
}

$error = '';
$loggedOut = isset($_GET['logged_out']);
$locked = isset($_GET['locked']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next = passgateSafeNextUrl($_POST['next'] ?? null, 'terminal.php');

    try {
        $db = getDb();
        ensureStallsSchema($db);
        $stall = authenticateStall($db, $email, $password);

        if ($stall === null) {
            auditLog('AUTH', "Failed staff login for {$email}");
            $error = 'Invalid email or password.';
        } else {
            establishStallSession($stall);
            auditLog('AUTH', "Staff login: {$stall['name']} ({$email})");
            safeRedirect($next . (str_contains($next, '?') ? '&' : '?') . 'welcome=1');
        }
    } catch (Throwable $e) {
        auditLog('AUTH', 'Staff login error: ' . $e->getMessage());
        $error = 'Unable to process login request.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Staff Login'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderStaffNav('staff-login'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Staff login'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;margin-bottom:1.15rem;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <p class="pg-eyebrow" style="margin:0 0 0.35rem;">Staff terminal</p>
                <h1 class="pg-brand" style="font-size:1.7rem;margin:0;">Staff login</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.84rem;line-height:1.45;">
                    Stall workers — sign in with the email and password from your event admin.
                </p>
            </div>

            <?php if ($loggedOut): ?>
                <div class="pg-notice pg-notice--ok" style="margin-bottom:1rem;">You have been logged out.</div>
            <?php endif; ?>
            <?php if ($locked): ?>
                <div class="pg-notice pg-notice--info" style="margin-bottom:1rem;">Terminal locked. Log in again to scan.</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="pg-alert" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                <label class="pg-label" for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="username" class="pg-input"
                       placeholder="stall@event.com"
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
                · Ticket distributor?
                <a href="distributor_login.php">Distributor login</a>
                · <a href="index.php">Home</a>
            </p>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
