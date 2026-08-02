<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

if (
    !isset($_SESSION['distributor_authenticated'])
    || $_SESSION['distributor_authenticated'] !== true
    || ($_SESSION['distributor_role'] ?? '') !== 'admin'
) {
    header('Location: index.php');
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
    <meta charset="UTF-8">
    <title>PassGate Pro – Event Setup</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 30px; background: #f0f4f8; color: #333; }
        .container { max-width: 750px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        h2 { border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; color: #1e293b; }
        label { display: block; margin-top: 15px; font-weight: 600; font-size: 14px; color: #475569; }
        input[type="text"], input[type="number"] { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        .tier-card { border: 1px solid #cbd5e1; border-left: 6px solid #2563eb; padding: 20px; margin-top: 20px; border-radius: 8px; background: #f8fafc; position: relative; }
        .benefit-row { display: flex; gap: 10px; margin-top: 8px; align-items: center; }
        .benefit-row input { margin-top: 0; }
        .btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-secondary { background: #64748b; color: white; margin-top: 10px; }
        .btn-danger { background: #dc2626; color: white; padding: 6px 10px; }
        .btn-submit { background: #16a34a; color: white; width: 100%; padding: 14px; font-size: 16px; margin-top: 30px; border-radius: 8px; font-weight: bold; border: none; text-align: center; }
        .tier-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .confirm-box { background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px dashed #2563eb; margin-top: 15px; }
        .confirm-tier-summary { margin-left: 20px; padding: 10px; border-left: 3px solid #64748b; background: #fff; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="container">
    <?php if ($is_confirmation_stage): ?>
        <h2>Confirm Event Creation</h2>
        <div class="confirm-box">
            <p><strong>Event:</strong> <?php echo htmlspecialchars($payload['event_name']); ?></p>
            <p><strong>Capacity:</strong> <?php echo htmlspecialchars($payload['total_tickets']); ?> tickets</p>
            <?php
            if (isset($payload['tier_name'])):
                foreach ($payload['tier_name'] as $tIdx => $name):
                    if (empty($name)) continue;
                    ?>
                    <div class="confirm-tier-summary">
                        <strong><?php echo htmlspecialchars($name); ?></strong> –
                        <?php echo (int) $payload['tier_qty'][$tIdx]; ?> @ $<?php echo number_format((float) $payload['tier_price'][$tIdx], 2); ?>
                        <ul style="margin:5px 0 0 0; padding-left:20px; font-size:13px;">
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
            <button type="submit" class="btn btn-submit">Initialize Event in Database</button>
        </form>
        <div style="text-align:center; margin-top:15px;">
            <a href="setup.php" class="btn btn-secondary" style="width:calc(100% - 28px); display:block; box-sizing:border-box; text-align:center;">Go Back</a>
        </div>
    <?php else: ?>
        <h2>Event Setup Wizard</h2>
        <p style="color:#64748b;font-size:14px;margin-bottom:15px;">Create a new event with ticket tiers and benefits. You can run this as many times as you need.</p>
        <form method="POST" id="setupForm">
            <label>Event Name</label>
            <input type="text" name="event_name" required value="<?php echo htmlspecialchars($payload['event_name'] ?? ''); ?>" placeholder="e.g., Global Tech Summit 2026">
            <label>Total Event Capacity</label>
            <input type="number" id="total_capacity" name="total_tickets" required value="<?php echo htmlspecialchars($payload['total_tickets'] ?? ''); ?>" placeholder="e.g., 5000" oninput="validateFormState()">
            <div id="capacity_warning" style="display:none; color:#dc2626; font-size:13px; font-weight:bold; margin-top:5px;"></div>
            <div id="tier-container"></div>
            <button type="button" class="btn btn-secondary" onclick="addTicketTier('+ Add Ticket Tier')">+ Add Ticket Tier</button>
            <button type="submit" id="submit_btn" class="btn btn-submit" style="background:#2563eb;">Preview & Verify</button>
        </form>
        <?php if (hasAnyEvents($db)): ?>
        <div style="text-align:center;margin-top:15px;">
            <a href="distributors.php?tab=events" class="btn btn-secondary" style="width:calc(100% - 28px);display:block;box-sizing:border-box;text-align:center;">Back to Events</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
let tierCounter = 0;
const oldPayload = <?php echo json_encode($payload); ?>;

function addTicketTier(savedName = '', savedQty = '', savedPrice = '', savedBenefits = null) {
    const container = document.getElementById('tier-container');
    if (!container) return;
    const tierIndex = tierCounter++;
    container.insertAdjacentHTML('beforeend', `
        <div class="tier-card" id="tier_card_${tierIndex}">
            <div class="tier-header">
                <h3>Ticket Tier</h3>
                <button type="button" class="btn btn-danger" onclick="removeElement('tier_card_${tierIndex}')">Remove</button>
            </div>
            <div style="display:flex; gap:15px;">
                <div style="flex:2;"><label>Tier Name</label><input type="text" name="tier_name[${tierIndex}]" required value="${savedName === '+ Add Ticket Tier' ? '' : savedName}" placeholder="VIP"></div>
                <div style="flex:1;"><label>Qty</label><input type="number" name="tier_qty[${tierIndex}]" class="tier-qty-input" required value="${savedQty}" oninput="validateFormState()"></div>
                <div style="flex:1;"><label>Price</label><input type="text" name="tier_price[${tierIndex}]" required value="${savedPrice}" placeholder="199.00"></div>
            </div>
            <h4 style="margin-top:20px;">Benefits</h4>
            <div id="benefit_container_${tierIndex}"></div>
            <button type="button" class="btn btn-secondary" style="background:#0284c7; padding:5px 10px; font-size:11px;" onclick="addBenefitRow(${tierIndex})">+ Add Benefit</button>
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
            <input type="number" name="benefit_max[${tierIndex}][${benefitIndex}]" class="benefit-max-input" required value="${bMax}" placeholder="Max" style="flex:1.5;" oninput="validateFormState()">
            <button type="button" class="btn btn-danger" onclick="removeElement('benefit_row_${tierIndex}_${benefitIndex}')">X</button>
        </div>`);
}

function removeElement(id) { document.getElementById(id)?.remove(); validateFormState(); }

function validateFormState() {
    const totalCapacityInput = document.getElementById('total_capacity');
    const warningDiv = document.getElementById('capacity_warning');
    const submitBtn = document.getElementById('submit_btn');
    if (!totalCapacityInput) return;
    const maxCapacity = parseInt(totalCapacityInput.value) || 0;
    let runningTierSum = 0;
    document.querySelectorAll('.tier-qty-input').forEach(input => { runningTierSum += parseInt(input.value) || 0; });
    let capacityIsValid = false;
    if (maxCapacity > 0 && runningTierSum === maxCapacity) capacityIsValid = true;
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
