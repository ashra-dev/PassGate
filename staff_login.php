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
$categoryChoices = [];
$pendingEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = passgateSafeNextUrl($_POST['next'] ?? null, 'terminal.php');

    try {
        $db = getDb();
        ensureStallsSchema($db);

        if (isset($_POST['stall_category'])) {
            $category = trim($_POST['stall_category'] ?? '');
            if (!completeStallCategoryLogin($db, $category)) {
                $error = 'Invalid or expired category selection. Please log in again.';
            } else {
                auditLog('AUTH', "Staff category login: {$category} ({$_SESSION['stall_email']})");
                safeRedirect($next . (str_contains($next, '?') ? '&' : '?') . 'welcome=1');
            }
        } else {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $attempt = stallLoginAttempt($db, $email, $password);

            if ($attempt['result'] === 'success') {
                auditLog('AUTH', "Staff login: category {$attempt['category']} ({$email})");
                safeRedirect($next . (str_contains($next, '?') ? '&' : '?') . 'welcome=1');
            } elseif ($attempt['result'] === 'choose_category') {
                $categoryChoices = $attempt['categories'] ?? [];
                $pendingEmail = (string) ($attempt['email'] ?? $email);
            } else {
                auditLog('AUTH', "Failed staff login for {$email}");
                $error = 'Invalid email or password.';
            }
        }
    } catch (Throwable $e) {
        auditLog('AUTH', 'Staff login error: ' . $e->getMessage());
        $error = 'Unable to process login request.';
    }
} elseif (!empty($_SESSION['stall_login_pending']['categories'])) {
    $categoryChoices = (array) $_SESSION['stall_login_pending']['categories'];
    $pendingEmail = (string) ($_SESSION['stall_login_pending']['email'] ?? '');
}

$showCategoryPicker = $categoryChoices !== [];
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
                    Multiple stalls can share one email. Scans are tracked by category.
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

            <?php if ($showCategoryPicker): ?>
                <p class="pg-muted" style="margin:0 0 0.85rem;font-size:0.88rem;">
                    Signed in as <strong><?php echo htmlspecialchars($pendingEmail); ?></strong>.
                    Choose which category you are scanning for:
                </p>
                <form method="POST" style="display:grid;gap:0.55rem;">
                    <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
                    <?php foreach ($categoryChoices as $cat): ?>
                        <button type="submit" name="stall_category" value="<?php echo htmlspecialchars($cat); ?>"
                                class="pg-btn pg-btn--gold" style="width:100%;text-transform:capitalize;">
                            <?php echo htmlspecialchars(ucfirst($cat)); ?>
                        </button>
                    <?php endforeach; ?>
                </form>
                <p class="pg-links" style="margin-top:1rem;text-align:center;font-size:0.82rem;">
                    <a href="staff_login.php">Use a different account</a>
                </p>
            <?php else: ?>
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
            <?php endif; ?>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>
</body>
</html>
