<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$db = getDb();
ensureCustomerSchema($db);

if (!isCustomerAuthenticated()) {
    safeRedirect('customer_login.php?next=buy.php');
}

$customer = getAuthenticatedCustomer($db);
if ($customer === null) {
    safeRedirect('customer_login.php?next=buy.php');
}

$events = getEventsAvailableForPurchase($db);
$isLoggedIn = true;
$customerEmail = (string) $customer['email'];
$error = $_GET['error'] ?? '';
$cancelled = isset($_GET['cancel']);
$stripeConfigured = getStripeClient() !== null;
$esewaConfigured = isEsewaConfigured();
$devCheckout = isDevCheckoutEnabled();
$anyGateway = $stripeConfigured || $esewaConfigured || $devCheckout;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php passgateRenderHead('PassGate – Buy Tickets'); ?>
</head>
<body class="pg-body">
    <?php passgateRenderPublicNav('buy'); ?>
    <?php passgateRenderBreadcrumb([
        ['label' => 'Home', 'href' => 'index.php'],
        ['label' => 'Buy tickets'],
    ]); ?>
<div class="pg-shop-wrap">
    <div class="pg-shop-top">
        <div>
            <p class="pg-eyebrow" style="margin:0;">Online tickets</p>
            <h1>Buy Tickets</h1>
            <p class="pg-muted" style="margin:0.35rem 0 0;font-size:0.86rem;">
                Signed in as <strong><?php echo htmlspecialchars($customerEmail); ?></strong> — tickets go to this account.
            </p>
        </div>
        <nav class="pg-shop-nav">
            <a class="pg-btn pg-btn--ghost pg-btn--sm" href="events.php">Browse events</a>
            <a class="pg-btn pg-btn--ghost pg-btn--sm" href="customer_dashboard.php">My tickets</a>
        </nav>
    </div>

    <?php if ($error !== ''): ?>
        <div class="pg-notice pg-notice--error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($cancelled): ?>
        <div class="pg-notice pg-notice--warn">Payment was cancelled. You can try again.</div>
    <?php endif; ?>

    <?php if (!$stripeConfigured && !$esewaConfigured && !$devCheckout): ?>
        <div class="pg-notice pg-notice--warn">
            <strong>No payment gateway configured.</strong>
            Add Stripe and/or eSewa keys to <code>.env</code> (see <code>.env.example</code>).
        </div>
    <?php elseif (!$stripeConfigured && $esewaConfigured): ?>
        <div class="pg-notice pg-notice--info">Paying with eSewa. Add Stripe test keys to enable card checkout.</div>
    <?php elseif ($stripeConfigured && !$esewaConfigured): ?>
        <div class="pg-notice pg-notice--info">Paying with Stripe. Add eSewa keys to enable wallet checkout.</div>
    <?php endif; ?>

    <?php if ($events === []): ?>
        <div class="pg-empty">
            <div class="pg-empty__icon"><i class="fa-solid fa-ticket"></i></div>
            <h3>No tickets available</h3>
            <p>Create an event and keep tickets in the Vault Pool to sell them online.</p>
        </div>
    <?php else: ?>
        <?php foreach ($events as $event): ?>
            <section class="pg-event-block">
                <div class="pg-event-block__head">
                    <h2><?php echo htmlspecialchars($event['name']); ?></h2>
                </div>
                <div class="pg-event-block__body">
                    <?php foreach ($event['tiers'] as $tier): ?>
                        <div class="pg-tier-card">
                            <h3><?php echo htmlspecialchars($tier['name']); ?></h3>
                            <p class="pg-tier-price"><?php echo formatPrice($tier['price']); ?> <span class="pg-faint" style="font-size:0.72rem;font-weight:700;">per ticket</span></p>
                            <p class="pg-tier-meta"><span class="tier-available-count"><?php echo (int) $tier['available']; ?></span> available</p>

                            <?php if ($tier['benefits'] !== []): ?>
                                <ul class="pg-benefit-list">
                                    <?php foreach ($tier['benefits'] as $benefit): ?>
                                        <li>
                                            <i class="fa-solid fa-check"></i>
                                            <?php echo htmlspecialchars($benefit['name']); ?>
                                            (×<?php echo (int) $benefit['max_uses']; ?>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <form class="buy-form"
                                  data-event-id="<?php echo (int) $event['id']; ?>"
                                  data-tier-id="<?php echo (int) $tier['id']; ?>"
                                  data-unit-price="<?php echo htmlspecialchars((string) $tier['price'], ENT_QUOTES); ?>"
                                  data-available="<?php echo (int) $tier['available']; ?>"
                                  data-gateway="<?php echo $anyGateway ? '1' : '0'; ?>">
                                <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                                <input type="hidden" name="tier_id" value="<?php echo (int) $tier['id']; ?>">
                                <input type="hidden" name="email" value="<?php echo htmlspecialchars($customerEmail); ?>">

                                <div class="pg-qty-row">
                                    <div class="pg-qty-control">
                                        <label for="qty-<?php echo (int) $tier['id']; ?>">Quantity</label>
                                        <button type="button" class="pg-qty-btn qty-minus" aria-label="Decrease quantity">−</button>
                                        <input type="number"
                                               id="qty-<?php echo (int) $tier['id']; ?>"
                                               name="quantity"
                                               class="pg-qty-input tier-quantity"
                                               value="1"
                                               min="1"
                                               max="<?php echo (int) $tier['available']; ?>"
                                               inputmode="numeric">
                                        <button type="button" class="pg-qty-btn qty-plus" aria-label="Increase quantity">+</button>
                                    </div>
                                    <div class="pg-tier-total">
                                        <span class="pg-tier-total__label">Total</span>
                                        <span class="pg-tier-total__amount tier-total"><?php echo formatPrice($tier['price']); ?></span>
                                    </div>
                                </div>
                                <p class="pg-qty-error hidden tier-qty-error"></p>

                                <fieldset class="pg-pay-options">
                                    <legend>Payment method</legend>
                                    <?php if ($stripeConfigured): ?>
                                        <label class="pg-pay-option">
                                            <input type="radio" name="payment_method" value="stripe" <?php echo $stripeConfigured ? 'checked' : ''; ?>>
                                            <i class="fa-brands fa-stripe" style="color:#635bff;"></i>
                                            <span>Stripe (card)</span>
                                        </label>
                                    <?php endif; ?>
                                    <?php if ($esewaConfigured): ?>
                                        <label class="pg-pay-option">
                                            <input type="radio" name="payment_method" value="esewa" <?php echo !$stripeConfigured ? 'checked' : ''; ?>>
                                            <i class="fa-solid fa-wallet" style="color:#087f5b;"></i>
                                            <span>eSewa</span>
                                        </label>
                                    <?php endif; ?>
                                    <?php if ($devCheckout): ?>
                                        <label class="pg-pay-option">
                                            <input type="radio" name="payment_method" value="dev" <?php echo !$stripeConfigured && !$esewaConfigured ? 'checked' : ''; ?>>
                                            <i class="fa-solid fa-flask" style="color:#d97706;"></i>
                                            <span>Test purchase (local debug)</span>
                                        </label>
                                    <?php endif; ?>
                                </fieldset>

                                <button type="submit" class="pg-btn pg-btn--gold" style="width:100%;margin-top:0.35rem;" <?php echo $anyGateway ? '' : 'disabled'; ?>>
                                    Continue to payment
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php passgateRenderPublicFooter(); ?>
<script>
function formatMoney(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n)) return '—';
  return 'NRS ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function clampQuantity(form, rawValue) {
  const available = parseInt(form.dataset.available || '1', 10);
  let qty = parseInt(String(rawValue), 10);
  if (!Number.isFinite(qty) || qty < 1) qty = 1;
  if (qty > available) qty = available;
  return qty;
}

