<?php
// config/helpers/logging.php
// Centralized logging — delegates to Logger singleton
// This file provides backward-compatible writeLog() function

/**
 * Write log entry — delegates to Logger singleton.
 * Kept as a function for backward compatibility with 200+ callsites.
 * 
 * @param string $message Log message
 * @param string $level   Log level: DEBUG, INFO, WARNING, ERROR, CRITICAL
 * @param string $module  Module name for filtering (e.g., 'sync', 'zoho', 'parasut')
 * @param array|null $context Additional context data as JSON
 */
function writeLog(string $message, string $level = 'INFO', string $module = 'general', ?array $context = null): void
{
    Logger::getInstance()->write($message, $level, $module, $context);
}
