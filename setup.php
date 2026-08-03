<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

if (
    !isset($_SESSION['distributor_authenticated'])
    || $_SESSION['distributor_authenticated'] !== true
    || ($_SESSION['distributor_role'] ?? '') !== 'admin'
) {
    header('Location: terminal.php');
    exit;
}

$db = getDb();

$is_confirmation_stage = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['confirmed'])) {
    $_SESSION['setup_payload'] = $_POST;
    $is_confirmation_stage = true;
    $payload = $_POST;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmed'])) {
    if (!isset($_SESSION['setup_payload'])) {
        header('Location: setup.php');
        exit;
    }

    $payload = $_SESSION['setup_payload'];
    $eventName = trim($payload['event_name'] ?? '');

    $tiers = [];
    if (isset($payload['tier_name']) && is_array($payload['tier_name'])) {
        foreach ($payload['tier_name'] as $tIdx => $name) {
            if (empty($name)) {
                continue;
            }
            $tier = [
                'name'     => $name,
                'qty'      => (int) ($payload['tier_qty'][$tIdx] ?? 0),
                'price'    => (float) ($payload['tier_price'][$tIdx] ?? 0),
                'benefits' => [],
            ];
            if (isset($payload['benefit_name'][$tIdx]) && is_array($payload['benefit_name'][$tIdx])) {
                foreach ($payload['benefit_name'][$tIdx] as $bIdx => $bName) {
                    if (!empty($bName)) {
                        $tier['benefits'][] = [
                            'name' => $bName,
                            'max'  => (int) ($payload['benefit_max'][$tIdx][$bIdx] ?? 1),
                        ];
                    }
                }
            }
            $tiers[] = $tier;
        }
    }

    try {
        $db->beginTransaction();

        $eventId = createEventWithTiers($db, $eventName, $tiers);

        $db->commit();
        auditLog('INIT', "Event '{$eventName}' (id {$eventId}) initialized with " . count($tiers) . ' tier(s).');
        unset($_SESSION['setup_payload']);
        $_SESSION['selected_event_id'] = $eventId;
        $_SESSION['terminal_event_id'] = $eventId;
        safeRedirect('tickets_qr.php?' . adminEventQuery($eventId));
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        auditLog('INIT', 'Setup failed: ' . $e->getMessage());
        die('Setup failed: ' . htmlspecialchars($e->getMessage()));
    }
} else {
    $payload = $_SESSION['setup_payload'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    passgateRenderHead('PassGate – Event Setup', [
        'extra' => <<<'CSS'
<style>
.pg-setup { max-width: 46rem; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }
.pg-setup-card {
  background: #fff;
  border: 1px solid var(--pg-border);
  border-radius: var(--pg-radius);
  padding: 1.5rem;
  box-shadow: var(--pg-shadow);
  position: relative;
  overflow: hidden;
}
.pg-setup-card::before {
  content: '';
  position: absolute;
  left: 0; right: 0; top: 0;
  height: 5px;
  background: linear-gradient(90deg, #2563eb, #0ea5e9, #12b886);
}
.pg-setup-card h2 {
  margin: 0 0 0.35rem;
  font-family: var(--pg-display);
  font-size: 1.55rem;
  letter-spacing: -0.03em;
  color: var(--pg-text);
}
.pg-setup label {
  display: block;
  margin-top: 1rem;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--pg-text-muted);
}
.pg-setup input[type="text"],
.pg-setup input[type="number"] {
  width: 100%;
  margin-top: 0.4rem;
  padding: 0.8rem 0.95rem;
  border-radius: var(--pg-radius-sm);
  border: 1.5px solid #e2e8f0;
  background: #fff;
  color: var(--pg-text);
  font: inherit;
  font-weight: 600;
  box-sizing: border-box;
}
.pg-setup input:focus {
  outline: none;
  border-color: var(--pg-gold);
  box-shadow: 0 0 0 4px var(--pg-gold-dim);
}
.tier-card {
  margin-top: 1.1rem;
  padding: 1.1rem;
  border-radius: var(--pg-radius);
  border: 1px solid #e2e8f0;
  border-left: 4px solid #2563eb;
  background: #f8fafc;
}
.tier-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; }
.tier-header h3 { margin: 0; font-family: var(--pg-display); font-size: 1.05rem; color: var(--pg-text); }
.benefit-row { display: flex; gap: 0.55rem; margin-top: 0.55rem; align-items: center; }
.benefit-row input { margin-top: 0 !important; }
.confirm-box {
  margin-top: 1rem;
  padding: 1.1rem;
  border-radius: var(--pg-radius);
  border: 1.5px dashed rgba(37, 99, 235, 0.35);
  background: #f8fafc;
  color: var(--pg-text);
}
.confirm-tier-summary {
  margin: 0.75rem 0 0;
  padding: 0.75rem 0.85rem;
  border-left: 3px solid #2563eb;
  background: #fff;
  border-radius: 0 0.55rem 0.55rem 0;
  border: 1px solid #e2e8f0;
  border-left-width: 3px;
}
#capacity_warning { display: none; color: var(--pg-deny); font-size: 0.8rem; font-weight: 700; margin-top: 0.4rem; }
</style>
CSS
    ]);
    ?>
</head>
<body class="pg-body pg-admin">
<div class="pg-setup">
    <div class="pg-setup-card">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.15rem;">
            <div class="pg-brand-mark" style="width:2.3rem;height:2.3rem;border-radius:0.75rem;font-size:0.9rem;">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
            <div>
                <p class="pg-eyebrow" style="margin:0;">Create event</p>
                <h2 style="margin:0;border:none;padding:0;"><?php echo $is_confirmation_stage ? 'Confirm event' : 'Create event'; ?></h2>
            </div>
        </div>

        <?php if ($is_confirmation_stage): ?>
            <p class="pg-muted" style="margin:0 0 0.75rem;font-size:0.88rem;">Review tiers and benefits, then create the Vault Pool.</p>
            <div class="confirm-box">
                <p style="margin:0;"><strong>Event:</strong> <?php echo htmlspecialchars($payload['event_name']); ?></p>
                <p style="margin:0.4rem 0 0;"><strong>Capacity:</strong> <?php echo htmlspecialchars($payload['total_tickets']); ?> tickets</p>
                <?php
                if (isset($payload['tier_name'])):
                    foreach ($payload['tier_name'] as $tIdx => $name):
                        if (empty($name)) continue;
                        ?>
                        <div class="confirm-tier-summary">
                            <strong><?php echo htmlspecialchars($name); ?></strong> –
                            <?php echo (int) $payload['tier_qty'][$tIdx]; ?> @ <?php echo formatPrice((float) $payload['tier_price'][$tIdx]); ?>
                            <ul style="margin:0.4rem 0 0; padding-left:1.2rem; font-size:0.82rem; color:var(--pg-text-muted);">
                                <?php
                                if (isset($payload['benefit_name'][$tIdx])):
                                    foreach ($payload['benefit_name'][$tIdx] as $bIdx => $bName):
                                        if (empty($bName)) continue;
                                        echo '<li>' . htmlspecialchars($bName) . ' (max: ' . (int) $payload['benefit_max'][$tIdx][$bIdx] . ')</li>';
                                    endforeach;
                                endif;
                                ?>
                            </ul>
                        </div>
                    <?php endforeach; endif; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="pg-btn pg-btn--gold" style="margin-top:1.25rem;">Create Vault Pool</button>
            </form>
            <a href="setup.php" class="pg-btn pg-btn--ghost" style="width:100%;margin-top:0.65rem;">Go back</a>
        <?php else: ?>
            <p class="pg-muted" style="margin:0 0 0.5rem;font-size:0.88rem;">
                Name the event, set capacity, then add tiers and benefits. Stall names should match benefit names later.
            </p>
            <form method="POST" id="setupForm">
                <label for="event_name">Event name</label>
                <input type="text" id="event_name" name="event_name" required value="<?php echo htmlspecialchars($payload['event_name'] ?? ''); ?>" placeholder="e.g. Test Fest 2026">

                <label for="total_capacity">Total capacity</label>
                <input type="number" id="total_capacity" name="total_tickets" required value="<?php echo htmlspecialchars($payload['total_tickets'] ?? ''); ?>" placeholder="e.g. 50" oninput="validateFormState()">
                <div id="capacity_warning"></div>

                <div id="tier-container"></div>

                <button type="button" class="pg-btn pg-btn--ghost" style="margin-top:0.85rem;" onclick="addTicketTier()">+ Add ticket tier</button>
                <button type="submit" id="submit_btn" class="pg-btn pg-btn--gold" style="margin-top:0.85rem;">Preview &amp; verify</button>
            </form>
            <?php if (hasAnyEvents($db)): ?>
            <a href="distributors.php?tab=events" class="pg-btn pg-btn--ghost" style="width:100%;margin-top:0.75rem;">Back to Events</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
let tierCounter = 0;
const oldPayload = <?php echo json_encode($payload); ?>;

function addTicketTier(savedName = '', savedQty = '', savedPrice = '', savedBenefits = null) {
    const container = document.getElementById('tier-container');
    if (!container) return;
    const tierIndex = tierCounter++;
    const nameVal = savedName === '+ Add Ticket Tier' ? '' : savedName;
    container.insertAdjacentHTML('beforeend', `
        <div class="tier-card" id="tier_card_${tierIndex}">
            <div class="tier-header">
                <h3>Ticket tier</h3>
                <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" style="color:var(--pg-deny);border-color:var(--pg-deny-border);" onclick="removeElement('tier_card_${tierIndex}')">Remove</button>
            </div>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <div style="flex:2;min-width:140px;"><label>Tier name</label><input type="text" name="tier_name[${tierIndex}]" required value="${nameVal}" placeholder="VIP"></div>
                <div style="flex:1;min-width:90px;"><label>Qty</label><input type="number" name="tier_qty[${tierIndex}]" class="tier-qty-input" required value="${savedQty}" oninput="validateFormState()"></div>
                <div style="flex:1;min-width:110px;"><label>Price (NRS)</label><input type="text" name="tier_price[${tierIndex}]" required value="${savedPrice}" placeholder="1999.00"></div>
            </div>
            <p class="pg-section-title" style="margin-top:1rem;">Benefits (stall names)</p>
            <div id="benefit_container_${tierIndex}"></div>
            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" style="margin-top:0.55rem;" onclick="addBenefitRow(${tierIndex})">+ Add benefit</button>
        </div>`);
    if (savedBenefits && savedBenefits.names.length > 0) {
        savedBenefits.names.forEach((n, i) => addBenefitRow(tierIndex, n, savedBenefits.maxes[i]));
    } else {
        addBenefitRow(tierIndex);
    }
    validateFormState();
}

function addBenefitRow(tierIndex, bName = '', bMax = '') {
    const container = document.getElementById(`benefit_container_${tierIndex}`);
    const benefitIndex = container.children.length;
    container.insertAdjacentHTML('beforeend', `
        <div class="benefit-row" id="benefit_row_${tierIndex}_${benefitIndex}">
            <input type="text" name="benefit_name[${tierIndex}][${benefitIndex}]" required value="${bName}" placeholder="Lunch" style="flex:3;">
            <input type="number" name="benefit_max[${tierIndex}][${benefitIndex}]" class="benefit-max-input" required value="${bMax}" placeholder="Max" style="flex:1.2;" oninput="validateFormState()">
            <button type="button" class="pg-btn pg-btn--ghost pg-btn--sm" style="color:var(--pg-deny);border-color:var(--pg-deny-border);" onclick="removeElement('benefit_row_${tierIndex}_${benefitIndex}')">X</button>
        </div>`);
}

function removeElement(id) { document.getElementById(id)?.remove(); validateFormState(); }

function validateFormState() {
    const totalCapacityInput = document.getElementById('total_capacity');
    const warningDiv = document.getElementById('capacity_warning');
    const submitBtn = document.getElementById('submit_btn');
    if (!totalCapacityInput || !submitBtn) return;
    const maxCapacity = parseInt(totalCapacityInput.value) || 0;
    let runningTierSum = 0;
    document.querySelectorAll('.tier-qty-input').forEach(input => { runningTierSum += parseInt(input.value) || 0; });
    let capacityIsValid = maxCapacity > 0 && runningTierSum === maxCapacity;
    if (maxCapacity > 0 && runningTierSum !== maxCapacity) {
        warningDiv.style.display = 'block';
        warningDiv.textContent = `Tier quantities (${runningTierSum}) must equal total capacity (${maxCapacity}).`;
    } else {
        warningDiv.style.display = 'none';
        warningDiv.textContent = '';
    }
    let benefitsAreValid = true;
    document.querySelectorAll('.benefit-max-input').forEach(input => {
        const val = parseInt(input.value);
        if (input.value !== '' && (isNaN(val) || val <= 0)) benefitsAreValid = false;
    });
    submitBtn.disabled = !(capacityIsValid && benefitsAreValid);
    submitBtn.style.opacity = submitBtn.disabled ? '0.5' : '1';
}

window.onload = function() {
    if (oldPayload && oldPayload.tier_name) {
        Object.keys(oldPayload.tier_name).forEach(key => {
            const benefitsData = { names: [], maxes: [] };
            if (oldPayload.benefit_name && oldPayload.benefit_name[key]) {
                Object.keys(oldPayload.benefit_name[key]).forEach(bKey => {
                    benefitsData.names.push(oldPayload.benefit_name[key][bKey]);
                    benefitsData.maxes.push(oldPayload.benefit_max[key][bKey]);
                });
            }
            addTicketTier(oldPayload.tier_name[key], oldPayload.tier_qty[key], oldPayload.tier_price[key], benefitsData);
        });
    } else {
        addTicketTier();
    }
};
</script>
</body>
</html>
