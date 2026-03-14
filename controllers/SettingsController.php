<?php
// controllers/SettingsController.php

class SettingsController extends BaseController
{
    public function save_settings(): void
    {
        $fields = [
            'parasut_client_id',
            'parasut_client_secret',
            'parasut_username',
            'parasut_password',
            'parasut_company_id',
            'zoho_client_id',
            'zoho_client_secret',
            'zoho_refresh_token',
            'zoho_organization_id',
            'zoho_tld',
            'parasut_webhook_secret',
            'zoho_webhook_key',
            'turnstile_site_key',
            'turnstile_secret_key'
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                updateSetting($this->pdo, $field, sanitizeCredential($_POST[$field]));
            }
        }

        // Clear existing access tokens when settings change
        updateSetting($this->pdo, 'zoho_access_token', '');
        updateSetting($this->pdo, 'zoho_expires_at', '0');
        updateSetting($this->pdo, 'parasut_access_token', '');
        updateSetting($this->pdo, 'parasut_expires_at', '0');
        writeLog("Settings updated. Access Tokens cleared to force refresh.");

        jsonResponse(['success' => true, 'message' => 'Ayarlar başarıyla kaydedildi. Tokenlar sıfırlandı.']);
    }

    public function test_parasut(): void
    {
        $result = $this->parasut()->testConnection();
        jsonResponse(['success' => true, 'message' => 'Paraşüt bağlantısı başarılı!', 'data' => $result]);
    }

    public function test_zoho(): void
    {
        $result = $this->zoho()->testConnection();
        jsonResponse(['success' => true, 'message' => 'Zoho bağlantısı başarılı!', 'data' => $result]);
    }

    public function exchange_zoho_code(): void
    {
        $authCode = $this->input('authorization_code', '');
        if (empty($authCode)) {
            jsonResponse(['success' => false, 'message' => 'Authorization Code gerekli.'], 400);
        }

        $result = $this->zoho()->exchangeAuthorizationCode($authCode);

        if ($result['success']) {
            jsonResponse(['success' => true, 'message' => 'Zoho bağlantısı başarıyla kuruldu!']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Zoho Hatası: ' . $result['error']], 400);
        }
    }
}

