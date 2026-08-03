<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

redirectIfAuthenticated();

$app_debug = env('APP_DEBUG', '0') === '1';
$smtp_configured = trim(env('MAIL_HOST', '') ?? '') !== '';
$sent = isset($_GET['sent']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Admin Login'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderStaffNav('admin-login'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Staff', 'href' => 'terminal.php'],
        ['label' => 'Admin login'],
    ]); ?>
    <div class="pg-auth-stage">
        <div class="pg-card pg-card--auth">
            <div style="text-align:center;margin-bottom:1.1rem;">
                <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
                    <i class="fa-solid fa-gauge-high"></i>
                </div>
                <h1 class="pg-brand" style="font-size:1.65rem;margin:0;">Admin login</h1>
                <p class="pg-muted" style="margin:0.5rem 0 0;font-size:0.86rem;line-height:1.45;">
                    Event organizers and admins sign in with a one-time email link — no password on this page.
                </p>
            </div>

            <?php if ($app_debug || !$smtp_configured): ?>
                <div class="pg-dev-ribbon" style="margin-bottom:1rem;">
                    Dev mode: login links are written to <code class="pg-mono">dev_login.log</code>, not emailed.
                </div>
            <?php endif; ?>

            <?php if ($sent): ?>
                <div class="pg-notice pg-notice--ok">
                    <?php if ($app_debug || !$smtp_configured): ?>
                        Check <code class="pg-mono">dev_login.log</code> in the project folder and open the login URL.
                    <?php else: ?>
                        If that email is registered as an admin, check your inbox for the login link.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <label class="pg-label" for="admin-email">Admin email</label>
            <input type="email" id="admin-email" class="pg-input" placeholder="admin@event.com" autocomplete="username">
            <button type="button" id="admin-send-btn" class="pg-btn pg-btn--gold" style="width:100%;margin-top:1rem;">
                Send login link
            </button>

            <p class="pg-muted" style="margin:1.15rem 0 0;font-size:0.82rem;line-height:1.5;">
                Already have a link?
                <a href="login.php">Open email link</a>
                · <a href="manual_login.php">Paste token manually</a>
            </p>

            <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--pg-border);">

            <p class="pg-muted" style="margin:0;font-size:0.82rem;line-height:1.5;">
                <strong>Stall staff?</strong> Use
                <a href="staff_login.php">staff login</a> or the
                <a href="terminal.php">scan terminal</a> with your stall email and password.
            </p>
        </div>
    </div>
    <?php passgateRenderPublicFooter(); ?>

    <script>
    document.getElementById('admin-send-btn').addEventListener('click', async () => {
      const email = document.getElementById('admin-email').value.trim();
      if (!email) {
        alert('Enter your admin email.');
        return;
      }
      const btn = document.getElementById('admin-send-btn');
      btn.disabled = true;
      btn.textContent = 'Sending…';
      try {
        const res = await fetch('auth.php?action=request_login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'request_login', email })
        });
        const data = await res.json();
        if (data.status === 'success') {
          window.location.href = 'admin_login.php?sent=1';
          return;
        }
        alert(data.message || 'Could not send login link.');
      } catch (e) {
        alert('Network error — is the server running?');
      }
      btn.disabled = false;
      btn.textContent = 'Send login link';
    });
    document.getElementById('admin-email').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') document.getElementById('admin-send-btn').click();
    });
    </script>
</body>
</html>
