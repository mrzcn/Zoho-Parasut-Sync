<?php
// config/helpers/rate_limit.php
// Simple file-based rate limiter — no external dependencies

/**
 * Check if the current IP exceeds the rate limit
 * @param int $maxRequests  Maximum requests allowed in the window
 * @param int $windowSeconds  Time window in seconds
 * @param string $action  Optional action name for per-action limits
 * @return bool  true if rate limited (should block), false if allowed
 */
function isRateLimited(int $maxRequests = 60, int $windowSeconds = 60, string $action = ''): bool
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Use first IP if forwarded chain
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    $key = md5($ip . ($action ? ':' . $action : ''));
    $dir = __DIR__ . '/../../logs/rate_limit';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . $key . '.json';
    $now = time();

    // Read existing data
    $data = ['requests' => [], 'blocked_until' => 0];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $data = json_decode($raw, true) ?: $data;
        }
    }

    // Check if currently blocked
    if ($data['blocked_until'] > $now) {
        return true;
    }

    // Clean old requests outside the window
    $data['requests'] = array_filter($data['requests'], fn($t) => $t > ($now - $windowSeconds));
    $data['requests'] = array_values($data['requests']);

    // Check limit
    if (count($data['requests']) >= $maxRequests) {
        // Block for the window duration
        $data['blocked_until'] = $now + $windowSeconds;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    // Record this request
    $data['requests'][] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return false;
}

/**
 * Clean up old rate limit files (run periodically)
 */
function cleanRateLimitFiles(): void
{
    $dir = __DIR__ . '/../../logs/rate_limit';
    if (!is_dir($dir))
        return;

    $files = glob($dir . '/*.json');
    $cutoff = time() - 3600; // Delete files older than 1 hour

    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
