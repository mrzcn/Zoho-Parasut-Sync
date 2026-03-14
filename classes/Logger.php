<?php
// classes/Logger.php
// Singleton logger — replaces global $pdo dependency in writeLog()

class Logger
{
    private static ?Logger $instance = null;
    private ?PDO $pdo = null;
    
    /** @var string Minimum log level for database writes */
    private string $minLevel = 'DEBUG';

    private const LEVELS = [
        'DEBUG'    => 0,
        'INFO'     => 1,
        'WARNING'  => 2,
        'ERROR'    => 3,
        'CRITICAL' => 4,
    ];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize with PDO connection
     */
    public function init(PDO $pdo, string $minLevel = 'DEBUG'): void
    {
        $this->pdo = $pdo;
        $this->minLevel = strtoupper($minLevel);
    }

    /**
     * Set minimum log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
     */
    public function setMinLevel(string $level): void
    {
        $this->minLevel = strtoupper($level);
    }

    /**
     * Write a log entry
     */
    public function write(string $message, string $level = 'INFO', string $module = 'general', ?array $context = null): void
    {
        $level = strtoupper($level);

        // Skip if below minimum level
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$this->minLevel] ?? 0)) {
            return;
        }

        // Mask sensitive tokens
        $message = preg_replace(
            '/(access_token|refresh_token|client_secret|user_password|password)(["\':=\s]+)["\'"]?([a-zA-Z0-9\._\-\/]{6,})["\'"]?/',
            '$1$2***',
            $message
        );

        // Try database first
        if ($this->pdo instanceof PDO) {
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO system_logs (level, module, message, context, ip_address, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                );
                $stmt->execute([
                    $level,
                    $module,
                    $message,
                    $context ? json_encode($context) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                // Auto-rotate: ~1% chance per write
                if (mt_rand(1, 100) === 1) {
                    $this->rotateDbLogs();
                }

                return;
            } catch (\Exception $e) {
                // Fall through to file
            }
        }

        // File fallback
        $logFile = __DIR__ . '/../logs/debug_log.txt';

        if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            $content = file_get_contents($logFile);
            file_put_contents($logFile, substr($content, -1024 * 1024));
        }

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] [$level] [$module] $message" . PHP_EOL, FILE_APPEND);
    }

    /**
     * Convenience methods
     */
    public function debug(string $message, string $module = 'general'): void
    {
        $this->write($message, 'DEBUG', $module);
    }

    public function info(string $message, string $module = 'general'): void
    {
        $this->write($message, 'INFO', $module);
    }

    public function warning(string $message, string $module = 'general'): void
    {
        $this->write($message, 'WARNING', $module);
    }

    public function error(string $message, string $module = 'general'): void
    {
        $this->write($message, 'ERROR', $module);
    }

    private function rotateDbLogs(): void
    {
        try {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
            if ($count > 50000) {
                $this->pdo->exec("DELETE FROM system_logs WHERE id NOT IN (
                    SELECT id FROM (SELECT id FROM system_logs ORDER BY id DESC LIMIT 30000) t
                )");
            }
        } catch (\Exception $e) {
            // Best-effort
        }
    }
}
