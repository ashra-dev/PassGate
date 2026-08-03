<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
$path = rtrim($path, '/');

// Support /api.php/scan, /api/scan via rewrite, or ?action=scan
$action = $_GET['action'] ?? '';

if ($action === '' && preg_match('#/api(?:\.php)?/(scan|ticket)(?:/(.+))?$#', $path, $m)) {
    $action = $m[1];
    if (!empty($m[2])) {
        $_GET['id'] = urldecode($m[2]);
    }
}

if ($action === '' && $method === 'POST') {
    $action = 'scan';
}

if ($action === '' && $method === 'GET' && isset($_GET['id'])) {
    $action = 'ticket';
}

try {
    $db = getDb();

    if ($action === 'scan' && $method === 'POST') {
        handleScan($db);
    } elseif ($action === 'ticket_status' && $method === 'GET') {
        handleTicketStatus($db);
    } elseif ($action === 'ticket' && $method === 'GET') {
        handleGetTicket($db);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Endpoint not found.']);
    }
} catch (Throwable $e) {
    auditLog('API', 'Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
}

/**
 * POST /api/scan – process a terminal scan (public, like legacy save.php).
 */
function handleScan(PDO $db): void
{
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?? [];

    $ticketId = trim($input['ticket_id'] ?? $input['ticketId'] ?? '');
    $requestedStation = trim($input['station'] ?? $input['stationType'] ?? '');

    if ($ticketId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ticket_id is required.']);
        return;
    }

    $authContext = resolveScanAuthContext($requestedStation);
    if (!$authContext['allowed']) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => $authContext['message'] ?? 'Authentication required.']);
        return;
    }

    $station = $authContext['station'];
    $stallId = $authContext['stall_id'];

    // Pad numeric-only IDs (legacy behaviour)
    if (preg_match('/^\d+$/', $ticketId)) {
        $ticketId = str_pad($ticketId, 6, '0', STR_PAD_LEFT);
    }

    $result = processTicketScan($db, $ticketId, $station, $stallId);
    $httpCode = $result['http_code'] ?? 200;
    unset($result['http_code']);

    // Attach full benefit usage for terminal / UI (does not alter scan logic)
    if (($result['status'] ?? '') !== 'error') {
        $details = getTicketDetails($db, $ticketId);
        if ($details !== null) {
            $result['benefits_summary'] = array_map(
                static fn (array $b): array => [
                    'name' => trim($b['name']),
                    'used' => (int) $b['used'],
                    'max'  => (int) $b['max_uses'],
                ],
                $details['benefits']
            );
            $result['ticket_meta'] = [
                'event_name' => $details['ticket']['event_name'] ?? '',
                'tier_name'  => $details['ticket']['tier_name'] ?? '',
                'physical_number' => (int) ($details['ticket']['physical_number'] ?? 0),
            ];
        }

        if (isStallAuthenticated()) {
            $result['stall_name'] = (string) ($_SESSION['stall_name'] ?? '');
        }
    }

    http_response_code($httpCode);

    // Backward-compatible status for index.php
    if ($result['status'] === 'limit_reached') {
        $result['status'] = 'already_scanned';
    }

    echo json_encode($result);
}

/**
 * GET /api.php?action=ticket_status&id=... – read-only lookup for staff terminal.
 */
function handleTicketStatus(PDO $db): void
{
    if (!isStallAuthenticated() && !isDistributorAuthenticated()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Staff login required.']);
        return;
    }

    $ticketId = trim($_GET['id'] ?? '');
    if ($ticketId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Ticket ID is required.']);
        return;
    }

    if (preg_match('/^\d+$/', $ticketId)) {
        $ticketId = str_pad($ticketId, 6, '0', STR_PAD_LEFT);
    }

    $payload = buildTicketStatusPayload($db, $ticketId);
    if ($payload === null) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Ticket not found.']);
        return;
    }

    echo json_encode(['status' => 'success', 'data' => $payload]);
}

/**
 * GET /api/ticket/{id} – ticket details (requires authenticated session).
 */
function handleGetTicket(PDO $db): void
{
    if (
        !isStallAuthenticated()
        && (empty($_SESSION['distributor_authenticated']) || $_SESSION['distributor_authenticated'] !== true)
    ) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
        return;
    }

    $ticketId = trim($_GET['id'] ?? '');
    if ($ticketId === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Ticket ID is required.']);
        return;
    }

    $details = getTicketDetails($db, $ticketId);

    if ($details === null) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Ticket not found.']);
        return;
    }

    echo json_encode(['status' => 'success', 'data' => $details]);
}
