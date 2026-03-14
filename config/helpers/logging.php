<?php
// config/helpers/logging.php
// Centralized logging — database with file fallback

/**
 * Write log entry to database (primary) or file (fallback)
 * Includes automatic log rotation to prevent unbounded growth.
 * 
 * @param string $message Log message
 * @param string $level   Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL
 * @param string $module  Module name for filtering (e.g., 'sync', 'zoho', 'parasut')
 * @param array|null $context Additional context data as JSON
 */
function writeLog(string $message, string $level = 'INFO', string $module = 'general', ?array $context = null): void
{
    global $pdo;

    // Mask sensitive tokens in both URL params and JSON format
    $message = preg_replace(
        '/(access_token|refresh_token|client_secret|user_password|password)(["\':\s=]+)["\'"]?([a-zA-Z0-9\._\-\/]{6,})["\'"]?/',
        '$1$2***',
        $message
    );

    // Try database first
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO system_logs (level, module, message, context, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level,
                $module,
                $message,
                $context ? json_encode($context) : null,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            // Auto-rotate: ~1% chance per write to check and purge old logs
            if (mt_rand(1, 100) === 1) {
                _rotateDbLogs($pdo);
            }

            return;
        } catch (Exception $e) {
            // Fall back to file
        }
    }

    // File fallback
    $logFile = __DIR__ . '/../../logs/debug_log.txt';

    // Auto-rotate file: truncate if > 5MB
    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        // Keep last ~1MB of logs
        $content = file_get_contents($logFile);
        file_put_contents($logFile, substr($content, -1024 * 1024));
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [$level] [$module] $message" . PHP_EOL, FILE_APPEND);
}

/**
 * Purge old database logs to keep table size manageable.
 * Keeps most recent 30,000 rows when count exceeds 50,000.
 */
function _rotateDbLogs(PDO $pdo): void
{
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
        if ($count > 50000) {
            // Delete oldest entries, keep newest 30,000
            $pdo->exec("DELETE FROM system_logs WHERE id NOT IN (
                SELECT id FROM (SELECT id FROM system_logs ORDER BY id DESC LIMIT 30000) t
            )");
        }
    } catch (Exception $e) {
        // Silently ignore — rotation is best-effort
    }
}
