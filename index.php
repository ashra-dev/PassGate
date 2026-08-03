<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (isDistributorAuthenticated() && ($_SESSION['distributor_role'] ?? '') === 'admin') {
    safeRedirect('distributors.php');
}

$event_name = 'PassGate';
$benefits_list = [];
$scan_benefits_list = [];
$is_authenticated = isDistributorAuthenticated();
$is_stall_authenticated = isStallAuthenticated();
$auth_role = $_SESSION['distributor_role'] ?? '';
$stall_name = $_SESSION['stall_name'] ?? '';
$stall_email = $_SESSION['stall_email'] ?? '';
$pin_unlocked = !empty($_SESSION['station_pin_unlocked']);
$pin_station = $_SESSION['pin_station_type'] ?? '';
$station_pin_enabled = env('STATION_PIN_ENABLED', '1') === '1';

try {
    $db = getDb();
    $terminal_event_id = resolveTerminalEventId($db);
    if ($terminal_event_id !== null) {
        $event_name = getCurrentEventName($db, $terminal_event_id);
        $benefits_list = getDistinctBenefitNames($db, $terminal_event_id);
    }
    $scan_benefits_list = getDistinctBenefitNames($db, null);
} catch (Throwable $e) {
    // Database not configured yet - empty benefits list
}

$has_scan_benefits = $scan_benefits_list !== [];

$app_debug = env('APP_DEBUG', '0') === '1';
$smtp_configured = trim(env('MAIL_HOST', '') ?? '') !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php
  passgateRenderHead('PassGate', [
      'extra' => <<<'HTML'
  <link rel="manifest" href="manifest.json">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="PassGate">
  <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/1037/1037237.png">
  <style>
    .pg-select--scan {
      min-height: 3.25rem;
      font-size: 1rem;
      font-weight: 600;
      padding: 0.75rem 1rem;
      border-radius: var(--pg-radius-sm, 0.75rem);
      width: 100%;
    }
    .pg-benefit-panel.is-disabled { opacity: 0.55; pointer-events: none; }
  </style>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {});
      });
    }
  </script>
HTML
  ]);
  ?>