function updateTierFormTotals(form) {
  const qtyInput = form.querySelector('.tier-quantity');
  const totalEl = form.querySelector('.tier-total');
  const errEl = form.querySelector('.tier-qty-error');
  const submitBtn = form.querySelector('button[type="submit"]');
  const minusBtn = form.querySelector('.qty-minus');
  const plusBtn = form.querySelector('.qty-plus');
  const unitPrice = parseFloat(form.dataset.unitPrice || '0');
  const available = parseInt(form.dataset.available || '1', 10);
  const qty = clampQuantity(form, qtyInput.value);

  qtyInput.value = String(qty);
  totalEl.textContent = formatMoney(unitPrice * qty);

  minusBtn.disabled = qty <= 1;
  plusBtn.disabled = qty >= available;

  const invalid = qty > available || qty < 1;
  errEl.classList.toggle('hidden', !invalid);
  errEl.textContent = invalid ? `Only ${available} ticket(s) available.` : '';
  submitBtn.disabled = invalid || form.dataset.gateway !== '1';
}

document.querySelectorAll('.buy-form').forEach(form => {
  const qtyInput = form.querySelector('.tier-quantity');
  form.querySelector('.qty-minus')?.addEventListener('click', () => {
    qtyInput.value = String(clampQuantity(form, parseInt(qtyInput.value, 10) - 1));
    updateTierFormTotals(form);
  });
  form.querySelector('.qty-plus')?.addEventListener('click', () => {
    qtyInput.value = String(clampQuantity(form, parseInt(qtyInput.value, 10) + 1));
    updateTierFormTotals(form);
  });
  qtyInput?.addEventListener('input', () => updateTierFormTotals(form));
  qtyInput?.addEventListener('change', () => updateTierFormTotals(form));
  updateTierFormTotals(form);
});

function submitEsewaForm(payload) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = payload.esewa_url;
  Object.entries(payload).forEach(([key, value]) => {
    if (key === 'esewa_url') return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = value;
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
}

document.querySelectorAll('.buy-form').forEach(form => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const fd = new FormData(form);
    const paymentMethod = fd.get('payment_method');
    const body = {
      event_id: fd.get('event_id'),
      tier_id: fd.get('tier_id'),
      email: fd.get('email'),
      quantity: fd.get('quantity')
    };

    const available = parseInt(form.dataset.available || '1', 10);
    const qty = clampQuantity(form, body.quantity);
    if (qty > available) {
      alert(`Only ${available} ticket(s) available.`);
      return;
    }
    body.quantity = qty;

    if (!paymentMethod) {
      alert('Choose a payment method.');
      return;
    }

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Redirecting…';

    let endpoint = 'create_checkout_session.php';
    if (paymentMethod === 'esewa') endpoint = 'esewa_initiate.php';
    if (paymentMethod === 'dev') endpoint = 'dev_purchase.php';

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      const raw = await res.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch (parseErr) {
        alert('Server error (invalid response). Check PHP logs.');
        console.error(raw);
        btn.disabled = false;
        btn.textContent = original;
        return;
      }

      if (paymentMethod === 'esewa' && data.status === 'success' && data.esewa_payload) {
        submitEsewaForm(data.esewa_payload);
        return;
      }

      if (data.url) {
        window.location.href = data.url;
        return;
      }

      alert(data.message || 'Checkout failed');
      btn.disabled = false;
      btn.textContent = original;
    } catch (err) {
      alert('Network error — is the server running?');
      btn.disabled = false;
      btn.textContent = original;
    }
  });
});
</script>
</body>
</html>
