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
                e.name AS event_name, d.name AS distributor_company, d.email AS distributor_email
         FROM tickets t
         JOIN tiers ti ON ti.id = t.tier_id
         JOIN events e ON e.id = t.event_id
         LEFT JOIN distributors d ON d.id = t.allocated_distributor_id
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
        'INSERT INTO benefits (tier_id, name, max_uses) VALUES (:tier_id, :name, :max_uses)'
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
            'redirect' => $record['role'] === 'admin' ? 'distributors.php' : 'index.php',
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
    $db->exec(
        'CREATE TABLE IF NOT EXISTS stalls (
            id            SERIAL PRIMARY KEY,
            name          VARCHAR(255) NOT NULL,
            email         VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
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
    $stmt = $db->prepare('SELECT id, name, email, password_hash FROM stalls WHERE LOWER(email) = :email');
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
    $_SESSION['stall_authenticated'] = true;
    $_SESSION['stall_id'] = (int) $stall['id'];
    $_SESSION['stall_name'] = (string) $stall['name'];
    $_SESSION['stall_email'] = (string) $stall['email'];
    unset($_SESSION['station_pin_unlocked'], $_SESSION['pin_station_type']);
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

    return $db->query('SELECT id, name, email, created_at FROM stalls ORDER BY name ASC')->fetchAll();
}

/**
 * Create a new stall account.
 */
function createStall(PDO $db, string $name, string $email, string $password): int
{
    ensureStallsSchema($db);

    $name = trim($name);
    $email = strtolower(trim($email));

    if ($name === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Name, email, and password are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email address.');
    }

    assertUniqueStallPasswordForEmail($db, $email, $password);

    $stmt = $db->prepare(
        'INSERT INTO stalls (name, email, password_hash) VALUES (:name, :email, :password_hash) RETURNING id'
    );
    $stmt->execute([
        'name'          => $name,
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
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
        return [
            'allowed'  => true,
            'station'  => (string) $_SESSION['stall_name'],
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

    if (!empty($_SESSION['station_pin_unlocked'])) {
        $station = trim($requestedStation);
        if ($station === '') {
            $station = (string) ($_SESSION['pin_station_type'] ?? '');
        }

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
        'message'  => 'Terminal not authenticated. Please log in as a stall.',
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

    safeRedirect('index.php');
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
