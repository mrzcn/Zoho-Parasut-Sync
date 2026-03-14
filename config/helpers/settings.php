<?php
// config/helpers/settings.php
// Settings management with key-value table (settings_kv)

$settingsCache = null;

/**
 * Get allowed settings keys (whitelist)
 */
function getAllowedSettingsKeys(): array
{
    return [
        'parasut_client_id',
        'parasut_client_secret',
        'parasut_username',
        'parasut_password',
        'parasut_company_id',
        'parasut_access_token',
        'parasut_refresh_token',
        'parasut_expires_at',
        'zoho_client_id',
        'zoho_client_secret',
        'zoho_refresh_token',
        'zoho_organization_id',
        'zoho_access_token',
        'zoho_expires_at',
        'zoho_tld',
        'user_password',
        'cron_last_run',
        'cron_secret_key',
        'last_sync_zoho_products',
        'last_sync_parasut_products',
        'zoho_tax_map',
        'parasut_webhook_secret',
        'zoho_webhook_key',
        'turnstile_site_key',
        'turnstile_secret_key',
    ];
}

/**
 * Get a setting value with request-scoped caching
 * Supports both settings_kv (new) and settings (legacy) tables
 */
function getSetting(PDO $pdo, string $key): ?string
{
    global $settingsCache;

    if (!in_array($key, getAllowedSettingsKeys())) {
        writeLog("Security Warning: Attempted to get invalid setting key: $key");
        return null;
    }

    if ($settingsCache === null) {
        $settingsCache = [];
        try {
            // Try new key-value table first
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings_kv");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            // Fallback to old settings table
            try {
                $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
                $settingsCache = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e2) {
                $settingsCache = [];
            }
        }
    }

    return $settingsCache[$key] ?? null;
}

/**
 * Update a setting value and refresh cache
 * Supports both settings_kv (new) and settings (legacy) tables
 */
function updateSetting(PDO $pdo, string $key, $value): bool
{
    global $settingsCache;
    $value = (string) $value; // Ensure string for DB storage

    if (!in_array($key, getAllowedSettingsKeys())) {
        writeLog("Security Warning: Attempted to update invalid setting key: $key");
        return false;
    }

    try {
        // Try new key-value table first
        $stmt = $pdo->prepare("INSERT INTO settings_kv (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $result = $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        // Fallback to old settings table
        $safeKey = '`' . str_replace('`', '', $key) . '`';
        $stmt = $pdo->prepare("UPDATE settings SET $safeKey = :value WHERE id = 1");
        $result = $stmt->execute([':value' => $value]);
    }

    if ($result && $settingsCache !== null) {
        $settingsCache[$key] = $value;
    }

    return $result;
}
