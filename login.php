<?php
require_once __DIR__ . '/bootstrap.php';


// If already logged in, redirect to dashboard
if (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

// Handle password-based login (admin fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Güvenlik doğrulaması başarısız. Lütfen sayfayı yenileyin.';
    } else {
        // Fetch settings via helper
        $settings = [
            'user_password' => getSetting($pdo, 'user_password'),
            'turnstile_site_key' => getSetting($pdo, 'turnstile_site_key'),
            'turnstile_secret_key' => getSetting($pdo, 'turnstile_secret_key'),
        ];

        if (empty($settings['user_password'])) {
            die("Hata: Ayarlar tablosu boş veya eksik.");
        }

        // Cloudflare Turnstile Validation
        if (!empty($settings['turnstile_secret_key'])) {
            $turnstileToken = $_POST['cf-turnstile-response'] ?? '';

            $verifyData = [
                'secret' => $settings['turnstile_secret_key'],
                'response' => $turnstileToken,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            ];

            $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verifyData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $verifyResponse = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                // Network error reaching Cloudflare — log and allow (Turnstile is optional)
                writeLog("Turnstile verification network error: $curlError", 'WARNING', 'security');
            } else {
                $responseData = json_decode($verifyResponse);
                if (!$responseData || !$responseData->success) {
                    $error = 'Bot koruması doğrulaması başarısız.';
                }
            }
        }

        if (empty($error)) {
            $password = $_POST['password'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'];
            $maxAttempts = 5;
            $lockoutTime = 15;

            // Check lockout
            $stmt = $pdo->prepare("SELECT attempts, lockout_until FROM login_attempts WHERE ip_address = :ip");
            $stmt->execute([':ip' => $ip]);
            $attemptData = $stmt->fetch();

            if ($attemptData && $attemptData['lockout_until'] && strtotime($attemptData['lockout_until']) > time()) {
                $remaining = ceil((strtotime($attemptData['lockout_until']) - time()) / 60);
                $error = "Çok fazla hatalı giriş denemesi. Lütfen $remaining dakika bekleyin.";
            }

            if (empty($error)) {
                $storedHash = $settings['user_password'] ?? '';
                if ($storedHash && password_verify($password, $storedHash)) {
                    // Success - Admin login
                    $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);

                    $_SESSION['is_logged_in'] = true;
                    $_SESSION['user_id'] = 0;
                    $_SESSION['user_email'] = 'admin@local';
                    $_SESSION['user_name'] = 'Admin';
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['login_method'] = 'password';

                    // Log admin login
                    $logStmt = $pdo->prepare("INSERT INTO sync_history (user_email, action_type, details, ip_address) VALUES (?, 'login_success', ?, ?)");
                    $logStmt->execute(['admin@local', 'Şifre ile giriş yapıldı', $ip]);

                    header('Location: index.php');
                    exit;
                } else {
                    // Failure
                    $error = 'Hatalı şifre!';
                    $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP")->execute([$ip]);

                    $currentAttemptsStmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
                    $currentAttemptsStmt->execute([$ip]);
                    if ($currentAttemptsStmt->fetchColumn() >= $maxAttempts) {
                        $until = date('Y-m-d H:i:s', strtotime("+$lockoutTime minutes"));
                        $pdo->prepare("UPDATE login_attempts SET lockout_until = ? WHERE ip_address = ?")->execute([$until, $ip]);
                        $error = "Çok fazla hatalı giriş denemesi. Hesabınız $lockoutTime dakika boyunca kilitlendi.";
                    }
                }
            }
        }
    }
}


// Fetch settings for Turnstile
$turnstileSiteKey = getSetting($pdo, 'turnstile_site_key') ?? '';
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş - SyncApp</title>
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if ($turnstileSiteKey): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <style>
        .zoho-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .zoho-btn:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .divider span {
            padding: 0 1rem;
            color: #64748b;
            font-size: 0.875rem;
        }
    </style>
</head>

<body class="bg-[#1a1a1a] flex items-center justify-center min-h-screen" style="font-family: 'Inter', sans-serif;">
    <div class="bg-[#2d2d2d] p-8 rounded-2xl shadow-2xl w-full max-w-sm border border-white/10">
        <div class="flex justify-center mb-6">
            <img src="assets/nolto_logo.png" alt="Nolto" class="h-16 w-auto"
                style="filter: grayscale(100%) brightness(1.5);">
        </div>
        <h1 class="text-2xl font-bold mb-6 text-center text-white">Giriş Yap</h1>

        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Password Login (Admin) -->
        <form method="POST" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <div class="mb-4">
                <label class="block text-slate-300 text-sm font-medium mb-2" for="password">Admin Şifresi</label>
                <input
                    class="bg-[#323232] border border-white/15 rounded-xl w-full py-3 px-4 text-white placeholder-slate-500 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all"
                    id="password" name="password" type="password" placeholder="Şifreniz" required
                    autocomplete="current-password">
            </div>
            <?php if ($turnstileSiteKey): ?>
                <div class="cf-turnstile mb-4" data-sitekey="<?= htmlspecialchars($turnstileSiteKey) ?>" data-theme="dark">
                </div>
            <?php endif; ?>
            <button
                class="bg-white hover:bg-gray-100 text-black font-bold py-3 px-4 rounded-xl w-full focus:outline-none focus:ring-2 focus:ring-white/30 transition-all"
                type="submit">Giriş</button>
        </form>
    </div>
</body>

</html>