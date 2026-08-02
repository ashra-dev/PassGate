<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/functions.php';

$data = json_decode(file_get_contents('php://input') ?: '', true) ?? [];
$action = $data['action'] ?? $_GET['action'] ?? '';

if ($action === 'unlock_station') {
    $pin = trim($data['pin'] ?? '');
    $station = trim($data['station'] ?? '');
    $expectedPin = env('STATION_PIN', '1111');
    $pinEnabled = env('STATION_PIN_ENABLED', '1') === '1';

    if (!$pinEnabled) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Station PIN login is disabled. Use stall login.']);
        exit;
    }

    if ($station === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Station is required.']);
        exit;
    }

    if (!hash_equals($expectedPin, $pin)) {
        auditLog('AUTH', "Failed station unlock for {$station}");
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid PIN.']);
        exit;
    }

    session_regenerate_id(true);
    clearTerminalSession();
    $_SESSION['station_pin_unlocked'] = true;
    $_SESSION['pin_station_type'] = $station;

    auditLog('AUTH', "Station PIN unlock for {$station}");
    echo json_encode(['status' => 'success', 'station' => $station]);
    exit;
}

if ($action === 'stall_login') {
    $email = strtolower(trim($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
        exit;
    }

    try {
        $db = getDb();
        ensureStallsSchema($db);
        $stall = authenticateStall($db, $email, $password);

        if ($stall === null) {
            auditLog('AUTH', "Failed stall login for {$email}");
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
            exit;
        }

        establishStallSession($stall);
        auditLog('AUTH', "Stall login success: {$stall['name']} ({$email})");

        echo json_encode([
            'status'      => 'success',
            'stall_id'    => (int) $stall['id'],
            'stall_name'  => $stall['name'],
            'stall_email' => $stall['email'],
        ]);
    } catch (Throwable $e) {
        auditLog('AUTH', 'Stall login error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to process login request.']);
    }
    exit;
}

if ($action === 'lock_terminal') {
    $stallName = $_SESSION['stall_name'] ?? '';
    clearTerminalSession();
    if ($stallName !== '') {
        auditLog('AUTH', "Terminal locked (stall: {$stallName})");
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action !== 'request_login') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit;
}

$email = strtolower(trim($data['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email address is required.']);
    exit;
}

try {
    $db = getDb();

    $stmt = $db->prepare('SELECT id, role FROM distributors WHERE LOWER(email) = :email');
    $stmt->execute(['email' => $email]);
    $distributor = $stmt->fetch();

    // Always respond success to avoid email enumeration
    if (!$distributor) {
        auditLog('AUTH', "Login link requested for unknown email: {$email}");
        echo json_encode([
            'status'  => 'success',
            'message' => 'If that email is registered, a login link has been sent.',
        ]);
        exit;
    }

    $token = bin2hex(random_bytes(32));

    // Invalidate previous tokens for this distributor
    $purge = $db->prepare('DELETE FROM login_tokens WHERE distributor_id = :id');
    $purge->execute(['id' => $distributor['id']]);

    // Use PostgreSQL NOW() so expiry matches DB timezone (avoid PHP UTC vs PG local mismatch)
    $insert = $db->prepare(
        'INSERT INTO login_tokens (distributor_id, token, expires_at)
         VALUES (:distributor_id, :token, NOW() + INTERVAL \'1 hour\')'
    );
    $insert->execute([
        'distributor_id' => $distributor['id'],
        'token'          => $token,
    ]);

    dispatchMagicLinkEmail($email, $token);
    auditLog('AUTH', "Magic link issued for {$email}");

    echo json_encode([
        'status'  => 'success',
        'message' => 'If that email is registered, a login link has been sent.',
    ]);
} catch (Throwable $e) {
    auditLog('AUTH', 'Login request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to process login request.']);
}
