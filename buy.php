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
                            <p class="pg-tier-price"><?php echo formatPrice($tier['price']); ?></p>
                            <p class="pg-tier-meta"><?php echo (int) $tier['available']; ?> remaining in vault</p>

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

                            <form class="buy-form" data-event-id="<?php echo (int) $event['id']; ?>" data-tier-id="<?php echo (int) $tier['id']; ?>">
                                <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                                <input type="hidden" name="tier_id" value="<?php echo (int) $tier['id']; ?>">
                                <input type="hidden" name="email" value="<?php echo htmlspecialchars($customerEmail); ?>">

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
      email: fd.get('email')
    };

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
