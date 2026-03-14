<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for security helper functions
 */
class SecurityHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure session is started for CSRF tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Clear CSRF token between tests
        unset($_SESSION['csrf_token']);
    }

    /**
     * Test CSRF token generation
     */
    public function testCsrfTokenGeneration(): void
    {
        $token = generateCsrfToken();

        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    /**
     * Test CSRF token is consistent within same session
     */
    public function testCsrfTokenConsistency(): void
    {
        $token1 = generateCsrfToken();
        $token2 = generateCsrfToken();

        $this->assertEquals($token1, $token2);
    }

    /**
     * Test CSRF token verification with valid token
     */
    public function testCsrfVerificationValid(): void
    {
        $token = generateCsrfToken();

        $this->assertTrue(verifyCsrfToken($token));
    }

    /**
     * Test CSRF token verification with invalid token
     */
    public function testCsrfVerificationInvalid(): void
    {
        generateCsrfToken();

        $this->assertFalse(verifyCsrfToken('invalid_token'));
    }

    /**
     * Test CSRF token verification with empty token
     */
    public function testCsrfVerificationEmpty(): void
    {
        generateCsrfToken();

        $this->assertFalse(verifyCsrfToken(''));
    }
}
