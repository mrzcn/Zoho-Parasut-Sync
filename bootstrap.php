<?php
// bootstrap.php — Central initialization file
// All PHP files include only this file

// Application version — used for cache busting after deploy
define('APP_VERSION', '2.7');

// Production error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// ---- Install Detection ----
// If .env doesn't exist, redirect to install wizard
// (skip for install.php itself and CLI/cron)
$isInstallPage = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'install.php');
$isCli = (php_sapi_name() === 'cli');
if (!$isInstallPage && !$isCli && !file_exists(__DIR__ . '/.env')) {
    header('Location: install.php');
    exit;
}

// Session (start only once)
if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie settings
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,           // Session cookie (browser close = expire)
        'path' => '/',
        'secure' => $isHttps,    // HTTPS-only in production
        'httponly' => true,        // No JS access to cookie
        'samesite' => 'Lax',       // CSRF protection
    ]);
    session_start();
}

// Composer Autoloader — classes + helper functions auto-loaded
require_once __DIR__ . '/vendor/autoload.php';

// Fallback autoloader — works without SSH when new classes are added
// Kicks in when Composer classmap is not up to date
spl_autoload_register(function ($class) {
    $dirs = [
        __DIR__ . '/controllers/',
        __DIR__ . '/classes/',
        __DIR__ . '/classes/Exceptions/',
        __DIR__ . '/config/',
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// rate_limit.php — Load directly without relying on Composer autoload_files
// (works even if composer dump-autoload hasn't been run on the server)
require_once __DIR__ . '/config/helpers/rate_limit.php';

// Database connection ($pdo global)
require_once __DIR__ . '/config/database.php';

// Initialize Logger singleton with PDO
if (isset($pdo) && $pdo instanceof PDO) {
    Logger::getInstance()->init($pdo);
}
