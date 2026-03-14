<?php
// config/helpers/http.php
// HTTP Response and Input Sanitization Utilities

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $error = json_last_error_msg();
        writeLog("JSON Encode Error: $error");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "JSON Oluşturma Hatası: $error"]);
        exit;
    }
    echo $json;
    exit;
}

/**
 * Sanitize user input for HTML display
 */
function sanitize(string $input): string
{
    return htmlspecialchars(strip_tags(trim($input)));
}

/**
 * Sanitize credentials/tokens — only trim, no HTML encoding
 */
function sanitizeCredential(string $input): string
{
    return trim($input);
}

/**
 * Enable long-running PHP mode for batch operations
 */
function enableLongRunningMode(int $memoryLimitMB = 256, int $timeLimitSec = 300): void
{
    @set_time_limit($timeLimitSec);
    @ini_set('memory_limit', $memoryLimitMB . 'M');
    @session_write_close();
}
