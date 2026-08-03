<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (isDistributorAuthenticated() && ($_SESSION['distributor_role'] ?? '') === 'admin') {
    safeRedirect('distributors.php');
}

if (!isStallAuthenticated()) {
    safeRedirect('staff_login.php?next=terminal.php');
}

$event_name = 'PassGate';
$scan_benefits_list = [];
$stall_name = (string) ($_SESSION['stall_name'] ?? '');
$stall_email = (string) ($_SESSION['stall_email'] ?? '');
$show_welcome = isset($_GET['welcome']);

try {
    $db = getDb();
    $terminal_event_id = resolveTerminalEventId($db);
    if ($terminal_event_id !== null) {
        $event_name = getCurrentEventName($db, $terminal_event_id);
    }
    $scan_benefits_list = getDistinctBenefitNames($db, null);
} catch (Throwable $e) {
    // Database not configured yet
}

$has_scan_benefits = $scan_benefits_list !== [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php
  passgateRenderHead('PassGate – Terminal', [
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
    .pg-terminal-tabs {
      display: flex;
      gap: 0.35rem;
      margin-bottom: 0.85rem;
      padding: 0.25rem;
      background: rgba(255,255,255,0.85);
      border: 1px solid var(--pg-border);
      border-radius: 999px;
    }
    .pg-terminal-tabs__btn {
      flex: 1;
      border: none;
      background: transparent;
      padding: 0.55rem 0.75rem;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 800;
      color: var(--pg-text-muted);
      cursor: pointer;
    }
    .pg-terminal-tabs__btn.is-active {
      color: #fff;
      background: linear-gradient(180deg, #3b82f6, #2563eb);
    }
    .pg-status-lookup { display: grid; gap: 0.85rem; }
    .pg-status-card {
      border: 1px solid var(--pg-border);
      border-radius: var(--pg-radius-sm);
      padding: 1rem;
      background: #fff;
    }
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

  <?php passgateRenderStaffNav('scanner'); ?>

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
        <div id="header-station-title" class="pg-station-name"><?php echo htmlspecialchars($stall_name); ?></div>
        <div style="margin-top:0.2rem;">
          <span id="header-status-chip" class="pg-status-chip is-ready">Ready</span>
        </div>
        <?php if ($stall_email !== ''): ?>
        <p class="pg-faint" style="margin:0.15rem 0 0;font-size:0.68rem;"><?php echo htmlspecialchars($stall_email); ?></p>
        <?php endif; ?>
      </div>
    </div>
    <button type="button" onclick="terminateSessionLogout()" class="pg-btn pg-btn--ghost pg-btn--sm" style="min-height:2.4rem;min-width:4.2rem;">
      <i class="fa-solid fa-right-from-bracket"></i> Log out
    </button>
  </header>

  <main class="pg-main">
    <div id="toast-container" class="pg-toast-wrap">
      <div id="toast-box" class="pg-toast hidden">
        <div id="toast-icon-wrapper" class="pg-toast__icon"></div>
        <span id="toast-message" class="pg-toast__msg"></span>
      </div>
    </div>

    <div class="pg-terminal-tabs" role="tablist" aria-label="Terminal mode">
      <button type="button" id="tab-scan" class="pg-terminal-tabs__btn is-active" onclick="switchTerminalMode('scan')">
        <i class="fa-solid fa-qrcode"></i> Scan
      </button>
      <button type="button" id="tab-status" class="pg-terminal-tabs__btn" onclick="switchTerminalMode('status')">
        <i class="fa-solid fa-magnifying-glass"></i> Check status
      </button>
    </div>

    <div id="panel-scan">
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
    </div>

    <div id="panel-status" class="hidden">
      <div class="pg-panel pg-status-lookup">
        <p class="pg-section-title" style="margin:0 0 0.35rem;">Check ticket status</p>
        <p class="pg-faint" style="margin:0 0 0.75rem;font-size:0.75rem;">Read-only lookup — does not redeem benefits or record a scan.</p>
        <div class="pg-manual-row">
          <input type="text" id="status-ticket-id" class="pg-input" placeholder="Ticket ID" autocomplete="off" enterkeyhint="search">
          <button type="button" onclick="lookupTicketStatus()" class="pg-btn pg-btn--process">Look up</button>
        </div>
        <div id="status-error" class="pg-alert hidden" style="margin-top:0.85rem;"></div>
        <div id="status-result" class="pg-status-card hidden" style="margin-top:0.85rem;"></div>
      </div>
    </div>
  </main>

  <footer class="pg-footer">
    <div style="display:flex;align-items:center;gap:0.5rem;">
      <span class="pg-live-dot"></span>
      <span id="footer-status-label">Ready to scan</span>
    </div>
    <a href="staff_login.php" class="pg-footer-link">Switch staff</a>
    <a href="index.php" class="pg-footer-link">Home</a>
  </footer>


  <script>
    let activeStationType = <?php echo json_encode($stall_name); ?>;
    let html5QrcodeScanner = null;
    let terminalView = 'scan';
    let flashTimer = null;
    const BASE_URL = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const STALL_NAME = <?php echo json_encode($stall_name); ?>;
    const SHOW_WELCOME = <?php echo $show_welcome ? 'true' : 'false'; ?>;
    const HAS_SCAN_BENEFITS = <?php echo $has_scan_benefits ? 'true' : 'false'; ?>;

    document.addEventListener('DOMContentLoaded', () => {
      localStorage.setItem('passgate_auth', 'stall');
      preselectBenefitMatch(STALL_NAME);
      if (SHOW_WELCOME) {
        showToast(`Welcome, ${STALL_NAME}`, 'success');
      }
    });

    function switchTerminalMode(mode) {
      terminalView = mode;
      document.getElementById('tab-scan')?.classList.toggle('is-active', mode === 'scan');
      document.getElementById('tab-status')?.classList.toggle('is-active', mode === 'status');
      document.getElementById('panel-scan')?.classList.toggle('hidden', mode !== 'scan');
      document.getElementById('panel-status')?.classList.toggle('hidden', mode !== 'status');
      if (mode === 'status') {
        stopCameraEngineImmediate();
      }
    }

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

    document.getElementById('manual-ticket-id').addEventListener('keypress', (e) => { if (e.key === 'Enter') processManualScan(); });
    document.getElementById('status-ticket-id')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') lookupTicketStatus(); });

    function stopCameraEngineImmediate() {
      if (html5QrcodeScanner && html5QrcodeScanner.isScanning) {
        html5QrcodeScanner.stop().then(() => {
          document.getElementById('video-placeholder').classList.remove('hidden');
          document.getElementById('camera-trigger-btn').innerText = 'Start camera';
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
      localStorage.removeItem('passgate_auth');
      showToast('Logged out', 'success');
      window.location.replace(`${BASE_URL}/staff_login.php?logged_out=1`);
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
        showToast('Session expired — log in again', 'error');
        window.location.replace(`${BASE_URL}/staff_login.php?next=terminal.php`);
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

    async function lookupTicketStatus() {
      const input = document.getElementById('status-ticket-id');
      const errEl = document.getElementById('status-error');
      const resultEl = document.getElementById('status-result');
      let ticketId = input.value.trim();
      if (!ticketId) return;

      errEl.classList.add('hidden');
      resultEl.classList.add('hidden');
      resultEl.innerHTML = '<p class="pg-muted">Looking up…</p>';
      resultEl.classList.remove('hidden');

      if (/^\d+$/.test(ticketId)) ticketId = ticketId.padStart(6, '0');

      try {
        const res = await fetch(`${BASE_URL}/api.php?action=ticket_status&id=${encodeURIComponent(ticketId)}`);
        const data = await res.json();
        if (!res.ok || data.status !== 'success') {
          throw new Error(data.message || 'Ticket not found');
        }
        renderStatusResult(data.data);
      } catch (err) {
        resultEl.classList.add('hidden');
        errEl.textContent = err.message || 'Lookup failed';
        errEl.classList.remove('hidden');
      }
    }

    function renderStatusResult(d) {
      const resultEl = document.getElementById('status-result');
      const holderSub = d.holder_detail && d.holder_detail !== d.holder_label
        ? `<span class="pg-faint" style="display:block;font-size:0.72rem;margin-top:0.15rem;">${escapeHtml(d.holder_detail)}</span>`
        : '';
      const benefitsHtml = (d.benefits || []).map(b => {
        const full = b.used >= b.max;
        const pct = b.max > 0 ? Math.min(100, Math.round((b.used / b.max) * 100)) : 0;
        const last = b.last_scan ? `<span class="pg-faint" style="display:block;font-size:0.65rem;margin-top:0.2rem;">Last: ${escapeHtml(String(b.last_scan).slice(0, 16))}</span>` : '';
        return `<div class="pg-benefit${full ? ' is-full' : ''}">
          <div style="display:flex;justify-content:space-between;gap:0.5rem;">
            <span style="font-weight:600;">${escapeHtml(b.name)}</span>
            <span class="pg-mono" style="font-size:0.72rem;font-weight:700;">${b.used}/${b.max}</span>
          </div>
          <div class="pg-bar"><div class="pg-bar__fill${full ? ' is-full' : ''}" style="width:${pct}%"></div></div>
          ${last}
        </div>`;
      }).join('');

      resultEl.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.75rem;margin-bottom:0.85rem;">
          <span class="pg-mono" style="font-weight:700;word-break:break-all;">${escapeHtml(d.ticket_id)}</span>
          <span class="pg-status-chip ${d.status === 'Active' ? 'is-ready' : 'is-locked'}">${escapeHtml(d.status)}</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;font-size:0.82rem;margin-bottom:0.85rem;">
          <div><span class="pg-section-title" style="display:block;margin-bottom:0.15rem;">Event</span><strong>${escapeHtml(d.event)}</strong></div>
          <div><span class="pg-section-title" style="display:block;margin-bottom:0.15rem;">Tier</span><strong>${escapeHtml(d.tier)}</strong></div>
          <div><span class="pg-section-title" style="display:block;margin-bottom:0.15rem;">Physical #</span><strong>#${d.physical_number}</strong></div>
          <div><span class="pg-section-title" style="display:block;margin-bottom:0.15rem;">Assigned to</span><strong>${escapeHtml(d.holder_label)}</strong>${holderSub}</div>
        </div>
        <h4 class="pg-section-title" style="margin:0 0 0.45rem;">Benefits</h4>
        <div style="display:grid;gap:0.45rem;">${benefitsHtml || '<p class="pg-muted" style="margin:0;">No benefits on this tier.</p>'}</div>
      `;
      resultEl.classList.remove('hidden');
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
        showToast('Session expired — log in again', 'error');
        window.location.replace(`${BASE_URL}/staff_login.php?next=terminal.php`);
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
      const isWarn = type === 'warn';
      msgEl.innerText = msg;
      iconWrap.className = `pg-toast__icon ${isSuccess ? 'pg-toast__icon--ok' : 'pg-toast__icon--err'}`;
      iconWrap.innerHTML = isSuccess
        ? '<i class="fa-solid fa-check"></i>'
        : (isWarn ? '<i class="fa-solid fa-circle-exclamation"></i>' : '<i class="fa-solid fa-exclamation"></i>');
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
