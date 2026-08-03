<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

if (isDistributorAuthenticated() && ($_SESSION['distributor_role'] ?? '') === 'admin') {
    safeRedirect('distributors.php');
}

$event_name = 'PassGate Pro';
$benefits_list = [];
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
} catch (Throwable $e) {
    // Database not configured yet – empty benefits list
}

$app_debug = env('APP_DEBUG', '0') === '1';
$smtp_configured = trim(env('MAIL_HOST', '') ?? '') !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com" defer></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="manifest" href="manifest.json">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="PassGate Pro">
  <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/1037/1037237.png">
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {});
      });
    }
  </script>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex flex-col">

  <div id="login-overlay" class="fixed inset-0 bg-slate-900 z-[100] flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 space-y-6 shadow-2xl border border-slate-100">
      <div class="text-center space-y-2">
        <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center mx-auto text-xl">
          <i class="fa-solid fa-lock"></i>
        </div>
        <h2 class="text-xl font-bold text-slate-900">Terminal Authentication</h2>
        <h4 class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($event_name); ?></h4>
        <p class="text-xs text-slate-400">Select your role module to continue.</p>
      </div>

      <div class="space-y-4">
        <div class="flex p-1 bg-slate-100 rounded-xl mb-4">
          <button id="btn-mode-stall" onclick="setLoginMode('stall')" class="flex-1 py-1.5 text-xs font-bold rounded-lg bg-white shadow-sm">Stall</button>
          <button id="btn-mode-distributor" onclick="setLoginMode('distributor')" class="flex-1 py-1.5 text-xs font-bold text-slate-500">Admin</button>
          <?php if ($station_pin_enabled): ?>
          <button id="btn-mode-station" onclick="setLoginMode('station')" class="flex-1 py-1.5 text-xs font-bold text-slate-500">PIN</button>
          <?php endif; ?>
        </div>

        <div id="fields-stall">
          <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1">Stall Email</label>
          <input type="email" id="login-stall-email" class="w-full bg-slate-100 text-sm rounded-xl px-3 py-2.5 border-none" placeholder="stall@event.com" autocomplete="username">
          <label class="block text-[10px] uppercase font-bold text-slate-400 mt-3 mb-1.5 pl-1">Password</label>
          <input type="password" id="login-stall-password" class="w-full bg-slate-100 text-sm rounded-xl px-3 py-2.5 border-none" autocomplete="current-password">
          <p class="text-xs text-slate-400 mt-2">Same email can be used for multiple stalls — each stall needs a unique password.</p>
        </div>

        <?php if ($station_pin_enabled): ?>
        <div id="fields-station" class="hidden">
          <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1">Target Station</label>
          <select id="login-station-select" class="w-full bg-slate-100 text-slate-800 text-sm rounded-xl px-3 py-2.5">
            <?php if ($benefits_list === []): ?>
              <option value="">No stations configured</option>
            <?php else: ?>
              <?php foreach ($benefits_list as $benefit): ?>
                <option value="<?php echo htmlspecialchars($benefit); ?>"><?php echo htmlspecialchars($benefit); ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <label class="block text-[10px] uppercase font-bold text-slate-400 mt-3 mb-1.5 pl-1">PIN</label>
          <input type="password" id="login-pin" maxlength="8" class="w-full text-center border rounded-xl px-3 py-2.5 text-base font-bold">
          <p class="text-xs text-amber-600 mt-2">Demo fallback only — use stall login in production.</p>
        </div>
        <?php endif; ?>

        <div id="fields-distributor" class="hidden">
          <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1">Distributor Email</label>
          <input type="email" id="login-email" class="w-full bg-slate-100 text-sm rounded-xl px-3 py-2.5 border-none" placeholder="you@company.com">
          <?php if ($app_debug || !$smtp_configured): ?>
          <p class="text-xs text-amber-600 font-medium mt-2">
            Dev mode: emails are not sent. After clicking Send Login Link, open
            <code class="bg-amber-50 px-1 rounded">dev_login.log</code> in your project folder and copy the URL.
          </p>
          <?php endif; ?>
          <p id="login-link-sent" class="hidden text-xs text-emerald-600 font-medium mt-2">
            <?php echo ($app_debug || !$smtp_configured)
                ? 'Login link written to dev_login.log — open that file and paste the URL in your browser.'
                : 'Check your email for a login link.'; ?>
          </p>
        </div>

        <button id="auth-btn" onclick="handleAuth()" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-3 text-sm transition-all shadow-md">
          Log In
        </button>
        <p class="text-center text-xs text-slate-400">
          <a href="manual_login.php" class="text-indigo-500 hover:underline">Admin: paste login token</a>
          · <a href="stall_login.php" class="text-emerald-600 hover:underline">Stall login page</a>
        </p>
        <?php if ($is_stall_authenticated): ?>
        <p class="text-center text-xs text-emerald-600">
          Stall: <?php echo htmlspecialchars($stall_name); ?> (<?php echo htmlspecialchars($stall_email); ?>).
          <a href="logout.php" class="text-slate-500 hover:underline">Logout</a>
        </p>
        <?php elseif ($is_authenticated): ?>
        <p class="text-center text-xs text-emerald-600">
          Signed in as <?php echo htmlspecialchars($_SESSION['distributor_email'] ?? ''); ?>.
          <?php if ($auth_role === 'admin'): ?>
            <a href="distributors.php" class="text-indigo-500 hover:underline">Open dashboard</a>
          <?php endif; ?>
          · <a href="logout.php" class="text-slate-500 hover:underline">Logout</a>
        </p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <header class="bg-indigo-600 text-white shadow-md sticky top-0 z-50 px-4 py-3 flex items-center justify-between">
    <div class="flex items-center space-x-2">
      <i class="fa-solid fa-shield-halved text-xl text-amber-300"></i>
      <span id="header-station-title" class="font-bold text-sm tracking-tight">Terminal Locked</span>
    </div>
    <button onclick="terminateSessionLogout()" class="bg-indigo-700 hover:bg-indigo-800 text-indigo-200 hover:text-white text-xs font-bold px-3 py-1.5 rounded-xl transition-colors">
      <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> Lock
    </button>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pb-24">
    <div id="toast-container" class="fixed top-6 inset-x-0 flex justify-center z-[99999] pointer-events-none px-4">
      <div id="toast-box" class="hidden flex items-center gap-3 bg-white/90 backdrop-blur-md border border-slate-200 shadow-xl px-5 py-3 rounded-2xl transform transition-all duration-500 ease-out translate-y-[-20px] opacity-0 max-w-xs w-full">
        <div id="toast-icon-wrapper" class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0"></div>
        <span id="toast-message" class="text-sm font-semibold text-slate-700 leading-tight"></span>
      </div>
    </div>

    <div class="space-y-4">
      <div class="bg-slate-900 rounded-2xl overflow-hidden shadow-inner relative aspect-square max-w-[300px] mx-auto w-full flex flex-col items-center justify-center border-4 border-slate-800">
        <div id="scanner-view-element" class="w-full h-full object-cover"></div>
        <div id="video-placeholder" class="absolute inset-0 z-20 bg-slate-900 flex flex-col items-center justify-center text-center p-6 text-slate-400">
          <i class="fa-solid fa-qrcode text-4xl mb-2 text-indigo-400"></i>
          <button id="camera-trigger-btn" onclick="startCameraScanningEngine()" class="mt-3 bg-indigo-600 text-white text-xs px-4 py-2 rounded-xl font-bold tracking-wide shadow-md">Activate Scanner Camera</button>
        </div>
      </div>

      <div class="bg-white rounded-2xl p-4 border border-slate-100 space-y-3">
        <div class="flex space-x-2">
          <input type="text" id="manual-ticket-id" placeholder="Manually enter Ticket ID" class="flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
          <button onclick="processManualScan()" class="bg-slate-800 text-white px-5 rounded-xl text-xs font-bold">Process</button>
        </div>
      </div>

      <div id="scan-result-card" class="hidden rounded-2xl p-4 text-center border font-medium">
        <h4 id="scan-result-title" class="font-bold text-base mb-1"></h4>
        <p id="scan-result-body" class="text-xs opacity-90"></p>
      </div>

      <div id="benefits-usage-panel" class="hidden bg-white rounded-2xl p-4 border border-slate-100 space-y-3">
        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
          <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Benefit Usage</h4>
          <span id="benefits-meta-tier" class="text-[10px] font-semibold text-slate-400"></span>
        </div>
        <div id="benefits-usage-list" class="space-y-2"></div>
      </div>

      <div id="unified-audit-panel" class="hidden rounded-2xl p-4 space-y-3 shadow-xs border">
        <div id="audit-panel-header" class="flex items-center space-x-2 font-bold text-xs tracking-wider uppercase border-b pb-1.5">
          <i class="fa-solid fa-clock-rotate-left text-sm"></i>
          <span>Registry Usage History Dossier</span>
        </div>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <div>
            <span class="block text-[10px] uppercase font-bold text-slate-400">Ticket String ID</span>
            <span id="audit-id" class="font-mono font-bold text-slate-800">-</span>
          </div>
          <div>
            <span class="block text-[10px] uppercase font-bold text-slate-400">Distributor Alloc.</span>
            <span id="audit-dist" class="font-semibold text-slate-800">-</span>
          </div>
          <div id="audit-stall-row" class="hidden col-span-2">
            <span class="block text-[10px] uppercase font-bold text-slate-400">Scanned By Stall</span>
            <span id="audit-stall" class="font-semibold text-emerald-700">-</span>
          </div>
        </div>
        <div>
          <span class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Historical Scan Logs</span>
          <div id="audit-logs-box" class="max-h-32 overflow-y-auto space-y-1 pr-1 text-[11px] font-mono"></div>
        </div>
      </div>
    </div>
  </main>

  <footer class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 py-2.5 px-4 flex items-center justify-between text-[11px] font-medium text-slate-400">
    <div class="flex items-center space-x-2">
      <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
      <span id="footer-status-label">Awaiting Authentication Verification...</span>
    </div>
    <a href="validate.php" class="text-indigo-500 hover:underline">Validate</a>
  </footer>

  <script>
    let activeStationType = null;
    let html5QrcodeScanner = null;
    let currentMode = 'stall';
    const BASE_URL = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));

    const DEV_MAIL_MODE = <?php echo ($app_debug || !$smtp_configured) ? 'true' : 'false'; ?>;
    const IS_AUTHENTICATED = <?php echo $is_authenticated ? 'true' : 'false'; ?>;
    const IS_STALL_AUTHENTICATED = <?php echo $is_stall_authenticated ? 'true' : 'false'; ?>;
    const STALL_NAME = <?php echo json_encode($stall_name); ?>;
    const PIN_UNLOCKED = <?php echo $pin_unlocked ? 'true' : 'false'; ?>;
    const PIN_STATION = <?php echo json_encode($pin_station); ?>;
    const STATION_PIN_ENABLED = <?php echo $station_pin_enabled ? 'true' : 'false'; ?>;
    const AUTH_ROLE = <?php echo json_encode($auth_role); ?>;

    // When login completes in another tab (email link), sync this tab instead of staying on the login overlay
    window.addEventListener('storage', (e) => {
      if (e.key !== 'passgate_auth' || !e.newValue) return;
      if (e.newValue === 'admin') {
        window.location.replace('distributors.php');
      } else if (e.newValue === 'distributor') {
        document.getElementById('login-overlay')?.classList.add('hidden');
        document.getElementById('footer-status-label').innerText = 'Signed in';
      } else if (e.newValue === 'logout') {
        window.location.replace('index.php');
      } else if (e.newValue === 'stall') {
        window.location.replace('index.php');
      }
    });

    function activateTerminal(stationLabel) {
      activeStationType = stationLabel;
      document.getElementById('login-overlay').classList.add('hidden');
      document.getElementById('header-station-title').innerText = stationLabel + ' Terminal';
      document.getElementById('footer-status-label').innerText = 'Terminal Active';
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
        document.getElementById('footer-status-label').innerText = 'Signed in as distributor';
      });
    }

    function setLoginMode(mode) {
      currentMode = mode;
      document.getElementById('fields-stall')?.classList.toggle('hidden', mode !== 'stall');
      document.getElementById('fields-station')?.classList.toggle('hidden', mode !== 'station');
      document.getElementById('fields-distributor')?.classList.toggle('hidden', mode !== 'distributor');
      document.getElementById('login-link-sent')?.classList.add('hidden');

      const labels = { stall: 'Log In as Stall', distributor: 'Send Login Link', station: 'Unlock with PIN' };
      document.getElementById('auth-btn').innerText = labels[mode] || 'Log In';

      const modes = ['stall', 'distributor', 'station'];
      modes.forEach(m => {
        const btn = document.getElementById('btn-mode-' + m);
        if (!btn) return;
        btn.className = mode === m
          ? 'flex-1 py-1.5 text-xs font-bold rounded-lg bg-white shadow-sm'
          : 'flex-1 py-1.5 text-xs font-bold text-slate-500';
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
          showToast(`Welcome, ${result.stall_name}`, 'success');
        } else {
          showToast(result.message || 'Invalid email or password', 'error');
        }
      } catch (err) {
        showToast('Authentication error', 'error');
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
          showToast(`Authorized for ${selectedStation} (PIN mode)`, 'success');
        } else {
          showToast(result.message || 'Invalid PIN', 'error');
        }
      } catch (err) {
        showToast('Authentication error', 'error');
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
          showToast(
            DEV_MAIL_MODE
              ? 'Link saved to dev_login.log — open that file'
              : 'Login link sent – check your email',
            'success'
          );
        } else {
          showToast(result.message || 'Failed', 'error');
        }
      } catch (err) {
        showToast('Error sending login link', 'error');
      }
    }

    document.getElementById('login-pin')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleStationAuthentication(); });
    document.getElementById('login-stall-password')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleStallLogin(); });
    document.getElementById('login-stall-email')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleStallLogin(); });
    document.getElementById('manual-ticket-id').addEventListener('keypress', (e) => { if (e.key === 'Enter') processManualScan(); });
    document.getElementById('login-email').addEventListener('keypress', (e) => { if (e.key === 'Enter') handleAuth(); });

    function stopCameraEngineImmediate() {
      if (html5QrcodeScanner && html5QrcodeScanner.isScanning) {
        html5QrcodeScanner.stop().then(() => {
          document.getElementById('video-placeholder').classList.remove('hidden');
          document.getElementById('camera-trigger-btn').innerText = 'Scan Next Ticket';
        }).catch(() => {});
      }
    }

    async function terminateSessionLogout() {
      stopCameraEngineImmediate();
      activeStationType = null;
      try {
        await fetch(`${BASE_URL}/auth.php?action=lock_terminal`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'lock_terminal' })
        });
      } catch (err) { /* still show lock UI */ }
      document.getElementById('header-station-title').innerText = 'Terminal Locked';
      document.getElementById('footer-status-label').innerText = 'Awaiting Authentication Verification...';
      document.getElementById('scan-result-card').className = 'hidden';
      document.getElementById('unified-audit-panel').className = 'hidden';
      document.getElementById('benefits-usage-panel').classList.add('hidden');
      document.getElementById('audit-stall-row').classList.add('hidden');
      document.getElementById('login-overlay').classList.remove('hidden');
      localStorage.removeItem('passgate_auth');
    }

    function loadHtml5QrcodeLibrary() {
      return new Promise((resolve, reject) => {
        if (window.Html5Qrcode) {
          resolve();
          return;
        }
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/html5-qrcode';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load scanner library'));
        document.head.appendChild(script);
      });
    }

    async function startCameraScanningEngine() {
      try {
        await loadHtml5QrcodeLibrary();
      } catch (err) {
        showToast('Scanner library failed to load', 'error');
        return;
      }

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
        showToast('Camera error. Check permissions.', 'error');
        document.getElementById('video-placeholder').classList.remove('hidden');
      });
    }

    function executeScanTransaction(ticketId) {
      if (!activeStationType) {
        showToast('Please log in as a stall first', 'error');
        document.getElementById('login-overlay')?.classList.remove('hidden');
        return;
      }

      let cleanedId = ticketId.trim();
      if (/^\d+$/.test(cleanedId)) cleanedId = cleanedId.padStart(6, '0');

      const resultCard = document.getElementById('scan-result-card');
      const title = document.getElementById('scan-result-title');
      const body = document.getElementById('scan-result-body');
      const auditPanel = document.getElementById('unified-audit-panel');
      const benefitsPanel = document.getElementById('benefits-usage-panel');

      resultCard.className = 'hidden rounded-2xl p-4 text-center border font-medium';
      auditPanel.className = 'hidden rounded-2xl p-4 space-y-3 shadow-xs border';
      benefitsPanel.classList.add('hidden');

      fetch(`${BASE_URL}/api.php?action=scan`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ticket_id: cleanedId, station: activeStationType })
      })
      .then(async response => {
        const data = await response.json();
        if (response.ok || data.status === 'already_scanned' || data.status === 'limit_reached') {
          const isGranted = data.status === 'granted';
          resultCard.classList.remove('hidden');
          resultCard.classList.add(
            isGranted ? 'bg-emerald-50' : 'bg-rose-50',
            isGranted ? 'border-emerald-200' : 'border-rose-200',
            isGranted ? 'text-emerald-800' : 'text-rose-800'
          );
          title.innerHTML = isGranted
            ? '<i class="fa-solid fa-circle-check mr-1.5"></i> ACCESS GRANTED'
            : '<i class="fa-solid fa-circle-minus mr-1.5"></i> LIMIT REACHED';
          body.innerText = isGranted ? 'Ticket verified.' : 'Allocation exceeded for this station.';
          auditPanel.classList.remove('hidden');
          populateAuditDossier(data.ticket, activeStationType,
            isGranted ? 'text-emerald-800' : 'text-rose-800',
            'bg-white/80',
            isGranted ? 'border-emerald-100' : 'border-rose-100');
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
      .catch(err => {
        resultCard.classList.remove('hidden');
        resultCard.classList.add('bg-rose-50', 'border-rose-200', 'text-rose-800');
        title.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1.5"></i> Lookup Failed';
        body.innerHTML = `The Ticket ID <strong>"${cleanedId}"</strong> was not found in the registry.`;
        auditPanel.classList.remove('hidden');
        document.getElementById('benefits-usage-panel').classList.add('hidden');
        document.getElementById('audit-id').innerText = cleanedId;
        document.getElementById('audit-dist').innerText = 'N/A';
        document.getElementById('audit-logs-box').innerHTML = `<div class="text-rose-400 italic p-2">No matching registry entry.</div>`;
        showToast('Lookup Failed', 'error');
      });
    }

    function renderBenefitsUsagePanel(data) {
      const panel = document.getElementById('benefits-usage-panel');
      const list = document.getElementById('benefits-usage-list');
      const meta = document.getElementById('benefits-meta-tier');
      const summary = data.benefits_summary || [];
      if (summary.length === 0) {
        panel.classList.add('hidden');
        return;
      }
      const tier = data.ticket_meta?.tier_name || '';
      const event = data.ticket_meta?.event_name || '';
      meta.textContent = [event, tier].filter(Boolean).join(' · ');
      list.innerHTML = summary.map(b => {
        const full = b.used >= b.max;
        const pct = b.max > 0 ? Math.min(100, Math.round((b.used / b.max) * 100)) : 0;
        return `<div class="rounded-xl px-3 py-2 text-sm ${full ? 'bg-rose-50 border border-rose-100' : 'bg-slate-50 border border-slate-100'}">
          <div class="flex justify-between items-center mb-1">
            <span class="font-medium text-slate-800">${escapeHtml(b.name)}</span>
            <span class="font-mono font-bold text-xs ${full ? 'text-rose-700' : 'text-slate-700'}">${b.used}/${b.max}</span>
          </div>
          <div class="h-1.5 bg-slate-200 rounded-full overflow-hidden">
            <div class="h-full rounded-full ${full ? 'bg-rose-500' : 'bg-emerald-500'}" style="width:${pct}%"></div>
          </div>
          ${full ? '<span class="text-[10px] font-bold text-rose-600 mt-1 inline-block">Fully used</span>' : `<span class="text-[10px] text-emerald-600 mt-1 inline-block">${b.max - b.used} remaining</span>`}
        </div>`;
      }).join('');
      panel.classList.remove('hidden');
    }

    function escapeHtml(str) {
      return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function populateAuditDossier(ticket, type, textClass, rowBg, borderClass) {
      document.getElementById('audit-id').innerText = ticket.id;
      document.getElementById('audit-dist').innerText = ticket.distributor || 'Unassigned Stock';
      const auditLogsBox = document.getElementById('audit-logs-box');
      auditLogsBox.innerHTML = '';
      const logsList = ticket.logs[type] || [];
      if (logsList.length === 0) {
        auditLogsBox.innerHTML = '<div class="text-slate-400 italic p-2">No historical scan entries recorded.</div>';
      } else {
        const listWrapper = document.createElement('div');
        listWrapper.className = 'space-y-1.5';
        logsList.forEach((logTime, idx) => {
          const row = document.createElement('div');
          row.className = `flex justify-between items-center ${rowBg} p-2 rounded border ${borderClass} shadow-xs`;
          row.innerHTML = `<span class="font-bold text-[11px] uppercase ${textClass}">Scan #${idx + 1}</span><span class="font-mono text-[11px] text-slate-700">${String(logTime).trim()}</span>`;
          listWrapper.appendChild(row);
        });
        auditLogsBox.appendChild(listWrapper);
      }
    }

    function showToast(msg, type) {
      const box = document.getElementById('toast-box');
      const msgEl = document.getElementById('toast-message');
      const iconWrap = document.getElementById('toast-icon-wrapper');
      const isSuccess = type === 'success';
      msgEl.innerText = msg;
      iconWrap.className = `w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ${isSuccess ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'}`;
      iconWrap.innerHTML = isSuccess ? '<i class="fa-solid fa-check"></i>' : '<i class="fa-solid fa-exclamation"></i>';
      box.classList.remove('hidden');
      requestAnimationFrame(() => box.classList.remove('opacity-0', 'translate-y-[-20px]'));
      setTimeout(() => {
        box.classList.add('opacity-0', 'translate-y-[-20px]');
        setTimeout(() => box.classList.add('hidden'), 500);
      }, 3500);
    }

    function processManualScan() {
      const el = document.getElementById('manual-ticket-id');
      if (!el.value.trim()) return;
      executeScanTransaction(el.value.trim());
      el.value = '';
    }
  </script>
</body>
</html>
