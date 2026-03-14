<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for settings helper functions (whitelist validation)
 */
class SettingsHelperTest extends TestCase
{
    /**
     * Test that ALLOWED_SETTINGS contains expected keys
     */
    public function testAllowedSettingsContainsRequiredKeys(): void
    {
        // Load settings helper if not already loaded
        if (!defined('SETTINGS_LOADED')) {
            require_once __DIR__ . '/../../config/helpers/settings.php';
        }

        $requiredKeys = [
            'parasut_client_id',
            'parasut_client_secret',
            'parasut_username',
            'parasut_password',
            'zoho_client_id',
            'zoho_client_secret',
            'zoho_refresh_token',
        ];

        $reflection = new ReflectionFunction('getSetting');
        $source = file_get_contents($reflection->getFileName());

        foreach ($requiredKeys as $key) {
            $this->assertStringContainsString(
                "'$key'",
                $source,
                "ALLOWED_SETTINGS should contain '$key'"
            );
        }
    }

    /**
     * Test sanitize function escapes HTML
     */
    public function testSanitizeFunction(): void
    {
        // sanitize is defined in security.php or settings.php
        if (function_exists('sanitize')) {
            $input = '<script>alert("xss")</script>';
            $output = sanitize($input);

            $this->assertStringNotContainsString('<script>', $output);
            $this->assertStringContainsString('&lt;script&gt;', $output);
        } else {
            $this->markTestSkipped('sanitize() function not available');
        }
    }
}
