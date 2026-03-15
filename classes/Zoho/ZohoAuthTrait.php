<?php
/**
 * ZohoAuthTrait — OAuth2 token management for Zoho CRM
 * 
 * Handles access token caching, refresh token flow, authorization code exchange,
 * and DB-level locking to prevent concurrent token refresh race conditions.
 * 
 * Required properties: $pdo, $clientId, $clientSecret, $refreshToken, $authUrl
 */
trait ZohoAuthTrait
{
    private function getAccessToken(): string
    {
        $token = getSetting($this->pdo, 'zoho_access_token');
        $expiresAt = getSetting($this->pdo, 'zoho_expires_at');

        if ($token && $expiresAt > time()) {
            return $token;
        }

        writeLog("Access Token expired or missing. Refreshing...", 'INFO', 'zoho');
        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        global $settingsCache;
        writeLog("Starting Refresh Access Token process.", 'DEBUG', 'zoho');

        if (!$this->clientId || !$this->clientSecret || !$this->refreshToken) {
            throw new ZohoAuthException(
                "Zoho eksik ayarlar! Lütfen Ayarlar sayfasından Zoho bağlantısını yapılandırın."
            );
        }

        // DB-level lock to prevent concurrent token refresh
        try {
            $lockStmt = $this->pdo->query("SELECT GET_LOCK('zoho_token_refresh', 10)");
            $lockStmt->closeCursor();
        } catch (\Exception $e) {
            sleep(2);
            $settingsCache = null;
            $token = getSetting($this->pdo, 'zoho_access_token');
            $expiresAt = getSetting($this->pdo, 'zoho_expires_at');
            if ($token && $expiresAt > time()) {
                return $token;
            }
        }

        try {
            $settingsCache = null;
            $token = getSetting($this->pdo, 'zoho_access_token');
            $expiresAt = getSetting($this->pdo, 'zoho_expires_at');

            if ($token && $expiresAt > time()) {
                $this->pdo->query("SELECT RELEASE_LOCK('zoho_token_refresh')")->closeCursor();
                return $token;
            }

            $response = $this->makeTokenRequest([
                'refresh_token' => $this->refreshToken,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'refresh_token',
            ]);

            if (!isset($response['access_token'])) {
                $error = $response['error'] ?? 'unknown';
                throw new ZohoAuthException("Token yenileme başarısız: $error");
            }

            $newToken = $response['access_token'];
            $expiresAt = time() + ($response['expires_in'] ?? 3600) - 60;

            updateSetting($this->pdo, 'zoho_access_token', $newToken);
            updateSetting($this->pdo, 'zoho_expires_at', $expiresAt);

            writeLog("Access Token refreshed successfully.", 'INFO', 'zoho');
            return $newToken;

        } finally {
            try {
                $this->pdo->query("SELECT RELEASE_LOCK('zoho_token_refresh')")->closeCursor();
            } catch (\Exception $e) {}
        }
    }

    /**
     * Exchange an authorization code for access + refresh tokens
     * @return array{success: bool, error?: string}
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        writeLog("Exchanging Authorization Code for tokens...", 'INFO', 'zoho');

        if (!$this->clientId || !$this->clientSecret) {
            return ['success' => false, 'error' => 'Client ID ve Client Secret gerekli.'];
        }

        $response = $this->makeTokenRequest([
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'authorization_code',
        ]);

        if (isset($response['access_token'])) {
            updateSetting($this->pdo, 'zoho_access_token', $response['access_token']);
            updateSetting($this->pdo, 'zoho_refresh_token', $response['refresh_token'] ?? null);
            updateSetting($this->pdo, 'zoho_expires_at', time() + ($response['expires_in'] ?? 3600) - 60);
            $this->refreshToken = $response['refresh_token'] ?? null;

            writeLog("Authorization Code exchange SUCCESS.", 'INFO', 'zoho');
            return ['success' => true];
        }

        $errorMsg = $response['error'] ?? 'Bilinmeyen Hata';
        return ['success' => false, 'error' => "$errorMsg: " . ($response['error_description'] ?? '')];
    }

    private function makeTokenRequest(array $fields): array
    {
        $ch = curl_init($this->authUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result, true) ?? [];
    }
}
