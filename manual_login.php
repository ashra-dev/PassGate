<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

redirectIfAuthenticated();

$error = null;
$emailValue = '';
$tokenValue = '';
$urlValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = strtolower(trim($_POST['email'] ?? ''));
    $tokenValue = preg_replace('/\s+/', '', trim($_POST['token'] ?? ''));
    $urlValue = trim($_POST['login_url'] ?? '');

    if ($urlValue !== '') {
        $parsed = parseMagicLinkInput($urlValue);
        if ($parsed['email'] !== '') {
            $emailValue = $parsed['email'];
        }
        if ($parsed['token'] !== '') {
            $tokenValue = $parsed['token'];
        }
    }

    try {
        $db = getDb();
        $result = completeMagicLinkLogin($db, $emailValue, $tokenValue);

        if ($result['success'] && $result['redirect'] !== null) {
            safeRedirect($result['redirect']);
        }

        $error = $result['error'] ?? 'Invalid or expired token. Please request a new login link.';
    } catch (Throwable $e) {
        auditLog('AUTH', 'Manual login error: ' . $e->getMessage());
        $error = 'An error occurred during login. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Manual Login'); ?>
</head>
<body class="pg-body">
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;margin-bottom:1.15rem;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
                    <i class="fa-solid fa-key"></i>
                </div>
                <p class="pg-eyebrow" style="margin:0 0 0.35rem;">Admin login</p>
                <h1 class="pg-brand" style="font-size:1.7rem;margin:0;">Manual Login</h1>
                <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.8rem;">
                    Paste your login token or the full link from email / <span class="pg-mono">dev_login.log</span>.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="pg-alert" style="margin-bottom:1rem;">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <label class="pg-label" for="login_url">Full login URL (optional)</label>
                <input type="text" id="login_url" name="login_url" class="pg-input"
                       value="<?php echo htmlspecialchars($urlValue); ?>"
                       placeholder="http://localhost:8000/login.php?token=...&email=...">
                <p class="pg-hint">If provided, email and token below fill automatically.</p>

                <label class="pg-label" for="email" style="margin-top:0.85rem;">Email</label>
                <input type="email" id="email" name="email" required class="pg-input"
                       value="<?php echo htmlspecialchars($emailValue); ?>"
                       placeholder="you@company.com">

                <label class="pg-label" for="token" style="margin-top:0.85rem;">Login token</label>
                <input type="text" id="token" name="token" required class="pg-input pg-mono"
                       value="<?php echo htmlspecialchars($tokenValue); ?>"
                       placeholder="64-character token"
                       minlength="64" maxlength="64"
                       pattern="[a-fA-F0-9]{64}">

                <div style="margin-top:1.1rem;">
                    <button type="submit" class="pg-btn pg-btn--gold">Log In</button>
                </div>
            </form>

            <p class="pg-links" style="margin-top:1rem;">
                Need a new link? <a href="terminal.php">Request one from staff login</a>
                · <a href="index.php">Home</a>
            </p>
        </div>
    </div>

    <script>
        document.getElementById('login_url').addEventListener('change', function () {
            const raw = this.value.trim();
            if (!raw) return;
            try {
                const url = new URL(raw);
                const email = url.searchParams.get('email');
                const token = url.searchParams.get('token');
                if (email) document.getElementById('email').value = email;
                if (token) document.getElementById('token').value = token;
            } catch (e) {
                // Server-side parse handles non-URL paste on submit
            }
        });
    </script>
</body>
</html>
