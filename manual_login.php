<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

redirectIfAuthenticated();

$error = null;
$emailValue = '';
$tokenValue = '';
$urlValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = strtolower(trim($_POST['email'] ?? ''));
    $tokenValue = preg_replace('/\s+/', '', trim($_POST['token'] ?? ''));
    $urlValue = trim($_POST['login_url'] ?? '');

    if ($urlValue !== '') {
        $parsed = parseMagicLinkInput($urlValue);
        if ($parsed['email'] !== '') {
            $emailValue = $parsed['email'];
        }
        if ($parsed['token'] !== '') {
            $tokenValue = $parsed['token'];
        }
    }

    try {
        $db = getDb();
        $result = completeMagicLinkLogin($db, $emailValue, $tokenValue);

        if ($result['success'] && $result['redirect'] !== null) {
            safeRedirect($result['redirect']);
        }

        $error = $result['error'] ?? 'Invalid or expired token. Please request a new login link.';
    } catch (Throwable $e) {
        auditLog('AUTH', 'Manual login error: ' . $e->getMessage());
        $error = 'An error occurred during login. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PassGate Pro – Manual Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans antialiased">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-md w-full p-6 space-y-6">
        <div class="text-center space-y-2">
            <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center mx-auto text-xl">
                <i class="fa-solid fa-key"></i>
            </div>
            <h1 class="text-xl font-bold text-slate-900">Manual Login</h1>
            <p class="text-xs text-slate-400">
                Paste your login token or the full link from your email if the button didn&rsquo;t work.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm">
                <i class="fa-solid fa-circle-xmark mr-1"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1" for="login_url">
                    Full login URL (optional)
                </label>
                <input type="text" id="login_url" name="login_url"
                       value="<?php echo htmlspecialchars($urlValue); ?>"
                       placeholder="http://localhost:8000/login.php?token=...&email=..."
                       class="w-full bg-slate-100 text-slate-800 text-sm rounded-xl px-3 py-2.5 border-none focus:ring-2 focus:ring-indigo-500">
                <p class="text-[10px] text-slate-400 mt-1 pl-1">If provided, email and token below are filled automatically.</p>
            </div>

            <div>
                <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1" for="email">
                    Email
                </label>
                <input type="email" id="email" name="email" required
                       value="<?php echo htmlspecialchars($emailValue); ?>"
                       placeholder="you@company.com"
                       class="w-full bg-slate-100 text-slate-800 text-sm rounded-xl px-3 py-2.5 border-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1.5 pl-1" for="token">
                    Login token
                </label>
                <input type="text" id="token" name="token" required
                       value="<?php echo htmlspecialchars($tokenValue); ?>"
                       placeholder="64-character token from your email"
                       minlength="64" maxlength="64"
                       pattern="[a-fA-F0-9]{64}"
                       class="w-full bg-slate-100 text-slate-800 text-sm rounded-xl px-3 py-2.5 border-none font-mono focus:ring-2 focus:ring-indigo-500">
            </div>

            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-3 text-sm transition-all shadow-md">
                Log In
            </button>
        </form>

        <p class="text-center text-xs text-slate-400">
            Need a new link?
            <a href="index.php" class="text-indigo-500 hover:underline">Request one from the terminal</a>
        </p>
    </div>

    <script>
        document.getElementById('login_url').addEventListener('change', function () {
            const raw = this.value.trim();
            if (!raw) return;

            try {
                const url = new URL(raw);
                const email = url.searchParams.get('email');
                const token = url.searchParams.get('token');
                if (email) document.getElementById('email').value = email;
                if (token) document.getElementById('token').value = token;
            } catch (e) {
                // Not a valid URL; server-side parse_str handles it on submit
            }
        });
    </script>
</body>
</html>
