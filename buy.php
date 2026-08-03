<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

$db = getDb();
$events = getEventsAvailableForPurchase($db);
$isLoggedIn = isCustomerAuthenticated();
$customerEmail = $isLoggedIn ? (string) ($_SESSION['customer_email'] ?? '') : '';
$error = $_GET['error'] ?? '';
$cancelled = isset($_GET['cancel']);
$stripeConfigured = getStripeClient() !== null;
$esewaConfigured = isEsewaConfigured();
$anyGateway = $stripeConfigured || $esewaConfigured;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Buy Tickets – PassGate Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen">
  <header class="bg-indigo-600 text-white px-4 py-4 flex items-center justify-between">
    <h1 class="font-bold text-lg"><i class="fa-solid fa-ticket mr-2"></i>Buy Tickets</h1>
    <div class="text-sm space-x-3">
      <?php if ($isLoggedIn): ?>
        <a href="customer_dashboard.php" class="text-indigo-100 hover:text-white">My tickets</a>
      <?php else: ?>
        <a href="customer_login.php" class="text-indigo-100 hover:text-white">Log in</a>
      <?php endif; ?>
      <a href="index.php" class="text-indigo-200 hover:text-white">Terminal</a>
    </div>
  </header>

  <main class="max-w-3xl mx-auto p-4 space-y-6">
    <?php if ($error !== ''): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-3 text-sm"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($cancelled): ?>
      <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-3 text-sm">Payment was cancelled.</div>
    <?php endif; ?>
    <?php if (!$stripeConfigured && !$esewaConfigured): ?>
      <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-3 text-sm">
        <strong>No payment gateway configured.</strong> Add Stripe and/or eSewa keys to <code>.env</code>.
      </div>
    <?php elseif (!$stripeConfigured): ?>
      <div class="bg-slate-100 border border-slate-200 text-slate-600 rounded-xl px-4 py-3 text-sm">Stripe is not configured — only eSewa is available.</div>
    <?php elseif (!$esewaConfigured): ?>
      <div class="bg-slate-100 border border-slate-200 text-slate-600 rounded-xl px-4 py-3 text-sm">eSewa is not configured — only Stripe is available.</div>
    <?php endif; ?>

    <?php if ($events === []): ?>
      <div class="bg-white rounded-2xl border p-8 text-center text-slate-500">No tickets available for online purchase right now.</div>
    <?php else: ?>
      <?php foreach ($events as $event): ?>
        <section class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
          <div class="bg-slate-800 text-white px-5 py-4">
            <h2 class="font-bold text-lg"><?php echo htmlspecialchars($event['name']); ?></h2>
          </div>
          <div class="p-5 space-y-5">
            <?php foreach ($event['tiers'] as $tier): ?>
              <div class="border border-slate-100 rounded-xl p-4 space-y-3">
                <div class="flex justify-between items-start">
                  <div>
                    <h3 class="font-bold text-slate-900"><?php echo htmlspecialchars($tier['name']); ?></h3>
                    <p class="text-indigo-600 font-bold"><?php echo formatPrice($tier['price']); ?></p>
                    <p class="text-xs text-slate-400"><?php echo (int) $tier['available']; ?> remaining</p>
                  </div>
                </div>
                <?php if ($tier['benefits'] !== []): ?>
                  <ul class="text-sm text-slate-600 space-y-1">
                    <?php foreach ($tier['benefits'] as $benefit): ?>
                      <li><i class="fa-solid fa-check text-emerald-500 mr-1 text-xs"></i><?php echo htmlspecialchars($benefit['name']); ?> (×<?php echo (int) $benefit['max_uses']; ?>)</li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>

                <form class="buy-form space-y-3" data-event-id="<?php echo (int) $event['id']; ?>" data-tier-id="<?php echo (int) $tier['id']; ?>">
                  <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
                  <input type="hidden" name="tier_id" value="<?php echo (int) $tier['id']; ?>">

                  <label class="block text-[10px] uppercase font-bold text-slate-400">Email for ticket delivery</label>
                  <input type="email" name="email" required
                         value="<?php echo htmlspecialchars($customerEmail); ?>"
                         class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm"
                         placeholder="you@email.com">

                  <fieldset class="space-y-2">
                    <legend class="text-[10px] uppercase font-bold text-slate-400 mb-1">Payment method</legend>
                    <?php if ($stripeConfigured): ?>
                      <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="payment_method" value="stripe" class="text-indigo-600" <?php echo $stripeConfigured ? 'checked' : ''; ?> <?php echo !$stripeConfigured ? 'disabled' : ''; ?>>
                        <i class="fa-brands fa-stripe text-indigo-600"></i> Stripe (card)
                      </label>
                    <?php endif; ?>
                    <?php if ($esewaConfigured): ?>
                      <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="payment_method" value="esewa" class="text-green-600" <?php echo !$stripeConfigured && $esewaConfigured ? 'checked' : ''; ?> <?php echo !$esewaConfigured ? 'disabled' : ''; ?>>
                        <span class="font-semibold text-green-700">eSewa</span> (wallet)
                      </label>
                    <?php endif; ?>
                  </fieldset>

                  <button type="submit" <?php echo $anyGateway ? '' : 'disabled'; ?>
                          class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 text-white font-bold rounded-xl py-2.5 text-sm">
                    Continue to payment
                  </button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

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

        btn.disabled = true;
        btn.textContent = 'Redirecting…';

        const endpoint = paymentMethod === 'esewa' ? 'esewa_initiate.php' : 'create_checkout_session.php';

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
            btn.textContent = 'Continue to payment';
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
          btn.textContent = 'Continue to payment';
        } catch (err) {
          alert('Network error — is the server running?');
          btn.disabled = false;
          btn.textContent = 'Continue to payment';
        }
      });
    });
  </script>
</body>
</html>
