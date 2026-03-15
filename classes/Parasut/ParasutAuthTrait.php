<?php
/**
 * ParasutAuthTrait — OAuth2 token management for Paraşüt
 * 
 * Handles token caching, login flow, and DB persistence.
 * Required properties: $pdo, $clientId, $clientSecret, $username, $password, $companyId
 */
trait ParasutAuthTrait
{
    private function getAccessToken(): string
    {
        $token = getSetting($this->pdo, 'parasut_access_token');
        $expiresAt = getSetting($this->pdo, 'parasut_expires_at');

        if ($token && $expiresAt > time()) {
            return $token;
        }

        writeLog("Paraşüt access token expired or missing. Logging in...", 'INFO', 'parasut');
        return $this->login();
    }

    private function login(): string
    {
        if (!$this->clientId || !$this->clientSecret || !$this->username || !$this->password) {
            throw new ParasutAuthException(
                "Paraşüt eksik ayarlar! Lütfen Ayarlar sayfasından Paraşüt bağlantısını yapılandırın."
            );
        }

        $ch = curl_init('https://api.parasut.com/oauth/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $this->username,
            'password'      => $this->password,
            'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob',
        ]));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!isset($data['access_token'])) {
            $error = $data['error_description'] ?? $data['error'] ?? 'Bilinmeyen hata';
            throw new ParasutAuthException("Paraşüt giriş başarısız: $error");
        }

        $token = $data['access_token'];
        $expiresAt = time() + ($data['expires_in'] ?? 7200) - 60;

        updateSetting($this->pdo, 'parasut_access_token', $token);
        updateSetting($this->pdo, 'parasut_expires_at', $expiresAt);

        writeLog("Paraşüt login successful.", 'INFO', 'parasut');
        return $token;
    }
}
