<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Append a line to the application audit log.
 */
function auditLog(string $category, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $line = "[{$timestamp}] [{$category}] [IP: {$ip}] -> {$message}\n";
    file_put_contents(__DIR__ . '/../system_audit.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Normalize benefit/station names for comparison.
 */
function normalizeStationName(string $name): string
{
    return strtolower(trim($name));
}

/**
 * Format a monetary amount in Nepalese Rupees (NRS).
 */
function formatPrice(float|string $amount): string
{
    return 'NRS ' . number_format((float) $amount, 2);
}

/**
 * Process a ticket scan against a station/benefit name.
 *
 * @param int|null $stallId Optional stall attribution for the scan row.
 * @return array<string, mixed>
 */
function processTicketScan(PDO $db, string $ticketId, string $station, ?int $stallId = null): array
{
    $ticketId = trim($ticketId);

    $ticketStmt = $db->prepare(
        'SELECT t.*, ti.name AS tier_name, e.name AS event_name
         FROM tickets t
         JOIN tiers ti ON ti.id = t.tier_id
         JOIN events e ON e.id = t.event_id
         WHERE t.id = :id'
    );
    $ticketStmt->execute(['id' => $ticketId]);
    $ticket = $ticketStmt->fetch();

    if (!$ticket) {
        return ['status' => 'error', 'message' => 'Ticket ID not found.', 'http_code' => 404];
    }

    $benefitStmt = $db->prepare(
        'SELECT * FROM benefits WHERE tier_id = :tier_id'
    );
    $benefitStmt->execute(['tier_id' => $ticket['tier_id']]);
    $benefits = $benefitStmt->fetchAll();

    $matchedBenefit = null;
    $normalizedStation = normalizeStationName($station);

    foreach ($benefits as $benefit) {
        if (normalizeStationName($benefit['name']) === $normalizedStation) {
            $matchedBenefit = $benefit;
            break;
        }
    }

    if ($matchedBenefit === null) {
        return [
            'status'  => 'error',
            'message' => 'Station not linked to this ticket.',
            'http_code' => 400,
        ];
    }

    $countStmt = $db->prepare(
        'SELECT COUNT(*) AS used FROM scans
         WHERE ticket_id = :ticket_id AND benefit_id = :benefit_id'
    );
    $countStmt->execute([
        'ticket_id'  => $ticketId,
        'benefit_id' => $matchedBenefit['id'],
    ]);
    $used = (int) $countStmt->fetchColumn();
    $max = (int) $matchedBenefit['max_uses'];

    $logsStmt = $db->prepare(
        'SELECT scanned_at FROM scans
         WHERE ticket_id = :ticket_id AND benefit_id = :benefit_id
         ORDER BY scanned_at ASC'
    );
    $logsStmt->execute([
        'ticket_id'  => $ticketId,
        'benefit_id' => $matchedBenefit['id'],
    ]);
    $logs = array_map(
        static fn (array $row): string => $row['scanned_at'],
        $logsStmt->fetchAll()
    );

    $distributorName = $ticket['allocated_distributor_name'] ?: 'Unassigned Stock';

    if ($used >= $max) {
        return [
            'status'    => 'limit_reached',
            'message'   => 'Limit reached.',
            'used'      => $used,
            'max'       => $max,
            'http_code' => 200,
            'ticket'    => [
                'id'          => $ticket['id'],
                'distributor' => $distributorName,
                'counts'      => [$station => $used],
                'limits'      => [$station => $max],
                'logs'        => [$station => $logs],
            ],
        ];
    }

    $insertStmt = $db->prepare(
        'INSERT INTO scans (ticket_id, benefit_id, scanned_at, station_type, stall_id)
         VALUES (:ticket_id, :benefit_id, NOW(), :station_type, :stall_id)'
    );
    $insertStmt->execute([
        'ticket_id'    => $ticketId,
        'benefit_id'   => $matchedBenefit['id'],
        'station_type' => trim($station),
        'stall_id'     => $stallId,
    ]);

    $used++;

    $stallNote = $stallId !== null ? " stall_id={$stallId}" : '';
    auditLog('SCAN', "Ticket {$ticketId} scanned at station {$station} ({$used}/{$max}){$stallNote}");

    $logs[] = date('Y-m-d H:i:s');

    return [
        'status'    => 'granted',
        'used'      => $used,
        'max'       => $max,
        'http_code' => 200,
        'ticket'    => [
            'id'          => $ticket['id'],
            'distributor' => $distributorName,
            'counts'      => [$station => $used],
            'limits'      => [$station => $max],
            'logs'        => [$station => $logs],
        ],
    ];
}

/**
 * Fetch full ticket details for API / validation views.
 *
 * @return array<string, mixed>|null
 */
function getTicketDetails(PDO $db, string $ticketId): ?array
{
    $ticketId = trim($ticketId);

    $stmt = $db->prepare(
        'SELECT t.*, ti.name AS tier_name, ti.price AS tier_price,
                e.name AS event_name, d.name AS distributor_company, d.email AS distributor_email,
                c.email AS customer_email, c.name AS customer_name
         FROM tickets t
         JOIN tiers ti ON ti.id = t.tier_id
         JOIN events e ON e.id = t.event_id
         LEFT JOIN distributors d ON d.id = t.allocated_distributor_id
         LEFT JOIN customers c ON c.id = t.customer_id
         WHERE t.id = :id'
    );
    $stmt->execute(['id' => $ticketId]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        return null;
    }

    $benefitsStmt = $db->prepare(
        'SELECT b.id, b.name, b.max_uses,
                COUNT(s.id) AS used,
                MAX(s.scanned_at) AS last_scan
         FROM benefits b
         LEFT JOIN scans s ON s.benefit_id = b.id AND s.ticket_id = :ticket_id
         WHERE b.tier_id = :tier_id
         GROUP BY b.id, b.name, b.max_uses
         ORDER BY b.id'
    );
    $benefitsStmt->execute([
        'ticket_id' => $ticketId,
        'tier_id'   => $ticket['tier_id'],
    ]);
    $benefits = $benefitsStmt->fetchAll();

    $scansStmt = $db->prepare(
        'SELECT s.scanned_at, s.station_type, b.name AS benefit_name
         FROM scans s
         JOIN benefits b ON b.id = s.benefit_id
         WHERE s.ticket_id = :ticket_id
         ORDER BY s.scanned_at DESC'
    );
    $scansStmt->execute(['ticket_id' => $ticketId]);
    $scans = $scansStmt->fetchAll();

    return [
        'ticket'   => $ticket,
        'benefits' => $benefits,
        'scans'    => $scans,
    ];
}

/**
 * Read-only ticket status payload for staff terminal / API (does not record scans).
 *
 * @return array<string, mixed>|null
 */
function buildTicketStatusPayload(PDO $db, string $ticketId): ?array
{
    $details = getTicketDetails($db, $ticketId);
    if ($details === null) {
        return null;
    }

    $ticket = $details['ticket'];
    $benefits = $details['benefits'];

    $totalMax = array_sum(array_column($benefits, 'max_uses'));
    $totalUsed = array_sum(array_column($benefits, 'used'));
    $isFullyUsed = $totalMax > 0 && $totalUsed >= $totalMax;
    $displayStatus = $isFullyUsed ? 'Used' : ($ticket['status'] ?? 'Active');

    if (!empty($ticket['customer_email'])) {
        $holderType = 'customer';
        $holderLabel = (string) ($ticket['customer_name'] ?: $ticket['customer_email']);
        $holderDetail = (string) $ticket['customer_email'];
    } elseif (!empty($ticket['allocated_distributor_name'])) {
        $holderType = 'distributor';
        $holderLabel = (string) $ticket['allocated_distributor_name'];
        $holderDetail = (string) ($ticket['distributor_company'] ?? $holderLabel);
    } elseif (!empty($ticket['distributor_company'])) {
        $holderType = 'distributor';
        $holderLabel = (string) $ticket['distributor_company'];
        $holderDetail = (string) ($ticket['distributor_email'] ?? '');
    } else {
        $holderType = 'vault';
        $holderLabel = 'Vault pool';
        $holderDetail = '';
    }

    $benefitRows = [];
    foreach ($benefits as $benefit) {
        $used = (int) $benefit['used'];
        $max = (int) $benefit['max_uses'];
        $benefitRows[] = [
            'name'      => trim((string) $benefit['name']),
            'used'      => $used,
            'max'       => $max,
            'remaining' => max(0, $max - $used),
            'last_scan' => $benefit['last_scan'] ?? null,
        ];
    }

    return [
        'ticket_id'       => (string) $ticket['id'],
        'event'           => (string) ($ticket['event_name'] ?? ''),
        'tier'            => (string) ($ticket['tier_name'] ?? ''),
        'physical_number' => (int) ($ticket['physical_number'] ?? 0),
        'status'          => $displayStatus,
        'holder_type'     => $holderType,
        'holder_label'    => $holderLabel,
        'holder_detail'   => $holderDetail,
        'benefits'        => $benefitRows,
    ];
}

/**
 * Distinct categories from benefits (for admin dropdowns). Falls back to "general".
 *
 * @return list<string>
 */
function getBenefitCategoryOptions(PDO $db): array
{
    ensureCategorySchema($db);

    $rows = $db->query(
        "SELECT DISTINCT LOWER(TRIM(category)) AS category
         FROM benefits
         WHERE TRIM(category) <> ''
         ORDER BY category ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $categories = array_values(array_filter(
        $rows,
        static fn ($cat): bool => $cat !== '' && $cat !== null
    ));

    return $categories !== [] ? $categories : ['general'];
}

/**
 * Distinct benefit/stall categories already in use (for analytics / legacy callers).
 *
 * @return list<string>
 */
function getDistinctBenefitCategories(PDO $db): array
{
    ensureCategorySchema($db);

    $rows = $db->query(
        "SELECT DISTINCT LOWER(TRIM(category)) AS category
         FROM (
             SELECT category FROM benefits WHERE TRIM(category) <> ''
             UNION
             SELECT category FROM stalls WHERE TRIM(category) <> ''
         ) AS categories
         ORDER BY category ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter($rows, static fn ($cat): bool => $cat !== '' && $cat !== null));
}

/**
 * Resolve category from a dropdown + optional custom text field (Add new).
 */
function resolveCategorySelection(string $selected, string $customFallback = ''): string
{
    $selected = trim($selected);
    if ($selected === '__new__') {
        return normalizeBenefitCategory(trim($customFallback));
    }

    return normalizeBenefitCategory($selected);
}

/**
 * Normalize a benefit/stall category slug (lowercase, trimmed).
 *
 * @throws InvalidArgumentException when required and empty, or format invalid
 */
function normalizeBenefitCategory(?string $category, bool $required = true): string
{
    $category = strtolower(trim((string) $category));
    // Allow plain typing: spaces become hyphens, other invalid chars stripped
    $category = preg_replace('/\s+/', '-', $category);
    $category = preg_replace('/[^a-z0-9_-]/', '', $category);
    $category = trim($category, '-_');

    if ($category === '') {
        if (!$required) {
            return '';
        }

        throw new InvalidArgumentException('Category is required.');
    }

    if (strlen($category) > 50) {
        throw new InvalidArgumentException('Category must be 50 characters or fewer.');
    }

    return $category;
}

/**
 * Add category columns to benefits and stalls (safe to run multiple times).
 */
function ensureCategorySchema(PDO $db): void
{
    $benefitCol = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'benefits' AND column_name = 'category'"
    );
    $benefitCol->execute();
    if ($benefitCol->fetch() === false) {
        $db->exec("ALTER TABLE benefits ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'general'");
    }

    $stallCol = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'stalls' AND column_name = 'category'"
    );
    $stallCol->execute();
    if ($stallCol->fetch() === false) {
        $db->exec("ALTER TABLE stalls ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'general'");
    }

    // Backfill legacy empty categories
    $db->exec("UPDATE benefits SET category = 'general' WHERE TRIM(category) = ''");
    $db->exec("UPDATE stalls SET category = 'general' WHERE TRIM(category) = ''");
    $db->exec("ALTER TABLE benefits ALTER COLUMN category SET DEFAULT 'general'");
    $db->exec("ALTER TABLE stalls ALTER COLUMN category SET DEFAULT 'general'");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_benefits_category ON benefits (category)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_stalls_category ON stalls (category)');
}

/**
 * Scan counts grouped by benefit category for an event.
 *
 * @return list<array{category: string, scan_count: int}>
 */
function getScansByCategory(PDO $db, int $eventId): array
{
    ensureCategorySchema($db);

    $stmt = $db->prepare(
        "SELECT COALESCE(NULLIF(LOWER(TRIM(b.category)), ''), 'general') AS category,
                COUNT(*) AS scan_count
         FROM scans s
         JOIN benefits b ON b.id = s.benefit_id
         JOIN tickets t ON t.id = s.ticket_id
         WHERE t.event_id = :event_id
         GROUP BY COALESCE(NULLIF(LOWER(TRIM(b.category)), ''), 'general')
         ORDER BY scan_count DESC, category ASC"
    );
    $stmt->execute(['event_id' => $eventId]);

    return array_map(
        static fn (array $row): array => [
            'category'   => (string) $row['category'],
            'scan_count' => (int) $row['scan_count'],
        ],
        $stmt->fetchAll()
    );
}

/**
 * Benefits configured for the tier of a ticket (terminal dropdown).
 *
 * @param string|null $stallCategory When set, only benefits in this category are returned.
 * @return array<string, mixed>|null null when ticket not found
 */
function getBenefitsForTicket(PDO $db, string $ticketId, ?string $stallCategory = null): ?array
{
    ensureCategorySchema($db);

    $ticketId = trim($ticketId);
    if (preg_match('/^\d+$/', $ticketId)) {
        $ticketId = str_pad($ticketId, 6, '0', STR_PAD_LEFT);
    }

    $stmt = $db->prepare(
        'SELECT t.id AS ticket_id, t.tier_id, ti.name AS tier_name, e.name AS event_name
         FROM tickets t
         JOIN tiers ti ON ti.id = t.tier_id
         JOIN events e ON e.id = t.event_id
         WHERE t.id = :id'
    );
    $stmt->execute(['id' => $ticketId]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        return null;
    }

    $stallCategory = $stallCategory !== null ? normalizeBenefitCategory($stallCategory, false) : null;

    if ($stallCategory !== null && $stallCategory !== '') {
        $benefitsStmt = $db->prepare(
            'SELECT b.id, TRIM(b.name) AS name, LOWER(TRIM(b.category)) AS category
             FROM benefits b
             WHERE b.tier_id = :tier_id AND LOWER(TRIM(b.category)) = :category
             ORDER BY b.id'
        );
        $benefitsStmt->execute([
            'tier_id'  => (int) $ticket['tier_id'],
            'category' => $stallCategory,
        ]);
    } else {
        $benefitsStmt = $db->prepare(
            'SELECT b.id, TRIM(b.name) AS name, LOWER(TRIM(b.category)) AS category
             FROM benefits b
             WHERE b.tier_id = :tier_id
             ORDER BY b.id'
        );
        $benefitsStmt->execute(['tier_id' => (int) $ticket['tier_id']]);
    }

    $benefits = $benefitsStmt->fetchAll();

    return [
        'ticket_id'      => (string) $ticket['ticket_id'],
        'tier_id'        => (int) $ticket['tier_id'],
        'tier_name'      => (string) $ticket['tier_name'],
        'event_name'     => (string) $ticket['event_name'],
        'stall_category' => $stallCategory ?? '',
        'benefits'       => array_map(
            static fn (array $row): array => [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'category' => (string) ($row['category'] ?? ''),
            ],
            $benefits
        ),
    ];
}

/**
 * Return distinct benefit names for an event's tiers (station dropdown).
 *
 * @return list<string>
 */
function getDistinctBenefitNames(PDO $db, ?int $eventId = null): array
{
    if ($eventId !== null) {
        $stmt = $db->prepare(
            'SELECT DISTINCT TRIM(b.name) AS name
             FROM benefits b
             JOIN tiers ti ON ti.id = b.tier_id
             WHERE ti.event_id = :event_id
             ORDER BY name'
        );
        $stmt->execute(['event_id' => $eventId]);
    } else {
        $stmt = $db->query('SELECT DISTINCT TRIM(name) AS name FROM benefits ORDER BY name');
    }

    $rows = $stmt->fetchAll();

    return array_values(array_filter(array_map(static fn (array $r): string => $r['name'], $rows)));
}

/**
 * Return an event name by id, or the latest event name.
 */
function getCurrentEventName(PDO $db, ?int $eventId = null): string
{
    if ($eventId !== null) {
        $stmt = $db->prepare('SELECT name FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : 'PassGate';
    }

    $stmt = $db->query('SELECT name FROM events ORDER BY id DESC LIMIT 1');
    $name = $stmt->fetchColumn();

    return $name !== false ? (string) $name : 'PassGate';
}

/**
 * Whether any events exist in the database.
 */
function hasAnyEvents(PDO $db): bool
{
    return (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn() > 0;
}

/**
 * Check whether any tickets exist (legacy helper).
 */
function hasActiveRegistry(PDO $db): bool
{
    return (int) $db->query('SELECT COUNT(*) FROM tickets')->fetchColumn() > 0;
}

/**
 * Fetch a single event row or null.
 *
 * @return array<string, mixed>|null
 */
function getEventById(PDO $db, int $eventId): ?array
{
    $stmt = $db->prepare('SELECT * FROM events WHERE id = :id');
    $stmt->execute(['id' => $eventId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Resolve the admin-selected event id from query string, session, or latest event.
 */
function resolveAdminEventId(PDO $db): int
{
    if (isset($_GET['event_id']) && ctype_digit((string) $_GET['event_id'])) {
        $candidate = (int) $_GET['event_id'];
        if (getEventById($db, $candidate) !== null) {
            $_SESSION['selected_event_id'] = $candidate;
            $_SESSION['terminal_event_id'] = $candidate;

            return $candidate;
        }
    }

    if (!empty($_SESSION['selected_event_id'])) {
        $candidate = (int) $_SESSION['selected_event_id'];
        if (getEventById($db, $candidate) !== null) {
            return $candidate;
        }
    }

    $latest = (int) $db->query('SELECT id FROM events ORDER BY id DESC LIMIT 1')->fetchColumn();
    $_SESSION['selected_event_id'] = $latest;

    return $latest;
}

/**
 * Resolve which event the scanning terminal should use.
 */
function resolveTerminalEventId(PDO $db): ?int
{
    if (!empty($_SESSION['terminal_event_id'])) {
        $candidate = (int) $_SESSION['terminal_event_id'];
        if (getEventById($db, $candidate) !== null) {
            return $candidate;
        }
    }

    if (isset($_GET['event_id']) && ctype_digit((string) $_GET['event_id'])) {
        $candidate = (int) $_GET['event_id'];
        if (getEventById($db, $candidate) !== null) {
            return $candidate;
        }
    }

    $latest = $db->query('SELECT id FROM events ORDER BY id DESC LIMIT 1')->fetchColumn();

    return $latest !== false ? (int) $latest : null;
}

/**
 * Build a query string preserving event context for admin pages.
 */
function adminEventQuery(int $eventId, string $tab = ''): string
{
    $params = ['event_id' => $eventId];
    if ($tab !== '') {
        $params['tab'] = $tab;
    }

    return http_build_query($params);
}

/**
 * List all events with ticket and scan counts for the admin Events tab.
 *
 * @return list<array<string, mixed>>
 */
function getAllEventsWithStats(PDO $db): array
{
    $stmt = $db->query(
        'SELECT e.id, e.name, e.created_at,
                COUNT(DISTINCT t.id) AS ticket_count,
                COUNT(DISTINCT t.id) FILTER (
                    WHERE t.customer_id IS NULL
                      AND (t.allocated_distributor_id IS NULL OR t.allocated_distributor_id = \'\')
                ) AS vault_available,
                COUNT(DISTINCT t.id) FILTER (WHERE t.customer_id IS NOT NULL) AS sold_online,
                COUNT(DISTINCT s.id) AS scan_count
         FROM events e
         LEFT JOIN tickets t ON t.event_id = e.id
         LEFT JOIN scans s ON s.ticket_id = t.id
         GROUP BY e.id, e.name, e.created_at
         ORDER BY e.created_at DESC'
    );

    return $stmt->fetchAll();
}

/**
 * Ticket inventory breakdown for an event (admin dashboard).
 *
 * @return array{total: int, vault_available: int, allocated: int, sold_online: int}
 */
function getEventTicketSummary(PDO $db, int $eventId): array
{
    ensureCustomerSchema($db);

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (
                WHERE customer_id IS NULL
                  AND (allocated_distributor_id IS NULL OR allocated_distributor_id = '')
            ) AS vault_available,
            COUNT(*) FILTER (
                WHERE customer_id IS NULL
                  AND allocated_distributor_id IS NOT NULL AND allocated_distributor_id <> ''
            ) AS allocated,
            COUNT(*) FILTER (WHERE customer_id IS NOT NULL) AS sold_online
         FROM tickets WHERE event_id = :event_id"
    );
    $stmt->execute(['event_id' => $eventId]);
    $row = $stmt->fetch() ?: [];

    return [
        'total'           => (int) ($row['total'] ?? 0),
        'vault_available' => (int) ($row['vault_available'] ?? 0),
        'allocated'       => (int) ($row['allocated'] ?? 0),
        'sold_online'     => (int) ($row['sold_online'] ?? 0),
    ];
}

/**
 * Generate a globally unique ticket id for an event tier.
 */
function generateTicketId(int $eventId, string $eventName, string $tierName, int $physicalNumber): string
{
    $eventRaw = preg_replace('/[^A-Za-z0-9]/', '', $eventName ?: 'EVN');
    $eventPrefix = strtoupper(substr($eventRaw, 0, 3));
    if (strlen($eventPrefix) < 3) {
        $eventPrefix = str_pad($eventPrefix, 3, 'X');
    }

    $tierRaw = preg_replace('/[^A-Za-z0-9]/', '', $tierName);
    $tierPrefix = strtoupper(substr($tierRaw, 0, 3));
    if (strlen($tierPrefix) < 3) {
        $tierPrefix = str_pad($tierPrefix, 3, 'X');
    }

    return "E{$eventId}-{$eventPrefix}-{$tierPrefix}-{$physicalNumber}";
}

/**
 * Create an event with tiers, benefits, and tickets from setup wizard data.
 *
 * @param list<array{name: string, qty: int, price: float, benefits: list<array{name: string, max: int}>}> $tiers
 */
function createEventWithTiers(PDO $db, string $eventName, array $tiers): int
{
    $eventStmt = $db->prepare('INSERT INTO events (name) VALUES (:name) RETURNING id');
    $eventStmt->execute(['name' => $eventName]);
    $eventId = (int) $eventStmt->fetchColumn();

    $tierInsert = $db->prepare(
        'INSERT INTO tiers (event_id, name, price, quantity) VALUES (:event_id, :name, :price, :quantity) RETURNING id'
    );
    $benefitInsert = $db->prepare(
        'INSERT INTO benefits (tier_id, name, max_uses, category) VALUES (:tier_id, :name, :max_uses, :category)'
    );
    $ticketInsert = $db->prepare(
        'INSERT INTO tickets (id, physical_number, event_id, tier_id, status, allocated_distributor_name)
         VALUES (:id, :physical_number, :event_id, :tier_id, :status, :allocated_distributor_name)'
    );

    foreach ($tiers as $tier) {
        $tierInsert->execute([
            'event_id' => $eventId,
            'name'     => $tier['name'],
            'price'    => $tier['price'],
            'quantity' => $tier['qty'],
        ]);
        $tierId = (int) $tierInsert->fetchColumn();

        foreach ($tier['benefits'] as $benefit) {
            $benefitInsert->execute([
                'tier_id'  => $tierId,
                'name'     => $benefit['name'],
                'max_uses' => max(1, $benefit['max']),
                'category' => normalizeBenefitCategory($benefit['category'] ?? ''),
            ]);
        }

        for ($physicalId = 1; $physicalId <= $tier['qty']; $physicalId++) {
            $ticketId = generateTicketId($eventId, $eventName, $tier['name'], $physicalId);
            $ticketInsert->execute([
                'id'                         => $ticketId,
                'physical_number'            => $physicalId,
                'event_id'                   => $eventId,
                'tier_id'                    => $tierId,
                'status'                     => 'Active',
                'allocated_distributor_name' => 'Vault Pool',
            ]);
        }
    }

    return $eventId;
}

/**
 * Fetch tickets for an event grouped by tier name.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function getTicketsGroupedByTier(PDO $db, int $eventId, ?int $tierId = null): array
{
    $sql = 'SELECT t.id, t.physical_number, t.status, ti.name AS tier_name, ti.id AS tier_id, ti.price
            FROM tickets t
            JOIN tiers ti ON ti.id = t.tier_id
            WHERE t.event_id = :event_id';
    $params = ['event_id' => $eventId];

    if ($tierId !== null) {
        $sql .= ' AND ti.id = :tier_id';
        $params['tier_id'] = $tierId;
    }

    $sql .= ' ORDER BY ti.name, t.physical_number';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['tier_name']][] = $row;
    }

    return $grouped;
}

/**
 * List tiers for an event.
 *
 * @return list<array<string, mixed>>
 */
function getEventTiers(PDO $db, int $eventId): array
{
    $stmt = $db->prepare(
        'SELECT id, name, price, quantity FROM tiers WHERE event_id = :event_id ORDER BY name'
    );
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll();
}

/**
 * Parse email and token from a pasted magic-link URL or raw 64-char hex token.
 *
 * @return array{email: string, token: string}
 */
function parseMagicLinkInput(string $input): array
{
    $input = trim($input);

    if ($input === '') {
        return ['email' => '', 'token' => ''];
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $input)) {
        return ['email' => '', 'token' => $input];
    }

    $query = parse_url($input, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return ['email' => '', 'token' => ''];
    }

    parse_str($query, $params);

    return [
        'email' => strtolower(trim((string) ($params['email'] ?? ''))),
        'token' => trim((string) ($params['token'] ?? '')),
    ];
}

/**
 * Verify a magic-link token, consume it, and establish a distributor session.
 *
 * @return array{success: bool, error: string|null, redirect: string|null}
 */
function completeMagicLinkLogin(PDO $db, string $email, string $token): array
{
    $email = strtolower(trim($email));
    $token = trim($token);

    if ($email === '' || $token === '') {
        auditLog('AUTH', 'Login failed: missing email or token');

        return [
            'success'  => false,
            'error'    => 'Invalid or expired token. Please request a new login link.',
            'redirect' => null,
        ];
    }

    $stmt = $db->prepare(
        'SELECT lt.id AS token_id, d.id, d.email, d.role
         FROM login_tokens lt
         JOIN distributors d ON d.id = lt.distributor_id
         WHERE lt.token = :token
           AND LOWER(d.email) = :email
           AND lt.expires_at > NOW()'
    );
    $stmt->execute(['token' => $token, 'email' => $email]);
    $record = $stmt->fetch();

    if ($record) {
        session_regenerate_id(true);
        clearCustomerSession();

        $_SESSION['distributor_authenticated'] = true;
        $_SESSION['distributor_email'] = $record['email'];
        $_SESSION['distributor_role'] = $record['role'];
        $_SESSION['distributor_id'] = $record['id'];

        $delete = $db->prepare('DELETE FROM login_tokens WHERE id = :id');
        $delete->execute(['id' => $record['token_id']]);

        auditLog('AUTH', "Successful login for {$email} (role: {$record['role']})");

        return [
            'success'  => true,
            'error'    => null,
            'redirect' => $record['role'] === 'admin' ? 'distributors.php' : 'terminal.php',
        ];
    }

    $expiredStmt = $db->prepare(
        'SELECT lt.id AS token_id
         FROM login_tokens lt
         JOIN distributors d ON d.id = lt.distributor_id
         WHERE lt.token = :token AND LOWER(d.email) = :email'
    );
    $expiredStmt->execute(['token' => $token, 'email' => $email]);
    $expiredRecord = $expiredStmt->fetch();

    if ($expiredRecord) {
        $delete = $db->prepare('DELETE FROM login_tokens WHERE id = :id');
        $delete->execute(['id' => $expiredRecord['token_id']]);
        auditLog('AUTH', "Expired token used for {$email}");

        return [
            'success'  => false,
            'error'    => 'This login link has expired. Please request a new one from the home page.',
            'redirect' => null,
        ];
    }

    auditLog('AUTH', "Failed login attempt for {$email}");

    return [
        'success'  => false,
        'error'    => 'Invalid or expired token. Please request a new login link.',
        'redirect' => null,
    ];
}

/**
 * Whether a distributor/admin session is active.
 */
function isDistributorAuthenticated(): bool
{
    return isset($_SESSION['distributor_authenticated'])
        && $_SESSION['distributor_authenticated'] === true;
}

/**
 * Whether a stall terminal session is active.
 */
function isStallAuthenticated(): bool
{
    return isset($_SESSION['stall_authenticated'])
        && $_SESSION['stall_authenticated'] === true
        && isset($_SESSION['stall_id']);
}

/**
 * Apply stalls table + scans.stall_id for existing databases.
 */
function ensureStallsSchema(PDO $db): void
{
    ensureCategorySchema($db);

    $db->exec(
        'CREATE TABLE IF NOT EXISTS stalls (
            id            SERIAL PRIMARY KEY,
            name          VARCHAR(255) NOT NULL,
            email         VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            category      VARCHAR(50) NOT NULL DEFAULT \'general\',
            created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    // Allow duplicate emails (same operator, multiple stalls) — drop legacy UNIQUE if present
    $db->exec('ALTER TABLE stalls DROP CONSTRAINT IF EXISTS stalls_email_key');

    $colCheck = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'scans' AND column_name = 'stall_id'"
    );
    $colCheck->execute();

    if ($colCheck->fetch() === false) {
        $db->exec(
            'ALTER TABLE scans ADD COLUMN stall_id INTEGER REFERENCES stalls(id) ON DELETE SET NULL'
        );
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_scans_stall_id ON scans (stall_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_stalls_email ON stalls (email)');
}

/**
 * Fetch all stall rows sharing an email (case-insensitive).
 *
 * @return array<int, array<string, mixed>>
 */
function getStallsByEmail(PDO $db, string $email): array
{
    $stmt = $db->prepare('SELECT id, name, email, password_hash, category FROM stalls WHERE LOWER(email) = :email');
    $stmt->execute(['email' => strtolower(trim($email))]);

    return $stmt->fetchAll();
}

/**
 * Ensure password differs from other stalls with the same email (login uses email + password).
 *
 * @throws InvalidArgumentException
 */
function assertUniqueStallPasswordForEmail(PDO $db, string $email, string $password, ?int $excludeStallId = null): void
{
    foreach (getStallsByEmail($db, $email) as $stall) {
        if ($excludeStallId !== null && (int) $stall['id'] === $excludeStallId) {
            continue;
        }

        if (password_verify($password, $stall['password_hash'])) {
            throw new InvalidArgumentException(
                'Another stall with this email already uses this password. Each stall needs a unique password.'
            );
        }
    }
}

/**
 * Authenticate a stall by email/password. Returns stall row or null.
 *
 * @return array<string, mixed>|null
 */
function authenticateStall(PDO $db, string $email, string $password): ?array
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return null;
    }

    $matches = [];
    foreach (getStallsByEmail($db, $email) as $stall) {
        if (password_verify($password, $stall['password_hash'])) {
            $matches[] = $stall;
        }
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    if (count($matches) > 1) {
        auditLog('AUTH', "Ambiguous stall login for {$email} (duplicate password across stalls)");
    }

    return null;
}

/**
 * Establish a stall session after successful authentication.
 *
 * @param array<string, mixed> $stall
 */
function establishStallSession(array $stall): void
{
    session_regenerate_id(true);
    clearCustomerSession();
    $_SESSION['stall_authenticated'] = true;
    $_SESSION['stall_id'] = (int) $stall['id'];
    $_SESSION['stall_name'] = (string) $stall['name'];
    $_SESSION['stall_email'] = (string) $stall['email'];
    $_SESSION['stall_category'] = normalizeBenefitCategory((string) ($stall['category'] ?? 'general'), false);
    if ($_SESSION['stall_category'] === '') {
        $_SESSION['stall_category'] = 'general';
    }
    unset($_SESSION['station_pin_unlocked'], $_SESSION['pin_station_type']);
}

/**
 * Resolve stall category for benefit filtering (null = show all benefits for tier).
 */
function resolveStallCategoryFilter(): ?string
{
    $fromQuery = trim((string) ($_GET['stall_category'] ?? ''));
    if ($fromQuery !== '') {
        return normalizeBenefitCategory($fromQuery, false) ?: null;
    }

    if (isStallAuthenticated()) {
        $fromSession = trim((string) ($_SESSION['stall_category'] ?? ''));
        if ($fromSession !== '') {
            return normalizeBenefitCategory($fromSession, false) ?: null;
        }
    }

    return null;
}

/**
 * Clear stall / PIN terminal session keys (keeps distributor session intact).
 */
function clearTerminalSession(): void
{
    unset(
        $_SESSION['stall_authenticated'],
        $_SESSION['stall_id'],
        $_SESSION['stall_name'],
        $_SESSION['stall_email'],
        $_SESSION['stall_category'],
        $_SESSION['station_pin_unlocked'],
        $_SESSION['pin_station_type']
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAllStalls(PDO $db): array
{
    ensureStallsSchema($db);

    return $db->query('SELECT id, name, email, category, created_at FROM stalls ORDER BY name ASC')->fetchAll();
}

/**
 * Create a new stall account.
 */
function createStall(PDO $db, string $name, string $email, string $password, string $category = ''): int
{
    ensureStallsSchema($db);

    $name = trim($name);
    $email = strtolower(trim($email));
    $category = normalizeBenefitCategory($category);

    if ($name === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Name, email, and password are required.');
    }

    if ($category === '') {
        throw new InvalidArgumentException('Stall category is required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email address.');
    }

    assertUniqueStallPasswordForEmail($db, $email, $password);

    $stmt = $db->prepare(
        'INSERT INTO stalls (name, email, password_hash, category) VALUES (:name, :email, :password_hash, :category) RETURNING id'
    );
    $stmt->execute([
        'name'          => $name,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'category'      => $category,
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * Update a stall password hash.
 */
function resetStallPassword(PDO $db, int $stallId, string $password): void
{
    if ($password === '') {
        throw new InvalidArgumentException('Password is required.');
    }

    $stallStmt = $db->prepare('SELECT id, email FROM stalls WHERE id = :id');
    $stallStmt->execute(['id' => $stallId]);
    $stall = $stallStmt->fetch();

    if (!$stall) {
        throw new InvalidArgumentException('Stall not found.');
    }

    assertUniqueStallPasswordForEmail($db, (string) $stall['email'], $password, $stallId);

    $stmt = $db->prepare('UPDATE stalls SET password_hash = :hash WHERE id = :id');
    $stmt->execute([
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'id'   => $stallId,
    ]);
}

/**
 * Delete a stall (scan rows keep history with stall_id set to NULL).
 */
function deleteStall(PDO $db, int $stallId): void
{
    $stmt = $db->prepare('DELETE FROM stalls WHERE id = :id');
    $stmt->execute(['id' => $stallId]);

    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Stall not found.');
    }
}

/**
 * Resolve scan context from the current session for api.php.
 *
 * @return array{allowed: bool, station: string, stall_id: ?int, message?: string}
 */
function resolveScanAuthContext(string $requestedStation): array
{
    if (isStallAuthenticated()) {
        $station = trim($requestedStation);
        if ($station === '') {
            return [
                'allowed'  => false,
                'station'  => '',
                'stall_id' => null,
                'message'  => 'Select a benefit before scanning.',
            ];
        }

        return [
            'allowed'  => true,
            'station'  => $station,
            'stall_id' => (int) $_SESSION['stall_id'],
        ];
    }

    if (isDistributorAuthenticated()) {
        $station = trim($requestedStation);
        if ($station === '') {
            return [
                'allowed' => false,
                'station' => '',
                'stall_id' => null,
                'message' => 'station is required.',
            ];
        }

        return [
            'allowed'  => true,
            'station'  => $station,
            'stall_id' => null,
        ];
    }

    return [
        'allowed'  => false,
        'station'  => '',
        'stall_id' => null,
        'message'  => 'Stall login required to scan.',
    ];
}

/**
 * Redirect authenticated users away from login pages (same-tab, no duplicate flows).
 */
function redirectIfAuthenticated(): void
{
    if (!isDistributorAuthenticated()) {
        return;
    }

    $role = $_SESSION['distributor_role'] ?? '';

    if ($role === 'admin') {
        safeRedirect('distributors.php');
    }

    safeRedirect('terminal.php');
}

/**
 * Issue an HTTP redirect that replaces history (303) to avoid duplicate POST/back-tab issues.
 */
function safeRedirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

/**
 * PHP's built-in server handles one request at a time; synchronous SMTP freezes all tabs.
 */
function dispatchMagicLinkEmail(string $email, string $token): void
{
    // Dev/local: write link synchronously (also avoids Unix-only backgrounding on Windows).
    require_once __DIR__ . '/mailer.php';
    if (shouldUseDevMailFallback()) {
        sendMagicLinkEmail($email, $token);
        return;
    }

    $phpBinary = PHP_BINARY;
    $script = __DIR__ . '/../send_mail_async.php';

    if (PHP_OS_FAMILY === 'Windows') {
        $command = sprintf(
            'start /B "" %s %s %s %s',
            escapeshellarg($phpBinary),
            escapeshellarg($script),
            escapeshellarg($email),
            escapeshellarg($token)
        );
        pclose(popen($command, 'r'));
        return;
    }

    $command = sprintf(
        '%s %s %s %s > /dev/null 2>&1 &',
        escapeshellarg($phpBinary),
        escapeshellarg($script),
        escapeshellarg($email),
        escapeshellarg($token)
    );

    exec($command);
}

/**
 *
 * Local: when MAIL_HOST is unset or APP_DEBUG=1, writes link to dev_login.log.
 * Production: sends via SMTP only; never logs the token.
 */
function sendMagicLinkEmail(string $email, string $token): bool
{
    require_once __DIR__ . '/mailer.php';

    $appUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
    $link = $appUrl . '/login.php?token=' . urlencode($token) . '&email=' . urlencode($email);
    $manualUrl = $appUrl . '/manual_login.php';

    $subject = 'Your PassGate Login Link';
    $textBody = "Click the link below to sign in. This link expires in 1 hour.\n\n{$link}\n\n"
        . "If the link doesn't open, go to {$manualUrl} and paste this token:\n{$token}\n";
    $htmlBody = '<p>Click the link below to sign in. This link expires in 1 hour.</p>'
        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Sign in to PassGate</a></p>'
        . '<p>Or copy this URL:<br><code>' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</code></p>'
        . '<p>If the link doesn&rsquo;t work, go to '
        . '<a href="' . htmlspecialchars($manualUrl, ENT_QUOTES, 'UTF-8') . '">manual login</a>'
        . ' and paste your token:<br><code style="word-break:break-all;">'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '</code></p>';

    if (shouldUseDevMailFallback()) {
        writeDevLoginLink($email, $link);

        return true;
    }

    $result = sendSmtpEmail($email, $subject, $textBody, $htmlBody);

    if (!$result['success']) {
        auditLog('MAIL', "SMTP send failed for {$email}: {$result['error']}");
    }

    return $result['success'];
}

// ---------------------------------------------------------------------------
// Customer accounts & online ticket sales
// ---------------------------------------------------------------------------

/**
 * Apply customers / customer_tickets tables and ticket purchase columns.
 */
function ensureCustomerSchema(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS customers (
            id            SERIAL PRIMARY KEY,
            email         VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            name          VARCHAR(255) DEFAULT \'\',
            created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $colCheck = $db->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'tickets'
           AND column_name IN ('customer_id', 'purchased_at')"
    );
    $colCheck->execute();
    $cols = $colCheck->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('customer_id', $cols, true)) {
        $db->exec(
            'ALTER TABLE tickets ADD COLUMN customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL'
        );
    }
    if (!in_array('purchased_at', $cols, true)) {
        $db->exec('ALTER TABLE tickets ADD COLUMN purchased_at TIMESTAMPTZ');
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS customer_tickets (
            id           SERIAL PRIMARY KEY,
            customer_id  INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
            ticket_id    VARCHAR(100) NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
            purchased_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payment_id   VARCHAR(255) DEFAULT \'\',
            payment_gateway VARCHAR(50) DEFAULT \'\',
            payment_reference VARCHAR(255) DEFAULT \'\',
            UNIQUE (ticket_id)
        )'
    );

    $ctColCheck = $db->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'customer_tickets'
           AND column_name IN ('payment_gateway', 'payment_reference')"
    );
    $ctColCheck->execute();
    $ctCols = $ctColCheck->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_gateway', $ctCols, true)) {
        $db->exec('ALTER TABLE customer_tickets ADD COLUMN payment_gateway VARCHAR(50) DEFAULT \'\'');
    }
    if (!in_array('payment_reference', $ctCols, true)) {
        $db->exec('ALTER TABLE customer_tickets ADD COLUMN payment_reference VARCHAR(255) DEFAULT \'\'');
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS payment_pending (
            id               SERIAL PRIMARY KEY,
            transaction_uuid VARCHAR(100) NOT NULL UNIQUE,
            event_id         INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
            tier_id          INTEGER NOT NULL REFERENCES tiers(id) ON DELETE CASCADE,
            email            VARCHAR(255) NOT NULL,
            gateway          VARCHAR(20) NOT NULL DEFAULT \'esewa\',
            amount           DECIMAL(10, 2) NOT NULL,
            quantity         INTEGER NOT NULL DEFAULT 1,
            created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $pendingColCheck = $db->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'payment_pending'
           AND column_name = 'quantity'"
    );
    $pendingColCheck->execute();
    if ($pendingColCheck->fetchColumn() === false) {
        $db->exec('ALTER TABLE payment_pending ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1');
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_tickets_customer_id ON tickets (customer_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_customers_email ON customers (email)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_tickets_customer ON customer_tickets (customer_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_customer_tickets_ticket ON customer_tickets (ticket_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_payment_pending_uuid ON payment_pending (transaction_uuid)');
}

function isCustomerAuthenticated(): bool
{
    return isset($_SESSION['customer_authenticated'])
        && $_SESSION['customer_authenticated'] === true
        && isset($_SESSION['customer_id']);
}

function requireCustomerAuth(?string $next = null): void
{
    if (!isCustomerAuthenticated()) {
        $target = 'customer_login.php';
        if ($next !== null && $next !== '') {
            $target .= '?next=' . urlencode($next);
        }
        safeRedirect($target);
    }
}

/**
 * Load the logged-in customer from the database and verify the session is still valid.
 *
 * @return array<string, mixed>|null
 */
function getAuthenticatedCustomer(PDO $db): ?array
{
    if (!isCustomerAuthenticated()) {
        return null;
    }

    ensureCustomerSchema($db);

    $customerId = (int) $_SESSION['customer_id'];
    if ($customerId <= 0) {
        clearCustomerSession();

        return null;
    }

    $stmt = $db->prepare('SELECT id, email, name FROM customers WHERE id = :id');
    $stmt->execute(['id' => $customerId]);
    $customer = $stmt->fetch();

    if ($customer === false) {
        clearCustomerSession();

        return null;
    }

    $sessionEmail = strtolower(trim((string) ($_SESSION['customer_email'] ?? '')));
    $dbEmail = strtolower(trim((string) $customer['email']));
    if ($sessionEmail !== '' && $sessionEmail !== $dbEmail) {
        auditLog('AUTH', "Customer session email mismatch for id {$customerId}");
        clearCustomerSession();

        return null;
    }

    return $customer;
}

function customerOwnsTicket(PDO $db, int $customerId, string $ticketId): bool
{
    ensureCustomerSchema($db);
    $ticketId = trim($ticketId);
    if ($ticketId === '' || $customerId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        'SELECT 1 FROM customer_tickets
         WHERE customer_id = :customer_id AND ticket_id = :ticket_id'
    );
    $stmt->execute(['customer_id' => $customerId, 'ticket_id' => $ticketId]);

    return $stmt->fetch() !== false;
}

/**
 * Require a valid customer session backed by the database.
 *
 * @return array<string, mixed>
 */
function requireCustomerAuthValidated(PDO $db, ?string $next = null): array
{
    requireCustomerAuth($next);

    $customer = getAuthenticatedCustomer($db);
    if ($customer === null) {
        $target = 'customer_login.php?next=' . urlencode($next ?? 'customer_dashboard.php');
        safeRedirect($target);
    }

    return $customer;
}

/**
 * @return array<string, mixed>|null
 */
function getCustomerByEmail(PDO $db, string $email): ?array
{
    ensureCustomerSchema($db);
    $stmt = $db->prepare('SELECT * FROM customers WHERE LOWER(email) = :email');
    $stmt->execute(['email' => strtolower(trim($email))]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Register or claim a guest account created during checkout (updates password).
 *
 * @throws InvalidArgumentException
 */
function registerCustomer(PDO $db, string $name, string $email, string $password): int
{
    ensureCustomerSchema($db);

    $name = trim($name);
    $email = strtolower(trim($email));

    if ($name === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Name, email, and password are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email address.');
    }

    $existing = getCustomerByEmail($db, $email);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing !== null) {
        // Guest checkout may have created a stub customer — allow setting a password.
        $stmt = $db->prepare(
            'UPDATE customers SET name = :name, password_hash = :hash, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'hash' => $hash, 'id' => $existing['id']]);

        return (int) $existing['id'];
    }

    $stmt = $db->prepare(
        'INSERT INTO customers (name, email, password_hash) VALUES (:name, :email, :hash) RETURNING id'
    );
    $stmt->execute(['name' => $name, 'email' => $email, 'hash' => $hash]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<string, mixed>|null
 */
function authenticateCustomer(PDO $db, string $email, string $password): ?array
{
    $customer = getCustomerByEmail($db, $email);
    if ($customer === null || !password_verify($password, $customer['password_hash'])) {
        return null;
    }

    return $customer;
}

function establishCustomerSession(array $customer): void
{
    session_regenerate_id(true);
    clearTerminalSession();
    clearDistributorSession();
    clearCustomerSession();
    $_SESSION['customer_authenticated'] = true;
    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_email'] = (string) $customer['email'];
    $_SESSION['customer_name'] = (string) ($customer['name'] ?? '');
}

function clearCustomerSession(): void
{
    unset(
        $_SESSION['customer_authenticated'],
        $_SESSION['customer_id'],
        $_SESSION['customer_email'],
        $_SESSION['customer_name']
    );
}

function clearDistributorSession(): void
{
    unset(
        $_SESSION['distributor_authenticated'],
        $_SESSION['distributor_email'],
        $_SESSION['distributor_role'],
        $_SESSION['distributor_id']
    );
}

/**
 * Tickets available for public online purchase (vault pool, not yet sold).
 */
function countAvailableTicketsForTier(PDO $db, int $tierId, int $eventId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM tickets
         WHERE tier_id = :tier_id AND event_id = :event_id
           AND customer_id IS NULL AND status = 'Active'
           AND (allocated_distributor_id IS NULL OR allocated_distributor_id = '')"
    );
    $stmt->execute(['tier_id' => $tierId, 'event_id' => $eventId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Events with at least one purchasable ticket for the public buy page.
 *
 * @return array<int, array<string, mixed>>
 */
function getEventsAvailableForPurchase(PDO $db): array
{
    ensureCustomerSchema($db);

    $eventsStmt = $db->query(
        "SELECT DISTINCT e.id, e.name, e.created_at
         FROM events e
         JOIN tickets t ON t.event_id = e.id
         WHERE t.customer_id IS NULL AND t.status = 'Active'
           AND (t.allocated_distributor_id IS NULL OR t.allocated_distributor_id = '')
         ORDER BY e.created_at DESC"
    );

    $events = [];
    foreach ($eventsStmt->fetchAll() as $event) {
        $eventId = (int) $event['id'];
        $tiersStmt = $db->prepare(
            'SELECT ti.* FROM tiers ti WHERE ti.event_id = :event_id ORDER BY ti.price ASC'
        );
        $tiersStmt->execute(['event_id' => $eventId]);

        $tiers = [];
        foreach ($tiersStmt->fetchAll() as $tier) {
            $tierId = (int) $tier['id'];
            $available = countAvailableTicketsForTier($db, $tierId, $eventId);
            if ($available <= 0) {
                continue;
            }

            $benefitsStmt = $db->prepare('SELECT name, max_uses FROM benefits WHERE tier_id = :tier_id ORDER BY name');
            $benefitsStmt->execute(['tier_id' => $tierId]);

            $tiers[] = [
                'id'        => $tierId,
                'name'      => $tier['name'],
                'price'     => (float) $tier['price'],
                'available' => $available,
                'benefits'  => $benefitsStmt->fetchAll(),
            ];
        }

        if ($tiers !== []) {
            $events[] = [
                'id'    => $eventId,
                'name'  => $event['name'],
                'tiers' => $tiers,
            ];
        }
    }

    return $events;
}

/**
 * Find or create a customer record for checkout / webhook fulfillment.
 */
function getOrCreateCustomerForPurchase(PDO $db, string $email, string $name = ''): int
{
    $email = strtolower(trim($email));
    $existing = getCustomerByEmail($db, $email);

    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $stmt = $db->prepare(
        'INSERT INTO customers (name, email, password_hash) VALUES (:name, :email, :hash) RETURNING id'
    );
    $stmt->execute([
        'name'  => $name !== '' ? $name : explode('@', $email)[0],
        'email' => $email,
        'hash'  => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * Normalize and validate a purchase quantity against vault availability.
 *
 * @throws InvalidArgumentException when quantity is invalid or exceeds availability
 */
function parsePurchaseQuantity(mixed $rawQuantity, int $maxAvailable): int
{
    if (!is_numeric($rawQuantity)) {
        throw new InvalidArgumentException('Quantity must be a number.');
    }

    $quantity = (int) $rawQuantity;
    if ($quantity < 1) {
        throw new InvalidArgumentException('Quantity must be at least 1.');
    }

    if ($quantity > $maxAvailable) {
        throw new InvalidArgumentException("Only {$maxAvailable} ticket(s) available.");
    }

    return $quantity;
}

/**
 * Unified multi-ticket assignment after successful online payment (Stripe or eSewa).
 *
 * @return array{
 *   success: bool,
 *   ticket_ids: list<string>,
 *   ticket_id: ?string,
 *   message: string,
 *   email: string,
 *   event_name?: string,
 *   tier_name?: string,
 *   quantity_requested?: int,
 *   quantity_assigned?: int
 * }
 */
function assignMultipleTickets(
    PDO $db,
    int $eventId,
    int $tierId,
    string $customerEmail,
    string $gateway,
    string $paymentId,
    int $quantity,
    ?string $paymentReference = null
): array {
    ensureCustomerSchema($db);

    $gateway = strtolower(trim($gateway));
    $paymentId = trim($paymentId);
    $paymentReference = trim($paymentReference ?? $paymentId);
    $customerEmail = strtolower(trim($customerEmail));
    $quantity = max(1, $quantity);

    // Idempotent – webhook, success page, and callback may all call this
    $existingStmt = $db->prepare(
        'SELECT ct.ticket_id, c.email
         FROM customer_tickets ct
         JOIN customers c ON c.id = ct.customer_id
         WHERE ct.payment_id = :payment_id OR ct.payment_reference = :payment_reference
         ORDER BY ct.ticket_id ASC'
    );
    $existingStmt->execute([
        'payment_id'        => $paymentId,
        'payment_reference' => $paymentReference,
    ]);
    $existingRows = $existingStmt->fetchAll();
    if ($existingRows !== []) {
        $ticketIds = array_map(static fn (array $row): string => (string) $row['ticket_id'], $existingRows);
        $result = [
            'success'             => true,
            'ticket_ids'          => $ticketIds,
            'ticket_id'           => $ticketIds[0] ?? null,
            'message'             => 'Already fulfilled.',
            'email'               => (string) $existingRows[0]['email'],
            'quantity_requested'  => $quantity,
            'quantity_assigned'   => count($ticketIds),
        ];

        return enrichAssignTicketResult($db, $tierId, $result);
    }

    $db->beginTransaction();

    try {
        $ticketStmt = $db->prepare(
            "SELECT id FROM tickets
             WHERE event_id = :event_id AND tier_id = :tier_id
               AND customer_id IS NULL AND status = 'Active'
               AND (allocated_distributor_id IS NULL OR allocated_distributor_id = '')
             ORDER BY physical_number ASC
             LIMIT :quantity
             FOR UPDATE"
        );
        $ticketStmt->bindValue('event_id', $eventId, PDO::PARAM_INT);
        $ticketStmt->bindValue('tier_id', $tierId, PDO::PARAM_INT);
        $ticketStmt->bindValue('quantity', $quantity, PDO::PARAM_INT);
        $ticketStmt->execute();
        $ticketRows = $ticketStmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($ticketRows) < $quantity) {
            $db->rollBack();
            auditLog(
                'SALE',
                'Insufficient tickets for event ' . $eventId . ' tier ' . $tierId
                . " (needed {$quantity}, found " . count($ticketRows) . ", {$gateway} {$paymentId})"
            );

            return [
                'success'            => false,
                'ticket_ids'         => [],
                'ticket_id'          => null,
                'message'            => 'Not enough tickets available.',
                'email'              => $customerEmail,
                'quantity_requested' => $quantity,
                'quantity_assigned'  => 0,
            ];
        }

        $customerId = getOrCreateCustomerForPurchase($db, $customerEmail);

        $update = $db->prepare(
            "UPDATE tickets SET
                customer_id = :customer_id,
                purchased_at = NOW(),
                allocated_distributor_id = NULL,
                allocated_distributor_name = 'Online Sale'
             WHERE id = :ticket_id"
        );
        $link = $db->prepare(
            'INSERT INTO customer_tickets
                (customer_id, ticket_id, payment_id, payment_gateway, payment_reference)
             VALUES (:customer_id, :ticket_id, :payment_id, :payment_gateway, :payment_reference)'
        );

        $assignedIds = [];
        foreach ($ticketRows as $ticketId) {
            $update->execute(['customer_id' => $customerId, 'ticket_id' => $ticketId]);
            $link->execute([
                'customer_id'       => $customerId,
                'ticket_id'         => $ticketId,
                'payment_id'        => $paymentId,
                'payment_gateway'   => $gateway,
                'payment_reference' => $paymentReference,
            ]);
            $assignedIds[] = (string) $ticketId;
        }

        $db->commit();

        $assignedCount = count($assignedIds);
        auditLog(
            'SALE',
            "{$assignedCount} ticket(s) sold via {$gateway} to {$customerEmail} (ref {$paymentReference})"
        );

        $result = [
            'success'            => true,
            'ticket_ids'         => $assignedIds,
            'ticket_id'          => $assignedIds[0] ?? null,
            'message'            => $assignedCount === 1 ? 'Ticket assigned.' : 'Tickets assigned.',
            'email'              => $customerEmail,
            'quantity_requested' => $quantity,
            'quantity_assigned'  => $assignedCount,
        ];

        return enrichAssignTicketResult($db, $tierId, $result);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        auditLog('SALE', 'Fulfillment error: ' . $e->getMessage());

        return [
            'success'            => false,
            'ticket_ids'         => [],
            'ticket_id'          => null,
            'message'            => $e->getMessage(),
            'email'              => $customerEmail,
            'quantity_requested' => $quantity,
            'quantity_assigned'  => 0,
        ];
    }
}

/**
 * Unified ticket assignment after successful online payment (Stripe or eSewa).
 *
 * @return array{success: bool, ticket_id: ?string, ticket_ids?: list<string>, message: string, email: string, event_name?: string, tier_name?: string}
 */
function assignTicket(
    PDO $db,
    int $eventId,
    int $tierId,
    string $customerEmail,
    string $gateway,
    string $paymentId,
    ?string $paymentReference = null
): array {
    return assignMultipleTickets(
        $db,
        $eventId,
        $tierId,
        $customerEmail,
        $gateway,
        $paymentId,
        1,
        $paymentReference
    );
}

/**
 * @param array{success: bool, ticket_id: ?string, ticket_ids?: list<string>, message: string, email: string} $result
 * @return array{success: bool, ticket_id: ?string, ticket_ids?: list<string>, message: string, email: string, event_name?: string, tier_name?: string}
 */
function enrichAssignTicketResult(PDO $db, int $tierId, array $result): array
{
    if (!$result['success']) {
        return $result;
    }

    $tierStmt = $db->prepare(
        'SELECT ti.name AS tier_name, e.name AS event_name FROM tiers ti JOIN events e ON e.id = ti.event_id
         WHERE ti.id = :tier_id'
    );
    $tierStmt->execute(['tier_id' => $tierId]);
    $meta = $tierStmt->fetch();
    if ($meta) {
        $result['event_name'] = (string) $meta['event_name'];
        $result['tier_name'] = (string) $meta['tier_name'];
    }

    return $result;
}

/**
 * @deprecated Use assignTicket() – kept for backward compatibility.
 *
 * @return array{success: bool, ticket_id: ?string, message: string, email?: string}
 */
function fulfillOnlineTicketPurchase(
    PDO $db,
    int $eventId,
    int $tierId,
    string $customerEmail,
    string $paymentId
): array {
    return assignTicket($db, $eventId, $tierId, $customerEmail, 'stripe', $paymentId);
}

/**
 * Fulfill a Stripe Checkout session (used by webhook and purchase success page).
 *
 * @return array{success: bool, ticket_id: ?string, ticket_ids?: list<string>, message: string, email: string, event_name?: string, tier_name?: string}
 */
function fulfillStripeCheckoutSession(PDO $db, object $session): array
{
    $eventId = (int) ($session->metadata['event_id'] ?? 0);
    $tierId = (int) ($session->metadata['tier_id'] ?? 0);
    $email = strtolower(trim($session->metadata['email'] ?? $session->customer_email ?? ''));
    $quantity = max(1, (int) ($session->metadata['quantity'] ?? 1));

    if ($eventId <= 0 || $tierId <= 0) {
        $ref = (string) ($session->client_reference_id ?? '');
        if (str_contains($ref, ':')) {
            [$eventId, $tierId] = array_map('intval', explode(':', $ref, 2));
        }
    }

    if ($eventId <= 0 || $tierId <= 0 || $email === '') {
        return ['success' => false, 'ticket_id' => null, 'ticket_ids' => [], 'message' => 'Missing checkout metadata.', 'email' => ''];
    }

    $result = assignMultipleTickets($db, $eventId, $tierId, $email, 'stripe', (string) $session->id, $quantity);
    $result['email'] = $email;

    return $result;
}

/**
 * Whether a fulfillment result should trigger the purchase confirmation email.
 */
function shouldSendPurchaseEmail(string $message): bool
{
    return in_array($message, ['Ticket assigned.', 'Tickets assigned.'], true);
}

/**
 * @return array<int, array<string, mixed>>
 */
function getCustomerTicketsWithDetails(PDO $db, int $customerId): array
{
    ensureCustomerSchema($db);

    $stmt = $db->prepare(
        'SELECT t.id, t.physical_number, t.purchased_at, t.status,
                e.name AS event_name, ti.name AS tier_name,
                ct.payment_id, ct.payment_gateway, ct.payment_reference
         FROM customer_tickets ct
         JOIN tickets t ON t.id = ct.ticket_id
         JOIN events e ON e.id = t.event_id
         JOIN tiers ti ON ti.id = t.tier_id
         WHERE ct.customer_id = :customer_id
         ORDER BY ct.purchased_at DESC'
    );
    $stmt->execute(['customer_id' => $customerId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $details = getTicketDetails($db, $row['id']);
        $row['benefits'] = $details['benefits'] ?? [];
    }
    unset($row);

    return $rows;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAllCustomersWithStats(PDO $db): array
{
    ensureCustomerSchema($db);

    return $db->query(
        'SELECT c.id, c.name, c.email, c.created_at,
                COUNT(ct.id) AS ticket_count
         FROM customers c
         LEFT JOIN customer_tickets ct ON ct.customer_id = c.id
         GROUP BY c.id, c.name, c.email, c.created_at
         ORDER BY c.created_at DESC'
    )->fetchAll();
}

function getStripeCurrency(): string
{
    return strtolower(env('STRIPE_CURRENCY', 'npr') ?? 'npr');
}

/**
 * @return \Stripe\StripeClient|null
 */
function getStripeClient(): ?\Stripe\StripeClient
{
    $secret = trim(env('STRIPE_SECRET_KEY', '') ?? '');
    if (
        $secret === ''
        || str_contains($secret, '...')
        || str_contains($secret, 'your_')
        || str_contains($secret, 'your-')
        || !str_starts_with($secret, 'sk_')
    ) {
        return null;
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    return new \Stripe\StripeClient($secret);
}

/**
 * Local-only checkout (APP_DEBUG=1) so buy flow can be tested without Stripe/eSewa keys.
 */
function isDevCheckoutEnabled(): bool
{
    return env('APP_DEBUG', '0') === '1';
}

/**
 * Send ticket QR email after online purchase (single or multiple tickets).
 *
 * @param string|list<string> $ticketIds
 */
function sendTicketPurchaseEmail(string $email, string|array $ticketIds, string $eventName, string $tierName): bool
{
    require_once __DIR__ . '/mailer.php';

    $ticketIds = is_array($ticketIds) ? array_values($ticketIds) : [trim($ticketIds)];
    $ticketIds = array_values(array_filter($ticketIds, static fn (string $id): bool => $id !== ''));

    if ($ticketIds === []) {
        return false;
    }

    $appUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
    $loginUrl = $appUrl . '/customer_login.php';
    $registerUrl = $appUrl . '/customer_register.php';
    $dashboardUrl = $appUrl . '/customer_dashboard.php';

    $count = count($ticketIds);
    $subject = $count === 1
        ? "Your PassGate ticket – {$eventName}"
        : "Your {$count} PassGate tickets – {$eventName}";

    $textLines = [
        'Thank you for your purchase!',
        '',
        "Event: {$eventName}",
        "Tier: {$tierName}",
        'Tickets:',
    ];
    $htmlTicketBlocks = '';

    foreach ($ticketIds as $ticketId) {
        $qrUrl = $appUrl . '/qr.php?id=' . urlencode($ticketId);
        $textLines[] = "- {$ticketId}: {$qrUrl}";
        $htmlTicketBlocks .= '<div style="margin:0 0 1rem;padding:0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;">'
            . '<strong>Ticket ID:</strong> <code>' . htmlspecialchars($ticketId) . '</code><br>'
            . '<a href="' . htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') . '">View QR code</a><br>'
            . '<img src="' . htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') . '" alt="Ticket QR" width="180" height="180">'
            . '</div>';
    }

    $textLines[] = '';
    $textLines[] = "View all tickets: {$dashboardUrl}";
    $textLines[] = "Create an account: {$registerUrl}";
    $textLines[] = "Log in: {$loginUrl}";

    $textBody = implode("\n", $textLines);
    $htmlBody = '<p>Thank you for your purchase!</p>'
        . '<p><strong>Event:</strong> ' . htmlspecialchars($eventName) . '<br>'
        . '<strong>Tier:</strong> ' . htmlspecialchars($tierName) . '<br>'
        . '<strong>Tickets:</strong> ' . $count . '</p>'
        . $htmlTicketBlocks
        . '<p><a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '">View your dashboard</a> · '
        . '<a href="' . htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') . '">Create account</a> · '
        . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">Log in</a></p>';

    if (shouldUseDevMailFallback()) {
        $devBody = "TICKETS (" . $count . ")\n" . implode("\n", $ticketIds) . "\nDashboard: {$dashboardUrl}";
        writeDevLoginLink($email, $devBody);

        return true;
    }

    $result = sendSmtpEmail($email, $subject, $textBody, $htmlBody);

    if (!$result['success']) {
        auditLog('MAIL', "Ticket email failed for {$email}: {$result['error']}");
    }

    return $result['success'];
}

// ---------------------------------------------------------------------------
// eSewa payment gateway
// ---------------------------------------------------------------------------

function getEsewaMerchantCode(): string
{
    $code = trim(env('ESEWA_MERCHANT_CODE', '') ?? '');
    if ($code === '' && isDevCheckoutEnabled()) {
        // Public eSewa sandbox merchant (developer.esewa.com.np)
        return 'EPAYTEST';
    }

    return $code;
}

function getEsewaSecretKey(): string
{
    $secret = trim(env('ESEWA_SECRET_KEY', '') ?? '');
    if ($secret === '' && isDevCheckoutEnabled()) {
        // Public eSewa sandbox secret from their docs
        return '8gBm/:&EnhH.1/q';
    }

    return $secret;
}

function isEsewaConfigured(): bool
{
    $code = getEsewaMerchantCode();
    $secret = getEsewaSecretKey();

    return $code !== '' && $secret !== '' && !str_contains($code, 'your_');
}

function getEsewaFormUrl(): string
{
    $testMode = env('ESEWA_TEST_MODE', '1') === '1';
    if (!$testMode) {
        return rtrim(env('ESEWA_LIVE_URL', 'https://epay.esewa.com.np/api/epay/main/v2/form'), '/');
    }

    return rtrim(env('ESEWA_SANDBOX_URL', 'https://rc-epay.esewa.com.np/api/epay/main/v2/form'), '/');
}

function generateEsewaTransactionUuid(): string
{
    return 'PG-' . date('ymd') . '-' . bin2hex(random_bytes(6));
}

/**
 * HMAC-SHA256 signature for eSewa ePay v2 form submission.
 */
function generateEsewaSignature(
    string $totalAmount,
    string $transactionUuid,
    string $productCode,
    string $secretKey
): string {
    $message = "total_amount={$totalAmount},transaction_uuid={$transactionUuid},product_code={$productCode}";

    return base64_encode(hash_hmac('sha256', $message, $secretKey, true));
}

/**
 * Verify signature on eSewa callback response payload.
 */
function verifyEsewaResponseSignature(array $payload, string $secretKey): bool
{
    $signedFieldNames = trim($payload['signed_field_names'] ?? '');
    $receivedSignature = trim($payload['signature'] ?? '');

    if ($signedFieldNames === '' || $receivedSignature === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signedFieldNames) as $field) {
        $field = trim($field);
        if ($field === '') {
            continue;
        }
        $value = (string) ($payload[$field] ?? '');
        $parts[] = "{$field}={$value}";
    }

    $message = implode(',', $parts);
    $expected = base64_encode(hash_hmac('sha256', $message, $secretKey, true));

    return hash_equals($expected, $receivedSignature);
}

/**
 * Store pending checkout context keyed by transaction_uuid (eSewa callback lookup).
 */
function createPendingPurchase(
    PDO $db,
    string $transactionUuid,
    int $eventId,
    int $tierId,
    string $email,
    float $amount,
    string $gateway = 'esewa',
    int $quantity = 1
): void {
    ensureCustomerSchema($db);

    $stmt = $db->prepare(
        'INSERT INTO payment_pending (transaction_uuid, event_id, tier_id, email, gateway, amount, quantity)
         VALUES (:uuid, :event_id, :tier_id, :email, :gateway, :amount, :quantity)'
    );
    $stmt->execute([
        'uuid'     => $transactionUuid,
        'event_id' => $eventId,
        'tier_id'  => $tierId,
        'email'    => strtolower(trim($email)),
        'gateway'  => $gateway,
        'amount'   => $amount,
        'quantity' => max(1, $quantity),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function getPendingPurchase(PDO $db, string $transactionUuid): ?array
{
    ensureCustomerSchema($db);

    $stmt = $db->prepare('SELECT * FROM payment_pending WHERE transaction_uuid = :uuid');
    $stmt->execute(['uuid' => $transactionUuid]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Build signed eSewa form fields for browser POST redirect.
 *
 * @return array<string, string>
 */
function buildEsewaPaymentForm(
    int $eventId,
    int $tierId,
    string $email,
    float $unitPrice,
    string $eventName,
    string $tierName,
    int $quantity = 1
): array {
    $merchantCode = getEsewaMerchantCode();
    $secretKey = getEsewaSecretKey();
    $appUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/');

    $quantity = max(1, $quantity);
    $totalPrice = $unitPrice * $quantity;
    $amountStr = number_format($totalPrice, 2, '.', '');
    $transactionUuid = generateEsewaTransactionUuid();

    $db = getDb();
    createPendingPurchase($db, $transactionUuid, $eventId, $tierId, $email, $totalPrice, 'esewa', $quantity);

    $signature = generateEsewaSignature($amountStr, $transactionUuid, $merchantCode, $secretKey);

    auditLog(
        'ESEWA',
        "Payment initiated {$transactionUuid} for {$email} event {$eventId} tier {$tierId}"
        . " qty {$quantity} ({$eventName} – {$tierName})"
    );

    return [
        'esewa_url'               => getEsewaFormUrl(),
        'amount'                  => $amountStr,
        'tax_amount'              => '0',
        'total_amount'            => $amountStr,
        'transaction_uuid'        => $transactionUuid,
        'product_code'            => $merchantCode,
        'product_service_charge'  => '0',
        'product_delivery_charge' => '0',
        'success_url'             => $appUrl . '/esewa_callback.php',
        'failure_url'             => $appUrl . '/buy.php?error=' . urlencode('eSewa payment cancelled or failed'),
        'signed_field_names'      => 'total_amount,transaction_uuid,product_code',
        'signature'               => $signature,
    ];
}

/**
 * Fulfill ticket after verified eSewa callback.
 *
 * @return array{success: bool, ticket_id: ?string, ticket_ids?: list<string>, message: string, email: string, event_name?: string, tier_name?: string}
 */
function fulfillEsewaPayment(PDO $db, array $callbackData): array
{
    $transactionUuid = trim($callbackData['transaction_uuid'] ?? '');
    $status = strtoupper(trim($callbackData['status'] ?? ''));
    $transactionCode = trim($callbackData['transaction_code'] ?? '');

    if ($transactionUuid === '') {
        return ['success' => false, 'ticket_id' => null, 'ticket_ids' => [], 'message' => 'Missing transaction UUID.', 'email' => ''];
    }

    if ($status !== 'COMPLETE') {
        auditLog('ESEWA', "Payment not complete for {$transactionUuid}: {$status}");

        return ['success' => false, 'ticket_id' => null, 'ticket_ids' => [], 'message' => 'Payment not completed.', 'email' => ''];
    }

    $pending = getPendingPurchase($db, $transactionUuid);
    if ($pending === null) {
        auditLog('ESEWA', "No pending purchase for {$transactionUuid}");

        return ['success' => false, 'ticket_id' => null, 'ticket_ids' => [], 'message' => 'Unknown transaction.', 'email' => ''];
    }

    $quantity = max(1, (int) ($pending['quantity'] ?? 1));

    return assignMultipleTickets(
        $db,
        (int) $pending['event_id'],
        (int) $pending['tier_id'],
        (string) $pending['email'],
        'esewa',
        $transactionUuid,
        $quantity,
        $transactionCode !== '' ? $transactionCode : $transactionUuid
    );
}

/**
 * Online sales breakdown by payment gateway for admin analytics.
 *
 * @return list<array{payment_gateway: string, sale_count: int, revenue: float}>
 */
function getOnlineSalesByGateway(PDO $db, int $eventId): array
{
    ensureCustomerSchema($db);

    $stmt = $db->prepare(
        "SELECT COALESCE(NULLIF(ct.payment_gateway, ''), 'unknown') AS payment_gateway,
                COUNT(*) AS sale_count,
                COALESCE(SUM(ti.price), 0) AS revenue
         FROM customer_tickets ct
         JOIN tickets t ON t.id = ct.ticket_id
         JOIN tiers ti ON ti.id = t.tier_id
         WHERE t.event_id = :event_id
         GROUP BY COALESCE(NULLIF(ct.payment_gateway, ''), 'unknown')
         ORDER BY payment_gateway"
    );
    $stmt->execute(['event_id' => $eventId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'payment_gateway' => (string) $row['payment_gateway'],
            'sale_count'      => (int) $row['sale_count'],
            'revenue'         => (float) $row['revenue'],
        ];
    }

    return $rows;
}