</head>
<body class="pg-body pg-shell">

  <div id="login-overlay" class="pg-overlay">
    <div class="pg-card pg-card--auth">
      <div style="text-align:center;margin-bottom:1.25rem;">
        <div class="pg-brand-mark" style="margin:0 auto 0.85rem;font-size:1.1rem;">
          <i class="fa-solid fa-ticket"></i>
        </div>
        <h1 class="pg-brand" style="font-size:1.85rem;margin:0;">PassGate</h1>
        <?php if ($event_name !== '' && strcasecmp($event_name, 'PassGate') !== 0): ?>
        <p class="pg-muted" style="margin:0.45rem 0 0;font-size:0.9rem;font-weight:700;"><?php echo htmlspecialchars($event_name); ?></p>
        <?php endif; ?>
        <p class="pg-faint" style="margin:0.4rem 0 0;font-size:0.8rem;">Sign in to start scanning</p>
      </div>

      <div id="fields-stall">
        <label class="pg-label" for="login-stall-email">Stall email</label>
        <input type="email" id="login-stall-email" class="pg-input" placeholder="stall@event.com" autocomplete="username">
        <label class="pg-label" for="login-stall-password" style="margin-top:0.85rem;">Password</label>
        <input type="password" id="login-stall-password" class="pg-input" autocomplete="current-password">
      </div>

      <div id="fields-distributor" class="hidden">
        <?php if ($app_debug || !$smtp_configured): ?>
        <div class="pg-dev-ribbon">
          Dev mode: login links go to <code class="pg-mono">dev_login.log</code>, not email.
        </div>
        <?php endif; ?>
        <label class="pg-label" for="login-email">Admin / distributor email</label>
        <input type="email" id="login-email" class="pg-input" placeholder="you@company.com">
        <p id="login-link-sent" class="pg-hint pg-hint--ok hidden">
          <?php echo ($app_debug || !$smtp_configured)
              ? 'Link saved to dev_login.log - open that file and paste the URL.'
              : 'Check your email for a login link.'; ?>
        </p>
      </div>

      <?php if ($station_pin_enabled): ?>
      <div id="fields-station" class="hidden">
        <label class="pg-label" for="login-station-select">Station</label>
        <select id="login-station-select" class="pg-select">
          <?php if ($benefits_list === []): ?>
            <option value="">No stations configured yet</option>
          <?php else: ?>
            <?php foreach ($benefits_list as $benefit): ?>
              <option value="<?php echo htmlspecialchars($benefit); ?>"><?php echo htmlspecialchars($benefit); ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
        <label class="pg-label" for="login-pin" style="margin-top:0.85rem;">PIN</label>
        <input type="password" id="login-pin" maxlength="8" class="pg-input" style="text-align:center;font-weight:700;letter-spacing:0.2em;">
        <p class="pg-hint pg-hint--warn">Demo unlock only - use stall login for real events.</p>
      </div>
      <?php endif; ?>

      <div style="margin-top:1.1rem;">
        <button type="button" id="auth-btn" onclick="handleAuth()" class="pg-btn pg-btn--gold">Start scanning</button>
      </div>

      <button type="button" id="more-login-toggle" class="pg-more-toggle" onclick="toggleMoreLogin()">
        More options (Admin / PIN)
      </button>
      <div id="more-login-panel" class="hidden" style="margin-top:0.35rem;">
        <div class="pg-segment" role="tablist">
          <button type="button" id="btn-mode-stall" onclick="setLoginMode('stall')" class="pg-segment__btn is-active">Stall</button>
          <button type="button" id="btn-mode-distributor" onclick="setLoginMode('distributor')" class="pg-segment__btn">Admin</button>
          <?php if ($station_pin_enabled): ?>
          <button type="button" id="btn-mode-station" onclick="setLoginMode('station')" class="pg-segment__btn">PIN</button>
          <?php endif; ?>
        </div>
        <p class="pg-links" style="margin-top:0.35rem;">
          <a href="manual_login.php">Paste login token</a>
          &middot; <a href="stall_login.php">Separate stall page</a>
          &middot; <a href="customer_login.php">Customer login</a>
          &middot; <a href="buy.php">Buy tickets</a>
        </p>
      </div>

      <?php if ($is_stall_authenticated): ?>
      <p class="pg-hint pg-hint--ok" style="text-align:center;">
        Stall ready: <?php echo htmlspecialchars($stall_name); ?>.
        <a href="logout.php">Logout</a>
      </p>
      <?php elseif ($is_authenticated): ?>
      <p class="pg-hint pg-hint--ok" style="text-align:center;">
        Signed in as <?php echo htmlspecialchars($_SESSION['distributor_email'] ?? ''); ?>.
        <?php if ($auth_role === 'admin'): ?>
          <a href="distributors.php">Open dashboard</a> &middot;
        <?php endif; ?>
        <a href="logout.php">Logout</a>
      </p>
      <?php endif; ?>
    </div>
  </div>

  <div id="scan-flash" class="pg-flash" role="status" aria-live="assertive" onclick="dismissFlash()">
    <div id="scan-flash-icon" class="pg-flash__icon"></div>
    <h2 id="scan-flash-title" class="pg-flash__title"></h2>
    <p id="scan-flash-sub" class="pg-flash__sub"></p>
    <div id="scan-flash-ticket" class="pg-flash__ticket"></div>
    <div class="pg-flash__hint">Tap to continue</div>
  </div>

  <header class="pg-topbar">
    <div style="display:flex;align-items:center;gap:0.75rem;min-width:0;">
      <div class="pg-brand-mark" style="width:2rem;height:2rem;border-radius:0.65rem;font-size:0.85rem;flex-shrink:0;">
        <i class="fa-solid fa-shield-halved"></i>
      </div>
      <div style="min-width:0;">
        <div id="header-station-title" class="pg-station-name">Terminal locked</div>
        <div style="margin-top:0.2rem;">
          <span id="header-status-chip" class="pg-status-chip is-locked">Locked</span>
        </div>
      </div>
    </div>
    <button type="button" onclick="terminateSessionLogout()" class="pg-btn pg-btn--ghost pg-btn--sm" style="min-height:2.4rem;min-width:4.2rem;">
      <i class="fa-solid fa-lock"></i> Lock
    </button>
  </header>

  <main class="pg-main">
    <div id="toast-container" class="pg-toast-wrap">
      <div id="toast-box" class="pg-toast hidden">
        <div id="toast-icon-wrapper" class="pg-toast__icon"></div>
        <span id="toast-message" class="pg-toast__msg"></span>
      </div>
    </div>

    <div id="benefit-select-panel" class="pg-panel<?php echo $has_scan_benefits ? '' : ' pg-benefit-panel is-disabled'; ?>">
      <label for="scan-benefit-select" class="pg-label">Benefit to redeem</label>
      <p class="pg-faint" style="margin:0 0 0.55rem;font-size:0.75rem;">Select drink, food, or entry type before scanning.</p>
      <?php if ($has_scan_benefits): ?>
        <select id="scan-benefit-select" class="pg-select pg-select--scan">
          <option value="">Select benefit…</option>
          <?php foreach ($scan_benefits_list as $benefit): ?>
            <option value="<?php echo htmlspecialchars($benefit, ENT_QUOTES); ?>"><?php echo htmlspecialchars($benefit); ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <div class="pg-alert" id="no-benefits-msg">
          No benefits configured yet. Add tiers with benefits in the admin setup before scanning.
        </div>
        <select id="scan-benefit-select" class="pg-select pg-select--scan hidden" disabled aria-hidden="true">
          <option value="">Select benefit…</option>
        </select>
      <?php endif; ?>
    </div>

    <div class="pg-scanner<?php echo $has_scan_benefits ? '' : ' pg-benefit-panel is-disabled'; ?>" id="scanner-panel">
      <div id="scanner-view-element" style="width:100%;height:100%;"></div>
      <div id="video-placeholder" class="pg-scanner__placeholder">
        <i class="fa-solid fa-qrcode" style="font-size:2rem;color:#2563eb;margin-bottom:0.75rem;"></i>
        <p class="pg-muted" style="margin:0 0 0.85rem;font-size:0.8rem;">Point camera at guest QR</p>
        <button type="button" id="camera-trigger-btn" onclick="startCameraScanningEngine()" class="pg-btn pg-btn--camera">Start camera</button>
      </div>
    </div>

    <div class="pg-panel<?php echo $has_scan_benefits ? '' : ' pg-benefit-panel is-disabled'; ?>" id="manual-panel">
      <p class="pg-section-title" style="margin-bottom:0.55rem;">Or type ticket ID</p>
      <div class="pg-manual-row">
        <input type="text" id="manual-ticket-id" class="pg-input" placeholder="Ticket ID" autocomplete="off" enterkeyhint="go">
        <button type="button" onclick="processManualScan()" class="pg-btn pg-btn--process">Process</button>
      </div>
    </div>

    <div id="scan-result-card" class="pg-result hidden">
      <h4 id="scan-result-title" class="pg-result__title"></h4>
      <p id="scan-result-body" class="pg-result__body"></p>
    </div>

    <details id="benefits-details" class="pg-details hidden">
      <summary>Benefit usage</summary>
      <div class="pg-details__body">
        <div style="display:flex;justify-content:flex-end;margin-bottom:0.55rem;">
          <span id="benefits-meta-tier" class="pg-faint" style="font-size:0.68rem;font-weight:600;"></span>
        </div>
        <div id="benefits-usage-list" style="display:grid;gap:0.55rem;"></div>
        <div id="benefits-usage-panel" class="hidden"></div>
      </div>
    </details>

    <details id="audit-details" class="pg-details hidden">
      <summary>Last scan details</summary>
      <div class="pg-details__body">
        <div id="unified-audit-panel">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;font-size:0.8rem;">
            <div>
              <span class="pg-section-title" style="display:block;margin-bottom:0.25rem;">Ticket ID</span>
              <span id="audit-id" class="pg-mono" style="font-weight:600;">-</span>
            </div>
            <div>
              <span class="pg-section-title" style="display:block;margin-bottom:0.25rem;">Distributor</span>
              <span id="audit-dist" style="font-weight:600;">-</span>
            </div>
            <div id="audit-stall-row" class="hidden" style="grid-column:1 / -1;">
              <span class="pg-section-title" style="display:block;margin-bottom:0.25rem;">Scanned by</span>
              <span id="audit-stall" style="font-weight:600;color:var(--pg-ok);">-</span>
            </div>
          </div>
          <div style="margin-top:0.85rem;">
            <span class="pg-section-title" style="display:block;margin-bottom:0.35rem;">History</span>
            <div id="audit-logs-box" class="pg-mono" style="max-height:8rem;overflow:auto;font-size:0.7rem;"></div>
          </div>
        </div>
      </div>
    </details>
  </main>

  <footer class="pg-footer">
    <div style="display:flex;align-items:center;gap:0.5rem;">
      <span class="pg-live-dot"></span>
      <span id="footer-status-label">Sign in to begin</span>
    </div>
    <a href="validate.php" class="pg-footer-link">Ticket lookup</a>
  </footer>


  <script>
    let activeStationType = null;
    let html5QrcodeScanner = null;
    let currentMode = 'stall';
    let moreLoginOpen = false;
    let flashTimer = null;
    const BASE_URL = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));

    const DEV_MAIL_MODE = <?php echo ($app_debug || !$smtp_configured) ? 'true' : 'false'; ?>;
    const IS_AUTHENTICATED = <?php echo $is_authenticated ? 'true' : 'false'; ?>;
    const IS_STALL_AUTHENTICATED = <?php echo $is_stall_authenticated ? 'true' : 'false'; ?>;
    const STALL_NAME = <?php echo json_encode($stall_name); ?>;
    const PIN_UNLOCKED = <?php echo $pin_unlocked ? 'true' : 'false'; ?>;
    const PIN_STATION = <?php echo json_encode($pin_station); ?>;
    const STATION_PIN_ENABLED = <?php echo $station_pin_enabled ? 'true' : 'false'; ?>;
    const AUTH_ROLE = <?php echo json_encode($auth_role); ?>;
    const SCAN_BENEFITS = <?php echo json_encode($scan_benefits_list, JSON_THROW_ON_ERROR); ?>;
    const HAS_SCAN_BENEFITS = <?php echo $has_scan_benefits ? 'true' : 'false'; ?>;

    window.addEventListener('storage', (e) => {
      if (e.key !== 'passgate_auth' || !e.newValue) return;
      if (e.newValue === 'admin') {
        window.location.replace('distributors.php');
      } else if (e.newValue === 'distributor') {
        document.getElementById('login-overlay')?.classList.add('hidden');
        setReadyState(false, 'Signed in');
      } else if (e.newValue === 'logout') {
        window.location.replace('index.php');
      } else if (e.newValue === 'stall') {
        window.location.replace('index.php');
      }
    });

    function getSelectedBenefit() {
      const el = document.getElementById('scan-benefit-select');
      return el ? el.value.trim() : '';
    }

    function requireBenefitSelected() {
      if (!HAS_SCAN_BENEFITS) {
        showToast('No benefits configured — contact admin', 'error');
        return false;
      }
      const benefit = getSelectedBenefit();
      if (!benefit) {
        showToast('Select a benefit before scanning', 'error');
        document.getElementById('scan-benefit-select')?.focus();
        return false;
      }
      return true;
    }

    function setReadyState(ready, stationLabel) {
      const title = document.getElementById('header-station-title');
      const chip = document.getElementById('header-status-chip');
      const footer = document.getElementById('footer-status-label');
      if (ready) {
        title.textContent = stationLabel;
        chip.textContent = 'Ready';
        chip.className = 'pg-status-chip is-ready';
        footer.textContent = 'Ready to scan';
      } else {
        title.textContent = stationLabel || 'Terminal locked';
        chip.textContent = 'Locked';
        chip.className = 'pg-status-chip is-locked';
        footer.textContent = stationLabel === 'Signed in' ? 'Signed in' : 'Sign in to begin';
      }
    }

    function activateTerminal(stationLabel) {
      activeStationType = stationLabel;
      document.getElementById('login-overlay').classList.add('hidden');
      setReadyState(true, stationLabel);
      preselectBenefitMatch(stationLabel);
      startCameraScanningEngine();
    }

    function preselectBenefitMatch(label) {
      const sel = document.getElementById('scan-benefit-select');
      if (!sel || !label) return;
      const norm = label.trim().toLowerCase();
      for (const opt of sel.options) {
        if (opt.value && opt.value.toLowerCase() === norm) {
          sel.value = opt.value;
          return;
        }
      }
    }

    if (IS_STALL_AUTHENTICATED && STALL_NAME) {
      document.addEventListener('DOMContentLoaded', () => {
        localStorage.setItem('passgate_auth', 'stall');
        activateTerminal(STALL_NAME);
      });
    } else if (PIN_UNLOCKED && PIN_STATION) {
      document.addEventListener('DOMContentLoaded', () => {
        activateTerminal(PIN_STATION);
      });
    } else if (IS_AUTHENTICATED && AUTH_ROLE === 'distributor') {
      document.addEventListener('DOMContentLoaded', () => {
        localStorage.setItem('passgate_auth', 'distributor');
        document.getElementById('login-overlay')?.classList.add('hidden');
        setReadyState(false, 'Signed in');
      });
    }

    function toggleMoreLogin() {
      moreLoginOpen = !moreLoginOpen;
      document.getElementById('more-login-panel')?.classList.toggle('hidden', !moreLoginOpen);
      document.getElementById('more-login-toggle').textContent = moreLoginOpen
        ? 'Hide options'
        : 'More options (Admin / PIN)';
      if (!moreLoginOpen) setLoginMode('stall');
    }

    function setLoginMode(mode) {
      currentMode = mode;
      document.getElementById('fields-stall')?.classList.toggle('hidden', mode !== 'stall');
      document.getElementById('fields-station')?.classList.toggle('hidden', mode !== 'station');
      document.getElementById('fields-distributor')?.classList.toggle('hidden', mode !== 'distributor');
      document.getElementById('login-link-sent')?.classList.add('hidden');

      const labels = {
        stall: 'Start scanning',
        distributor: 'Send login link',
        station: 'Unlock with PIN'
      };
      document.getElementById('auth-btn').innerText = labels[mode] || 'Start scanning';

      ['stall', 'distributor', 'station'].forEach((m) => {
        const btn = document.getElementById('btn-mode-' + m);
        if (!btn) return;
        btn.classList.toggle('is-active', mode === m);
      });
    }

    function handleAuth() {
      if (currentMode === 'stall') handleStallLogin();
      else if (currentMode === 'station') handleStationAuthentication();
      else handleDistributorLogin();
    }

    async function handleStallLogin() {
      const email = document.getElementById('login-stall-email').value.trim();
      const password = document.getElementById('login-stall-password').value;
      if (!email || !password) {
        showToast('Enter email and password', 'error');
        return;
      }
      try {
        const response = await fetch(`${BASE_URL}/auth.php?action=stall_login`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'stall_login', email, password })
        });
        const result = await response.json();
        if (result.status === 'success') {
          localStorage.setItem('passgate_auth', 'stall');
          activateTerminal(result.stall_name);
          showToast(`${result.stall_name} ready`, 'success');
        } else {
          showToast(result.message || 'Invalid email or password', 'error');
        }
      } catch (err) {
        showToast('Sign-in failed', 'error');
      }
    }

    async function handleStationAuthentication() {
      const selectedStation = document.getElementById('login-station-select').value;
      const inputPin = document.getElementById('login-pin').value.trim();
      if (!selectedStation) {
        showToast('No station configured', 'error');
        return;
      }
      try {
        const response = await fetch(`${BASE_URL}/auth.php?action=unlock_station`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'unlock_station', station: selectedStation, pin: inputPin })
        });
        const result = await response.json();
        if (result.status === 'success') {
          activateTerminal(selectedStation);
          showToast(`${selectedStation} unlocked`, 'success');
        } else {
          showToast(result.message || 'Invalid PIN', 'error');
        }
      } catch (err) {
        showToast('Sign-in failed', 'error');
      }
    }

    async function handleDistributorLogin() {
      const emailInput = document.getElementById('login-email').value.trim();
      if (!emailInput) {
        showToast('Enter your email', 'error');
        return;
      }
      try {
        const response = await fetch(`${BASE_URL}/auth.php?action=request_login`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'request_login', email: emailInput })
        });
        const result = await response.json();
        if (result.status === 'success') {
          document.getElementById('login-link-sent').classList.remove('hidden');
          showToast(DEV_MAIL_MODE ? 'Link saved to dev_login.log' : 'Login link sent — check email', 'success');
        } else {
          showToast(result.message || 'Failed', 'error');
        }
      } catch (err) {
        showToast('Could not send login link', 'error');
      }
    }

    document.getElementById('login-pin')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleStationAuthentication(); });
    document.getElementById('login-stall-password')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleStallLogin(); });
    document.getElementById('login-stall-email')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleStallLogin(); });
    document.getElementById('manual-ticket-id').addEventListener('keypress', (e) => { if (e.key === 'Enter') processManualScan(); });
    document.getElementById('login-email')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleAuth(); });

    function stopCameraEngineImmediate() {
      if (html5QrcodeScanner && html5QrcodeScanner.isScanning) {
        html5QrcodeScanner.stop().then(() => {
          document.getElementById('video-placeholder').classList.remove('hidden');
          document.getElementById('camera-trigger-btn').innerText = 'Scan next';
        }).catch(() => {});
      }
    }

    async function terminateSessionLogout() {
      stopCameraEngineImmediate();
      activeStationType = null;
      dismissFlash();
      try {
        await fetch(`${BASE_URL}/auth.php?action=lock_terminal`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'lock_terminal' })
        });
      } catch (err) {}
      setReadyState(false);
      document.getElementById('scan-result-card').className = 'pg-result hidden';
      document.getElementById('benefits-details')?.classList.add('hidden');
      document.getElementById('audit-details')?.classList.add('hidden');
      document.getElementById('audit-stall-row').classList.add('hidden');
      document.getElementById('login-overlay').classList.remove('hidden');
      localStorage.removeItem('passgate_auth');
    }

    function loadHtml5QrcodeLibrary() {
      return new Promise((resolve, reject) => {
        if (window.Html5Qrcode) { resolve(); return; }
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/html5-qrcode';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load scanner library'));
        document.head.appendChild(script);
      });
    }

    async function startCameraScanningEngine() {
      if (!activeStationType) {
        showToast('Sign in as a stall first', 'error');
        document.getElementById('login-overlay')?.classList.remove('hidden');
        return;
      }
      if (!requireBenefitSelected()) return;
      try {
        await loadHtml5QrcodeLibrary();
      } catch (err) {
        showToast('Scanner library failed to load', 'error');
        return;
      }
      if (html5QrcodeScanner && html5QrcodeScanner.isScanning) return;
      document.getElementById('video-placeholder').classList.add('hidden');
      html5QrcodeScanner = new Html5Qrcode('scanner-view-element');
      const config = {
        fps: 15,
        qrbox: (width, height) => ({ width: Math.floor(width * 0.75), height: Math.floor(height * 0.75) }),
        aspectRatio: 1.0
      };
      html5QrcodeScanner.start(
        { facingMode: 'environment' },
        config,
        (decodedText) => { stopCameraEngineImmediate(); executeScanTransaction(decodedText); },
        () => {}
      ).catch(() => {
        showToast('Camera blocked — use ticket ID below', 'error');
        document.getElementById('video-placeholder').classList.remove('hidden');
      });
    }

    function showFlash(kind, title, sub, ticketId) {
      const flash = document.getElementById('scan-flash');
      const icon = document.getElementById('scan-flash-icon');
      flash.className = 'pg-flash is-on pg-flash--' + kind;
      const icons = {
        ok: '<i class="fa-solid fa-circle-check"></i>',
        warn: '<i class="fa-solid fa-circle-exclamation"></i>',
        deny: '<i class="fa-solid fa-circle-xmark"></i>'
      };
      icon.innerHTML = icons[kind] || icons.deny;
      document.getElementById('scan-flash-title').textContent = title;
      document.getElementById('scan-flash-sub').textContent = sub;
      document.getElementById('scan-flash-ticket').textContent = ticketId ? ('Ticket ' + ticketId) : '';
      if (flashTimer) clearTimeout(flashTimer);
      flashTimer = setTimeout(() => dismissFlash(), kind === 'ok' ? 1600 : 2200);
    }

    function dismissFlash() {
      document.getElementById('scan-flash').classList.remove('is-on');
      if (flashTimer) { clearTimeout(flashTimer); flashTimer = null; }
    }

    function executeScanTransaction(ticketId) {
      if (!activeStationType) {
        showToast('Sign in as a stall first', 'error');
        document.getElementById('login-overlay')?.classList.remove('hidden');
        return;
      }
      if (!requireBenefitSelected()) return;

      const selectedBenefit = getSelectedBenefit();
      let cleanedId = ticketId.trim();
      if (/^\d+$/.test(cleanedId)) cleanedId = cleanedId.padStart(6, '0');

      const resultCard = document.getElementById('scan-result-card');
      const title = document.getElementById('scan-result-title');
      const body = document.getElementById('scan-result-body');
      const benefitsDetails = document.getElementById('benefits-details');
      const auditDetails = document.getElementById('audit-details');

      resultCard.className = 'pg-result hidden';
      benefitsDetails.classList.add('hidden');
      auditDetails.classList.add('hidden');

      fetch(`${BASE_URL}/api.php?action=scan`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ticket_id: cleanedId, station: selectedBenefit })
      })
      .then(async response => {
        const data = await response.json();
        if (response.ok || data.status === 'already_scanned' || data.status === 'limit_reached') {
          const isGranted = data.status === 'granted';
          showFlash(
            isGranted ? 'ok' : 'warn',
            isGranted ? 'GRANTED' : 'LIMIT REACHED',
            isGranted ? 'Benefit approved — fulfill for guest.' : 'Already claimed or max uses reached. Send to admin if needed.',
            cleanedId
          );
          resultCard.className = 'pg-result ' + (isGranted ? 'pg-result--ok' : 'pg-result--warn');
          title.innerHTML = isGranted
            ? '<i class="fa-solid fa-circle-check mr-1.5"></i> Access granted'
            : '<i class="fa-solid fa-circle-minus mr-1.5"></i> Limit reached';
          body.innerText = isGranted ? 'Ticket verified.' : 'This benefit cannot be used again.';
          auditDetails.classList.remove('hidden');
          populateAuditDossier(data.ticket, selectedBenefit);
          if (data.stall_name) {
            document.getElementById('audit-stall-row').classList.remove('hidden');
            document.getElementById('audit-stall').innerText = data.stall_name;
          } else {
            document.getElementById('audit-stall-row').classList.add('hidden');
          }
          renderBenefitsUsagePanel(data);
        } else {
          throw new Error(data.message || 'An error occurred');
        }
      })
      .catch(() => {
        showFlash('deny', 'DENIED', 'Ticket not found or not valid for this station.', cleanedId);
        resultCard.className = 'pg-result pg-result--deny';
        title.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1.5"></i> Access denied';
        body.innerHTML = `Ticket <strong class="pg-mono">${cleanedId}</strong> was not found.`;
        auditDetails.classList.remove('hidden');
        benefitsDetails.classList.add('hidden');
        document.getElementById('audit-id').innerText = cleanedId;
        document.getElementById('audit-dist').innerText = 'N/A';
        document.getElementById('audit-logs-box').innerHTML = '<div class="pg-faint" style="font-style:italic;padding:0.4rem;">No matching ticket.</div>';
      });
    }

    function renderBenefitsUsagePanel(data) {
      const details = document.getElementById('benefits-details');
      const list = document.getElementById('benefits-usage-list');
      const meta = document.getElementById('benefits-meta-tier');
      const summary = data.benefits_summary || [];
      if (summary.length === 0) {
        details.classList.add('hidden');
        return;
      }
      const tier = data.ticket_meta?.tier_name || '';
      const event = data.ticket_meta?.event_name || '';
      meta.textContent = [event, tier].filter(Boolean).join(' · ');
      list.innerHTML = summary.map(b => {
        const full = b.used >= b.max;
        const pct = b.max > 0 ? Math.min(100, Math.round((b.used / b.max) * 100)) : 0;
        return `<div class="pg-benefit${full ? ' is-full' : ''}">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:600;">${escapeHtml(b.name)}</span>
            <span class="pg-mono" style="font-size:0.72rem;font-weight:700;color:${full ? 'var(--pg-deny)' : 'var(--pg-text)'};">${b.used}/${b.max}</span>
          </div>
          <div class="pg-bar"><div class="pg-bar__fill${full ? ' is-full' : ''}" style="width:${pct}%"></div></div>
          ${full
            ? '<span style="font-size:0.65rem;font-weight:700;color:var(--pg-deny);">Fully used</span>'
            : `<span style="font-size:0.65rem;color:var(--pg-ok);">${b.max - b.used} remaining</span>`}
        </div>`;
      }).join('');
      details.classList.remove('hidden');
    }

    function escapeHtml(str) {
      return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function populateAuditDossier(ticket, type) {
      document.getElementById('audit-id').innerText = ticket.id;
      document.getElementById('audit-dist').innerText = ticket.distributor || 'Unassigned stock';
      const auditLogsBox = document.getElementById('audit-logs-box');
      auditLogsBox.innerHTML = '';
      const logsList = ticket.logs[type] || [];
      if (logsList.length === 0) {
        auditLogsBox.innerHTML = '<div class="pg-faint" style="font-style:italic;padding:0.4rem;">No scans yet for this station.</div>';
      } else {
        logsList.forEach((logTime, idx) => {
          const row = document.createElement('div');
          row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:0.45rem 0.55rem;border-radius:0.55rem;border:1px solid #e2e8f0;background:#f8fafc;margin-bottom:0.35rem;';
          row.innerHTML = `<span style="font-weight:700;font-size:0.65rem;letter-spacing:0.08em;text-transform:uppercase;color:#2563eb;">Scan #${idx + 1}</span><span>${String(logTime).trim()}</span>`;
          auditLogsBox.appendChild(row);
        });
      }
    }

    function showToast(msg, type) {
      const box = document.getElementById('toast-box');
      const msgEl = document.getElementById('toast-message');
      const iconWrap = document.getElementById('toast-icon-wrapper');
      const isSuccess = type === 'success';
      msgEl.innerText = msg;
      iconWrap.className = `pg-toast__icon ${isSuccess ? 'pg-toast__icon--ok' : 'pg-toast__icon--err'}`;
      iconWrap.innerHTML = isSuccess ? '<i class="fa-solid fa-check"></i>' : '<i class="fa-solid fa-exclamation"></i>';
      box.classList.remove('hidden');
      requestAnimationFrame(() => box.classList.add('is-visible'));
      setTimeout(() => {
        box.classList.remove('is-visible');
        setTimeout(() => box.classList.add('hidden'), 450);
      }, 3500);
    }

    function processManualScan() {
      const el = document.getElementById('manual-ticket-id');
      if (!el.value.trim()) return;
      if (!requireBenefitSelected()) return;
      executeScanTransaction(el.value.trim());
      el.value = '';
    }
  </script>
</body>
</html>
