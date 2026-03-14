<?php
// config/helpers/security.php
// CSRF Protection and Authentication

/**
 * Generate CSRF token (stored in session)
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from request
 * NOTE: Token is NOT rotated after success — this is intentional.
 * Rotation breaks concurrent AJAX calls (e.g., dashboard loading multiple stats).
 * The token is per-session and cryptographically random, which is sufficient.
 */
function verifyCsrfToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        writeLog("Security Alert: CSRF token verification failed.");
        return false;
    }
    return true;
}

/**
 * Check if user is authenticated, redirect to login if not
 */
function checkAuthentication(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $currentPage = basename($_SERVER['PHP_SELF']);

    if ($currentPage === 'login.php') {
        return;
    }

    if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}
