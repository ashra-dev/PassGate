<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

redirectIfAuthenticated();

$token = trim($_GET['token'] ?? '');
$email = strtolower(trim($_GET['email'] ?? ''));
$error = null;

if ($token !== '' && $email !== '') {
    try {
        $db = getDb();
        $result = completeMagicLinkLogin($db, $email, $token);

        if ($result['success'] && $result['redirect'] !== null) {
            safeRedirect($result['redirect']);
        }

        $error = $result['error'] ?? 'Invalid or expired login link.';
    } catch (Throwable $e) {
        auditLog('AUTH', 'Login verification error: ' . $e->getMessage());
        $error = 'An error occurred during login. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Login'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderStaffNav('admin-login'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Staff', 'href' => 'terminal.php'],
        ['label' => 'Login'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth" style="text-align:center;">
            <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <h1 class="pg-brand" style="font-size:1.75rem;margin:0;">PassGate</h1>

            <?php if ($error): ?>
                <div class="pg-alert" style="margin-top:1.25rem;text-align:left;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <div style="margin-top:1rem;display:grid;gap:0.55rem;">
                    <a class="pg-btn pg-btn--gold" href="admin_login.php">Request new login link</a>
                    <a class="pg-btn pg-btn--ghost" href="manual_login.php">Paste token manually</a>
                    <a class="pg-btn pg-btn--ghost" href="terminal.php">Staff scanner</a>
                </div>
            <?php else: ?>
                <p class="pg-muted" style="margin:1rem 0 0;font-size:0.9rem;line-height:1.45;">
                    Open the link from your email, or request a new one below.
                </p>
                <div style="margin-top:1.25rem;display:grid;gap:0.55rem;">
                    <a class="pg-btn pg-btn--gold" href="admin_login.php">Admin login (email link)</a>
                    <a class="pg-btn pg-btn--ghost" href="manual_login.php">Paste token manually</a>
                    <a class="pg-btn pg-btn--ghost" href="index.php">Go to home</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
