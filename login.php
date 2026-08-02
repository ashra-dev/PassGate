<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

redirectIfAuthenticated();

$token = trim($_GET['token'] ?? '');
$email = strtolower(trim($_GET['email'] ?? ''));
$error = null;

if ($token !== '' && $email !== '') {
    try {
        $db = getDb();
        $result = completeMagicLinkLogin($db, $email, $token);

        if ($result['success'] && $result['redirect'] !== null) {
            safeRedirect($result['redirect']);
        }

        $error = $result['error'] ?? 'Invalid or expired login link.';
    } catch (Throwable $e) {
        auditLog('AUTH', 'Login verification error: ' . $e->getMessage());
        $error = 'An error occurred during login. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PassGate Pro – Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl p-8 max-w-md w-full text-center space-y-4">
        <?php if ($error): ?>
            <div class="text-rose-600 font-semibold"><?php echo htmlspecialchars($error); ?></div>
            <a href="manual_login.php" class="inline-block mt-2 text-indigo-600 hover:underline">Try manual login</a>
            <a href="index.php" class="inline-block mt-4 text-slate-500 hover:underline block">Return to home</a>
        <?php else: ?>
            <p class="text-slate-600">Use the login link sent to your email, or request a new one from the home page.</p>
            <a href="manual_login.php" class="inline-block mt-2 text-indigo-600 hover:underline">Manual login with token</a>
            <a href="index.php" class="inline-block mt-4 bg-indigo-600 text-white px-6 py-2 rounded-xl font-bold">Go to PassGate</a>
        <?php endif; ?>
    </div>
</body>
</html>
