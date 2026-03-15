<?php
// classes/ZohoService.php

class ZohoService
{
    private $pdo;
    private $clientId;
    private $clientSecret;
    private $refreshToken;
    private $organizationId;
    private $tld = 'com';
    private $authUrl;
    private $baseUrl;

    // In-memory caches to avoid duplicate API calls within the same request
    private array $productCache = [];      // code => product data
    private array $productNameCache = [];  // name => product data
    private array $accountCache = [];      // name => account data

    // Public getters for external scripts that need direct API access
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }
    public function getPublicAccessToken()
    {
        return $this->getAccessToken();
    }

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->clientId = getSetting($pdo, 'zoho_client_id');
        $this->clientSecret = getSetting($pdo, 'zoho_client_secret');
        $this->refreshToken = getSetting($pdo, 'zoho_refresh_token');
        $this->organizationId = getSetting($pdo, 'zoho_organization_id');

        // setting check
        $dbTld = getSetting($pdo, 'zoho_tld');
        if ($dbTld) {
            $this->tld = $dbTld;
        }

        $this->authUrl = "https://accounts.zoho.{$this->tld}/oauth/v2/token";
        // Zoho CRM API Base URL (v3)
        $this->baseUrl = "https://www.zohoapis.{$this->tld}/crm/v3";

        writeLog("ZohoService Initialized. TLD: {$this->tld}", 'DEBUG', 'zoho');
    }

    private function getAccessToken()
    {
        $token = getSetting($this->pdo, 'zoho_access_token');
        $expiresAt = getSetting($this->pdo, 'zoho_expires_at');

        if ($token && $expiresAt > time()) {
            // writeLog("Using existing valid Access Token.");
            return $token;
        }

        writeLog("Access Token expired or missing (ExpiresAt: $expiresAt). Refreshing...");
        return $this->refreshAccessToken();
    }

    private function refreshAccessToken()
    {
        global $settingsCache;
        writeLog("Starting Refresh Access Token process.");

        if (!$this->clientId || !$this->clientSecret || !$this->refreshToken) {
            writeLog("Error: Missing credentials for Refresh. clientId=" . (!empty($this->clientId) ? 'SET' : 'EMPTY') . ", refreshToken=" . (!empty($this->refreshToken) ? 'SET' : 'EMPTY'));
            throw new Exception("Zoho eksik ayarlar! Lütfen Ayarlar sayfasından Zoho bağlantısını yapılandırın.");
        }

        // DB-level lock to prevent concurrent token refresh race conditions
        try {
            $lockStmt = $this->pdo->query("SELECT GET_LOCK('zoho_token_refresh', 10)");
            $lockStmt->closeCursor(); // CRITICAL: consume result set to avoid PDO error 2014
            writeLog("Token refresh lock acquired.");
        } catch (\Exception $e) {
            // Lock not available — another process is refreshing. Wait and re-check.
            writeLog("Token refresh lock busy, waiting 2s...");
            sleep(2);
            $settingsCache = null; // Force fresh DB read
            $token = getSetting($this->pdo, 'zoho_access_token');
            $expiresAt = getSetting($this->pdo, 'zoho_expires_at');
            if ($token && $expiresAt > time()) {
                writeLog("Token refreshed by another process. ExpiresAt: $expiresAt");
                return $token; // Another process already refreshed
            }
        }

        try {
            // Force fresh DB read — clear stale cache
            $settingsCache = null;

            // Double-check: maybe another process refreshed while we waited for the lock
            $token = getSetting($this->pdo, 'zoho_access_token');
            $expiresAt = getSetting($this->pdo, 'zoho_expires_at');
            writeLog("Token double-check: ExpiresAt=$expiresAt, now=" . time() . ", valid=" . ($expiresAt > time() ? 'YES' : 'NO'));

            if ($token && $expiresAt > time()) {
                writeLog("Token already valid after lock, skipping refresh.");
                return $token;
            }

            $postData = [
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token'
            ];

            writeLog("Attempting grant_type=refresh_token");

            try {
                $response = $this->makeTokenRequest($postData);
            } catch (\Exception $e) {
                writeLog("Token HTTP request FAILED: " . $e->getMessage());
                throw $e;
            }

            writeLog("Token refresh response: " . json_encode(array_keys($response)));

            if (isset($response['access_token'])) {
                $accessToken = $response['access_token'];
                $expiresIn = $response['expires_in'];
                $expiresAt = time() + $expiresIn - 60;

                $r1 = updateSetting($this->pdo, 'zoho_access_token', $accessToken);
                $r2 = updateSetting($this->pdo, 'zoho_expires_at', $expiresAt);

                writeLog("Token obtained successfully. Expires in $expiresIn seconds. DB write: token=$r1, expires=$r2, NewExpiresAt=$expiresAt");
                return $accessToken;
            }

            writeLog("Token Refresh FAILED details: " . json_encode($response));

            $errorMsg = $response['error'] ?? 'Bilinmeyen Hata';
            if ($errorMsg === 'invalid_token' || $errorMsg === 'invalid_code') {
                throw new Exception("Zoho Token Geçersiz! Lütfen Ayarlar sayfasından yeni bir Authorization Code ile bağlantıyı yeniden kurun.");
            }

            throw new Exception("Zoho Token Hatası: " . $errorMsg);
        } finally {
            try {
                $relStmt = $this->pdo->query("SELECT RELEASE_LOCK('zoho_token_refresh')");
                $relStmt->closeCursor(); // Consume result set
            } catch (\Exception $e) {
                // Ignore lock release failure
            }
        }
    }

    /**
     * Exchange a one-time Authorization Code for Access Token and Refresh Token.
     * This is used during initial setup from the Settings page.
     */
    public function exchangeAuthorizationCode($code)
    {
        writeLog("Exchanging Authorization Code for tokens...");

        if (!$this->clientId || !$this->clientSecret) {
            return ['success' => false, 'error' => 'Client ID ve Client Secret gerekli.'];
        }

        $postData = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code'
        ];

        $response = $this->makeTokenRequest($postData);

        if (isset($response['access_token'])) {
            $accessToken = $response['access_token'];
            $refreshToken = $response['refresh_token'] ?? null;
            $expiresAt = time() + ($response['expires_in'] ?? 3600) - 60;

            updateSetting($this->pdo, 'zoho_access_token', $accessToken);
            updateSetting($this->pdo, 'zoho_refresh_token', $refreshToken);
            updateSetting($this->pdo, 'zoho_expires_at', $expiresAt);

            // Update instance variable
            $this->refreshToken = $refreshToken;

            writeLog("Authorization Code exchange SUCCESS. Refresh token saved.");
            return ['success' => true];
        }

        $errorMsg = $response['error'] ?? 'Bilinmeyen Hata';
        $errorDesc = $response['error_description'] ?? '';
        writeLog("Authorization Code exchange FAILED: $errorMsg - $errorDesc");

        return ['success' => false, 'error' => "$errorMsg: $errorDesc"];
    }

    private function makeTokenRequest($fields)
    {
        writeLog("Requesting Token from: " . $this->authUrl);
        $ch = curl_init($this->authUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        writeLog("Token Response [$httpCode]: " . substr($result, 0, 100) . "...");
        return json_decode($result, true);
    }

    // Log API metrics to database (best-effort, never throws)
    private function logApiMetric(string $service, string $method, string $endpoint, ?int $httpCode, ?int $durationMs, bool $isRetry = false, ?string $error = null): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO api_metrics (service, method, endpoint, http_code, duration_ms, is_retry, error_message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$service, $method, substr($endpoint, 0, 500), $httpCode, $durationMs, $isRetry ? 1 : 0, $error ? substr($error, 0, 500) : null]);
        } catch (\Exception $e) {
            // Table might not exist yet — silently ignore
        }
    }

    public function request($method, $endpoint, $data = [], $params = [])
    {
        writeLog("API Request [$method] $endpoint", 'DEBUG', 'zoho');
        $token = $this->getAccessToken();
        $requestStartTime = microtime(true);

        $queryString = '';
        if (!empty($params)) {
            $queryString = '?' . http_build_query($params);
        }

        $url = $this->baseUrl . $endpoint . $queryString;
        writeLog("Full URL: $url", 'DEBUG', 'zoho');

        $ch = curl_init($url);
        $headers = [
            'Authorization: Zoho-oauthtoken ' . $token,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);  // 10s connection timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);          // 30s total timeout

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $maxRetries = 3;
        $attempt = 0;
        $httpCode = 0;
        $response = false;

        while ($attempt <= $maxRetries) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // CURL transport error (timeout, DNS, connection refused, etc.)
            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
                $curlErrNo = curl_errno($ch);
                writeLog("CURL ERROR #$curlErrNo: $curlError (attempt $attempt/$maxRetries)", 'ERROR', 'zoho');

                if ($attempt < $maxRetries) {
                    $attempt++;
                    $sleepTime = pow(2, $attempt);
                    writeLog("Retrying in {$sleepTime}s...", 'WARNING', 'zoho');
                    sleep($sleepTime);
                    continue;
                }

                curl_close($ch);
                throw new Exception("Zoho Bağlantı Hatası ($maxRetries deneme sonrası): $curlError");
            }

            // Zoho Rate Limit (429) Handling
            if ($httpCode === 429 && $attempt < $maxRetries) {
                $attempt++;
                $sleepTime = pow(2, $attempt); // 2, 4, 8 seconds
                writeLog("Zoho RATE LIMIT (429) hit. Retrying in {$sleepTime}s (Attempt $attempt of $maxRetries)...", 'WARNING', 'zoho');
                sleep($sleepTime);
                continue;
            }

            break; // Success or non-retryable error
        }

        // Log the response if it's an error (HTTP status code >= 400)
        if ($httpCode >= 400) {
            if ($httpCode === 429) {
                writeLog("Zoho API RATE LIMIT (429) EXCEEDED after $maxRetries retries: " . $response, 'ERROR', 'zoho');
            } else {
                writeLog("Zoho API Error ($httpCode): " . $response, 'ERROR', 'zoho');
            }
        }

        curl_close($ch);

        writeLog("API Response [$httpCode]", 'DEBUG', 'zoho');

        // Log API metrics (duration from start of first attempt)
        $durationMs = (int) ((microtime(true) - $requestStartTime) * 1000);
        $this->logApiMetric('zoho', $method, $endpoint, $httpCode, $durationMs, $attempt > 0);

        $result = json_decode($response, true);

        if ($result === null && !empty($response)) {
            writeLog("JSON Decode Error. Raw: " . $response);
            throw new Exception("Zoho API Yanıtı Anlaşılamadı: " . substr($response, 0, 200));
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return $result;
        }

        writeLog("API Failure: " . json_encode($result));

        if (isset($result['code']) && $result['code'] === 'OAUTH_SCOPE_MISMATCH') {
            throw new Exception("YETKİ HATASI: Mevcut Refresh Token, Zoho CRM erişimine izin vermiyor. Muhtemelen eski (Inventory) tokenı kullanıyorsunuz. Lütfen Zoho API Console'dan 'ZohoCRM.modules.ALL' yetkisiyle YENİ bir Self-Client Code oluşturup Ayarlar sayfasında Refresh Token alanına yapıştırın ve Kaydedin.");
        }

        // Handle "Inactive Product" error
        if (isset($result['data'][0]['code']) && $result['data'][0]['code'] === 'NOT_ALLOWED' && strpos($result['data'][0]['message'], 'inactive') !== false) {
            throw new Exception("ÜRÜN HATASI: Zoho'da eklemeye çalıştığınız ürün PASİF (Inactive) durumda. Lütfen Zoho CRM'de ilgili ürünü bulup aktifleştirin ve tekrar deneyin.");
        }

        throw new Exception("Zoho API Hatası ($httpCode): " . ($result['message'] ?? json_encode($result)));
    }

    public function testConnection()
    {
        writeLog("Testing Connection (fetching 1 product)...");
        // Zoho CRM v3 requires 'fields' in some orgs or purely better for performance.
        return $this->request('GET', '/Products?per_page=1&fields=id,Product_Name,Product_Code');
    }

    public function updateProduct($id, $data)
    {
        writeLog("Updating Product ID: $id with data: " . json_encode($data));
        // Zoho CRM Update: Requires 'data' array wrapper
        $payload = [
            'data' => [$data]
        ];

        // CRM v3 uses PUT to /Products/{id}
        return $this->request('PUT', "/Products/$id", $payload);
    }

    /**
     * Get all org-level tax names (cached)
     */
    private array $orgTaxNamesCache = [];
    public function getOrgTaxNames(): array
    {
        if (!empty($this->orgTaxNamesCache)) {
            return $this->orgTaxNamesCache;
        }
        try {
            // Zoho CRM v3: /org returns org details including taxes
            $response = $this->request('GET', '/org');
            writeLog("Org response keys: " . implode(', ', array_keys($response ?? [])));

            // Try multiple paths for tax data
            $taxes = [];
            if (isset($response['org']) && is_array($response['org'])) {
                foreach ($response['org'] as $orgData) {
                    if (isset($orgData['tax_details'])) {
                        $taxes = $orgData['tax_details'];
                        break;
                    }
                }
            }
            if (empty($taxes)) {
                $taxes = $response['org_taxes']['taxes'] ?? $response['taxes'] ?? $response['tax_details'] ?? [];
            }

            if (!empty($taxes)) {
                $this->orgTaxNamesCache = array_map(fn($t) => $t['name'] ?? $t['display_label'] ?? '', $taxes);
                $this->orgTaxNamesCache = array_filter($this->orgTaxNamesCache);
            }

            // If still empty, try dedicated settings endpoint
            if (empty($this->orgTaxNamesCache)) {
                writeLog("Org taxes empty from /org, trying Settings/taxes...");
                try {
                    $taxResp = $this->request('GET', '/settings/taxes');
                    $taxData = $taxResp['taxes'] ?? [];
                    if (!empty($taxData)) {
                        $this->orgTaxNamesCache = array_map(fn($t) => $t['name'] ?? $t['display_label'] ?? '', $taxData);
                        $this->orgTaxNamesCache = array_filter($this->orgTaxNamesCache);
                    }
                } catch (\Exception $e2) {
                    writeLog("Settings/taxes also failed: " . $e2->getMessage());
                }
            }

            writeLog("Org taxes loaded: " . implode(', ', $this->orgTaxNamesCache));
        } catch (\Exception $e) {
            writeLog("Failed to load org taxes: " . $e->getMessage());
        }

        // Fallback to known KDV rates if API calls fail
        if (empty($this->orgTaxNamesCache)) {
            writeLog("Using fallback KDV tax names.");
            $this->orgTaxNamesCache = ['KDV 20', 'KDV 18', 'KDV 10', 'KDV 8', 'KDV 1', 'KDV 0'];
        }
        return $this->orgTaxNamesCache;
    }

    /**
     * Ensure a product has all org taxes assigned
     */
    public function ensureProductTaxes(string $productId): bool
    {
        try {
            // Load tax ID map (same source as createInvoice)
            $taxMapJson = getSetting($this->pdo, 'zoho_tax_map');
            $taxIdMap = $taxMapJson ? json_decode($taxMapJson, true) : [];

            // Fallback: tax map must be configured in Settings → Zoho Tax Map
            if (empty($taxIdMap)) {
                writeLog("WARNING: zoho_tax_map not configured in Settings! Tax assignment skipped.");
                return false;
            }

            // Build Tax objects with id, name, and percentage (Zoho CRM v3 required format)
            $taxObjects = [];
            foreach ($taxIdMap as $rate => $taxId) {
                $taxObjects[] = [
                    'id' => $taxId,
                    'name' => 'KDV ' . $rate,
                    'percentage' => (float) $rate
                ];
            }

            writeLog("Auto-fixing taxes for product $productId: " . json_encode($taxObjects));
            $this->updateProduct($productId, ['Tax' => $taxObjects]);
            return true;
        } catch (\Exception $e) {
            writeLog("Failed to fix product taxes for $productId: " . $e->getMessage());
            return false;
        }
    }

    public function createProduct($productData)
    {
        writeLog("Creating Product in Zoho: " . json_encode($productData));

        $itemData = [
            'Product_Name' => $productData['name'],
            'Product_Code' => $productData['code'],
            'Unit_Price' => round((float) $productData['price'], 2)
        ];

        // Add Tax if provided
        $taxId = null;
        if (isset($productData['vat_rate'])) {
            // Tax ID mapping (From Database)
            $taxMapJson = getSetting($this->pdo, 'zoho_tax_map');
            $taxMap = $taxMapJson ? json_decode($taxMapJson, true) : [];

            // Tax map must be configured in Settings
            if (empty($taxMap)) {
                writeLog("WARNING: zoho_tax_map not configured. Tax will not be applied to new product.");
            }

            $rate = (string) intval($productData['vat_rate']);
            if (isset($taxMap[$rate])) {
                $taxId = $taxMap[$rate];
            }
        }

        if ($taxId) {
            $itemData['Tax'] = [
                ['id' => $taxId]
            ];
        }

        $data = [
            'data' => [$itemData]
        ];

        $response = $this->request('POST', '/Products', $data);

        // --- VERIFICATION: Fetch created product to check Tax (Optional diagnostic) ---
        try {
            if (isset($response['data'][0]['details']['id'])) {
                $newId = $response['data'][0]['details']['id'];
                $check = $this->request('GET', '/Products/' . $newId);
                writeLog("VERIFICATION - CREATED PRODUCT DETAILS: " . json_encode($check));
            }
        } catch (Exception $ve) {
            writeLog("Verification Fetch Warning: " . $ve->getMessage());
        }
        // --------------------------------------------------------

        // Handle Duplicate Error gracefully
        if (isset($response['data'][0]['code']) && $response['data'][0]['code'] === 'DUPLICATE_DATA') {
            if (isset($response['data'][0]['details']['duplicate_record']['id'])) {
                writeLog("Product exists (duplicate). Using ID: " . $response['data'][0]['details']['duplicate_record']['id']);
                // Mimic success structure so caller can use it
                return [
                    'data' => [
                        [
                            'status' => 'success', // Fake success for logic continuity
                            'details' => ['id' => $response['data'][0]['details']['duplicate_record']['id']]
                        ]
                    ]
                ];
            }
        }

        return $response;
    }


    // --- Helper Methods for Sync ---

    public function searchAccount($name, $phone = null, $email = null, $website = null)
    {
        // Escape special characters for Zoho Criteria (parentheses)
        $safeName = str_replace(['(', ')'], ['\\(', '\\)'], $name);

        // Search by Account_Name, Phone, Email, or Website
        $criteria = "((Account_Name:equals:" . urlencode($safeName) . ")";
        if ($phone) {
            $safePhone = str_replace(['(', ')'], ['\\(', '\\)'], $phone);
            $criteria .= " or (Phone:equals:" . urlencode($safePhone) . ")";
        }
        if ($email) {
            $safeEmail = str_replace(['(', ')'], ['\\(', '\\)'], $email);
            $criteria .= " or (Email:equals:" . urlencode($safeEmail) . ")";
        }
        if ($website) {
            $safeWebsite = str_replace(['(', ')'], ['\\(', '\\)'], $website);
            $criteria .= " or (Website:equals:" . urlencode($safeWebsite) . ")";
        }
        $criteria .= ")";

        $response = $this->request('GET', "/Accounts/search?criteria=$criteria");

        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    public function getAccounts($page = 1)
    {
        return $this->request('GET', "/Accounts?fields=id,Account_Name,Phone,Website&page=$page&per_page=100");
    }

    public function searchAccountByPhone($phone)
    {
        // Search by Phone
        $safePhone = str_replace(['(', ')'], ['\\(', '\\)'], $phone);
        $endpoint = "/Accounts/search?criteria=(Phone:equals:" . urlencode($safePhone) . ")";
        $response = $this->request('GET', $endpoint);
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    public function searchAccountByWebsite($website)
    {
        // Search by Website - use 'contains' to match domain regardless of http/https
        // Clean the website to get just the domain
        $domain = preg_replace('#^https?://#', '', $website);
        $domain = rtrim($domain, '/');

        // Skip if domain is too short or empty
        if (strlen($domain) < 3) {
            return null;
        }

        // Use 'equals' as 'contains' is not supported for Website field in API v3 COQL/Criteria usually
        $endpoint = "/Accounts/search?criteria=(Website:equals:" . urlencode($domain) . ")";
        try {
            $response = $this->request('GET', $endpoint);
            if (isset($response['data']) && count($response['data']) > 0) {
                return $response['data'][0];
            }
        } catch (Exception $e) {
            // Website field might not be searchable, log and continue
            writeLog("Website search failed: " . $e->getMessage());
        }
        return null;
    }

    public function createAccount($name, $email, $phone = null, $extras = [])
    {
        $accountData = [
            'Account_Name' => $name,
            'Email' => $email
        ];

        // Only add phone if it's valid (not null/empty)
        if (!empty($phone)) {
            $accountData['Phone'] = $phone;
        }

        // Contact enrichment: tax number, tax office, address
        if (!empty($extras['tax_number'])) {
            $accountData['Tax_ID'] = $extras['tax_number'];
        }
        if (!empty($extras['tax_office'])) {
            $accountData['Description'] = 'Vergi Dairesi: ' . $extras['tax_office'];
        }
        if (!empty($extras['billing_street'])) {
            $accountData['Billing_Street'] = $extras['billing_street'];
        }
        if (!empty($extras['billing_city'])) {
            $accountData['Billing_City'] = $extras['billing_city'];
        }
        if (!empty($extras['billing_state'])) {
            $accountData['Billing_State'] = $extras['billing_state'];
        }
        if (!empty($extras['billing_country'])) {
            $accountData['Billing_Country'] = $extras['billing_country'];
        }
        if (!empty($extras['website'])) {
            $accountData['Website'] = $extras['website'];
        }

        $data = [
            'data' => [$accountData]
        ];
        $response = $this->request('POST', '/Accounts', $data);

        if (isset($response['data'][0]['details']['id'])) {
            return $response['data'][0]['details']['id'];
        }

        // Check for Duplicates
        if (isset($response['data'][0]['code']) && $response['data'][0]['code'] === 'DUPLICATE_DATA') {
            // Try to extract existing ID from duplicate_record details if available
            if (isset($response['data'][0]['details']['duplicate_record']['id'])) {
                writeLog("Account exists (duplicate). Using ID: " . $response['data'][0]['details']['duplicate_record']['id']);
                return $response['data'][0]['details']['duplicate_record']['id'];
            }
        }

        return null;
    }

    public function searchProduct($code)
    {
        $code = trim($code);
        if (empty($code))
            return null;

        // In-memory cache: avoid duplicate API calls for same product code
        if (isset($this->productCache[$code])) {
            return $this->productCache[$code];
        }

        $safeCode = str_replace(['(', ')'], ['\\(', '\\)'], $code);
        $endpoint = "/Products/search?criteria=(Product_Code:equals:" . urlencode($safeCode) . ")";
        $response = $this->request('GET', $endpoint);
        if (isset($response['data']) && count($response['data']) > 0) {
            $this->productCache[$code] = $response['data'][0];
            return $response['data'][0];
        }
        $this->productCache[$code] = null;
        return null;
    }

    public function searchProductByName($name)
    {
        $name = trim($name);
        if (empty($name))
            return null;

        $safeName = str_replace(['(', ')'], ['\\(', '\\)'], $name);
        $endpoint = "/Products/search?criteria=(Product_Name:equals:" . urlencode($safeName) . ")";
        $response = $this->request('GET', $endpoint);
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    /*
     * Generic Search for Product Check Page
     * Searches by ID, Code, or Name
     */
    public function searchProducts($query)
    {
        $query = trim($query);
        if (empty($query))
            return [];

        // 1. Try as ID if numeric
        if (is_numeric($query)) {
            try {
                // If it's a valid ID, GET /Products/{id} works best
                $result = $this->request('GET', "/Products/$query");
                if (isset($result['data'][0]['id'])) {
                    return $result['data']; // Zoho wraps single resource in data[]
                }
            } catch (Exception $e) {
                // Ignore errors (ID not found etc) and continue to criteria search
            }
        }

        // 2. Search by Product Code
        $safeQuery = str_replace(['(', ')'], ['\\(', '\\)'], $query);

        try {
            // Search by Code (Exact match prefered)
            $endpoint = "/Products/search?criteria=(Product_Code:equals:" . urlencode($safeQuery) . ")";
            $response = $this->request('GET', $endpoint);
            if (isset($response['data']) && !empty($response['data'])) {
                return $response['data'];
            }
        } catch (Exception $e) {
            // Ignore code search failure, continue to name
        }

        // 3. Search by Product Name (CONTAINS - Broadest Match, prioritized for User)
        // User explicitly asked for "like %QNB%" behavior
        try {
            $endpoint = "/Products/search?criteria=(Product_Name:contains:" . urlencode($safeQuery) . ")";
            $response = $this->request('GET', $endpoint);
            if (isset($response['data']) && !empty($response['data'])) {
                return $response['data'];
            }
        } catch (Exception $e) {
            // Ignore
        }

        // 4. Fallback: Search by Product Name (Starts With)
        try {
            $endpoint = "/Products/search?criteria=(Product_Name:starts_with:" . urlencode($safeQuery) . ")";
            $response = $this->request('GET', $endpoint);
            if (isset($response['data']) && !empty($response['data'])) {
                return $response['data'];
            }
        } catch (Exception $e) {
            // Ignore
        }

        return [];
    }

    public function createInvoice($subject, $accountId, $lineItems, $currency = 'TRY', $options = [])
    {
        $invoiceData = [
            'Subject' => $subject,
            'Account_Name' => ['id' => $accountId],
            'Currency' => $currency
        ];

        // Only add line items if provided (skip if empty to add them later via update)
        if (!empty($lineItems)) {
            $productDetails = [];

            // Tax ID mapping (From Database)
            $taxMapJson = getSetting($this->pdo, 'zoho_tax_map');
            $taxIdMap = $taxMapJson ? json_decode($taxMapJson, true) : [];

            // Tax map must be configured in Settings
            if (empty($taxIdMap)) {
                writeLog("WARNING: zoho_tax_map not configured. Invoice line items will have no tax.");
            }

            foreach ($lineItems as $item) {
                writeLog("Processing Line Item for Zoho: " . json_encode($item));
                $lineItemData = [
                    'Product_Name' => ['id' => $item['product_id']],
                    'Quantity' => (float) $item['quantity'],
                    'List_Price' => round((float) $item['price'], 2),
                ];

                // Determine VAT rate
                $vatRate = 0;
                if (!empty($item['tax_name'])) {
                    $vatRate = (int) preg_replace('/[^0-9]/', '', $item['tax_name']);
                }
                if ($vatRate == 0 && isset($item['vat_rate'])) {
                    $vatRate = (int) $item['vat_rate'];
                }

                // Add Line_Tax with id, name, and percentage (Zoho CRM v3 confirmed working format)
                if ($vatRate > 0 && isset($taxIdMap[(string) $vatRate])) {
                    $taxId = $taxIdMap[(string) $vatRate];
                    $taxName = "KDV " . $vatRate;  // Simple name format works!

                    $lineItemData['Line_Tax'] = [
                        [
                            'id' => $taxId,
                            'name' => $taxName,
                            'percentage' => (float) $vatRate
                        ]
                    ];
                    writeLog("Applied Line_Tax: id=$taxId, name=$taxName, percentage=$vatRate");
                }

                // Add Discount if provided
                if (!empty($item['discount']) && (float) $item['discount'] > 0) {
                    $lineItemData['Discount'] = round((float) $item['discount'], 2);
                }

                $productDetails[] = $lineItemData;
            } // End foreach
            $invoiceData['Invoiced_Items'] = $productDetails;
        }

        // Add Invoice Number if provided
        if (!empty($options['invoice_number'])) {
            $invoiceData['Invoice_Number'] = $options['invoice_number'];
        }

        // Add Invoice Date if provided (format: YYYY-MM-DD)
        if (!empty($options['invoice_date'])) {
            $invoiceData['Invoice_Date'] = $options['invoice_date'];
        }

        // Add Due Date if provided (format: YYYY-MM-DD)
        if (!empty($options['due_date'])) {
            $invoiceData['Due_Date'] = $options['due_date'];
        }

        // Exchange Rate
        if (!empty($options['exchange_rate'])) {
            $invoiceData['Exchange_Rate'] = (float) $options['exchange_rate'];
        }

        // Add Billing Address fields if provided
        if (!empty($options['billing_street'])) {
            $invoiceData['Billing_Street'] = $options['billing_street'];
        }
        if (!empty($options['billing_city'])) {
            $invoiceData['Billing_City'] = $options['billing_city'];
        }
        if (!empty($options['billing_state'])) {
            $invoiceData['Billing_State'] = $options['billing_state'];
        }
        if (!empty($options['billing_country'])) {
            $invoiceData['Billing_Country'] = $options['billing_country'];
        }

        // Add Description/Notes if provided
        if (!empty($options['description'])) {
            // Try both Description and Note fields
            $invoiceData['Description'] = $options['description'];
        }

        // Add Terms and Conditions if provided
        if (!empty($options['terms_and_conditions'])) {
            $invoiceData['Terms_and_Conditions'] = $options['terms_and_conditions'];
        }

        // Add Status if provided
        if (!empty($options['status'])) {
            $invoiceData['Status'] = $options['status'];
        }

        // Add Payment Status (Custom Field)
        if (!empty($options['payment_status'])) {
            $invoiceData['Payment_Status'] = $options['payment_status'];
        }

        // Add Parasut URL (Custom Field)
        if (!empty($options['parasut_url'])) {
            $invoiceData['Parasut_URL'] = $options['parasut_url'];
        }

        // Add Parasut PDF URL (Custom Field)
        if (!empty($options['parasut_pdf_url'])) {
            $invoiceData['Parasut_PDF_URL'] = $options['parasut_pdf_url'];
        }

        $data = [
            'data' => [$invoiceData]
        ];

        try {
            $response = $this->request('POST', '/Invoices', $data);
        } catch (\Exception $e) {
            // Auto-fix: "Given tax is not present in the corresponding product"
            if (strpos($e->getMessage(), 'tax is not present') !== false) {
                writeLog("Tax error detected — auto-fixing product taxes and retrying...");
                // Extract product IDs from line items and fix their taxes
                foreach ($invoiceData['Invoiced_Items'] ?? [] as $item) {
                    $pid = $item['Product_Name']['id'] ?? null;
                    if ($pid)
                        $this->ensureProductTaxes($pid);
                }
                // Retry once
                $response = $this->request('POST', '/Invoices', $data);
                writeLog("Invoice created after tax auto-fix ✅");
            } else {
                throw $e;
            }
        }

        return $response;
    }

    // Add a note to an existing Zoho record
    public function addNote($module, $recordId, $noteContent)
    {
        $data = [
            'data' => [
                [
                    '$se_module' => $module,
                    'Parent_Id' => $recordId,
                    'Note_Title' => 'Fatura Notları',
                    'Note_Content' => $noteContent
                ]
            ]
        ];

        writeLog("Adding Note to $module/$recordId: " . substr($noteContent, 0, 100) . "...");
        return $this->request('POST', '/Notes', $data);
    }

    // Update an existing invoice (e.g., to fix line item prices)
    public function updateInvoice($invoiceId, $lineItems)
    {
        $invoicedItems = [];

        foreach ($lineItems as $item) {
            $lineItemData = [
                'Product_Name' => ['id' => $item['product_id']],
                'Quantity' => (float) $item['quantity'],
                'List_Price' => round((float) $item['price'], 2)
            ];

            // Add Tax if provided
            $taxId = null;
            if (isset($item['tax_id'])) {
                $taxId = $item['tax_id'];
            } elseif (!empty($item['tax_name'])) {
                // Legacy support or if name passed
                $taxMapJson = getSetting($this->pdo, 'zoho_tax_map');
                $taxMap = $taxMapJson ? json_decode($taxMapJson, true) : [];

                if (empty($taxMap)) {
                    // Tax map not configured — skip tax assignment
                }

                // Extract number from tax name or use if it matches simple rate
                $rate = preg_replace('/[^0-9]/', '', $item['tax_name']);
                if (isset($taxMap[$rate])) {
                    $taxId = $taxMap[$rate];
                }
            }

            // Allow passing 'vat_rate' directly
            if (!$taxId && isset($item['vat_rate'])) {
                $taxMapJson = getSetting($this->pdo, 'zoho_tax_map');
                $taxMap = $taxMapJson ? json_decode($taxMapJson, true) : [];
                if (empty($taxMap)) {
                    // Tax map not configured — skip tax assignment
                }
                $rate = (string) intval($item['vat_rate']);
                if (isset($taxMap[$rate])) {
                    $taxId = $taxMap[$rate];
                }
            }

            if ($taxId) {
                $lineItemData['Tax'] = [
                    ['id' => $taxId]
                ];
            }

            $invoicedItems[] = $lineItemData;
        }

        $data = [
            'data' => [
                [
                    'Invoiced_Items' => $invoicedItems
                ]
            ]
        ];

        return $this->request('PUT', "/Invoices/$invoiceId", $data);
    }

    // --- Bulk Deletion Methods ---

    /**
     * Get IDs of Recent Invoices
     */
    public function getInvoiceIds($limit = 50)
    {
        // per_page max is usually 200
        $limit = min($limit, 200);
        $endpoint = "/Invoices?fields=id&page=1&per_page=$limit&sort_order=desc&sort_by=Created_Time";
        $response = $this->request('GET', $endpoint);

        $ids = [];
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $invoice) {
                if (isset($invoice['id'])) {
                    $ids[] = $invoice['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * Mass Delete Invoices
     * @param array $ids List of Invoice IDs
     */
    public function massDeleteInvoices(array $ids)
    {
        if (empty($ids))
            return ['success' => true, 'count' => 0];

        // Zoho allows deleting up to 100 IDs in request via 'ids' parameter
        $idString = implode(',', $ids);
        $endpoint = "/Invoices?ids=$idString";

        writeLog("Mass Deleting Invoices: " . count($ids) . " records.");

        try {
            // DELETE method only returns statuses for each
            $response = $this->request('DELETE', $endpoint);

            // Analyze response
            $successCount = 0;
            if (isset($response['data'])) {
                foreach ($response['data'] as $res) {
                    if (isset($res['status']) && ($res['status'] === 'success' || $res['code'] === 'SUCCESS')) {
                        $successCount++;
                    }
                }
            }
            return ['success' => true, 'count' => $successCount];

        } catch (Exception $e) {
            writeLog("Mass Delete Failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // List Invoices from Zoho - Simple single-page fetcher
    // Returns one batch at a time. Use page_token for pagination beyond 2000 records.
    public function getInvoices($page = 1, $perPage = 200, $pageToken = null)
    {
        $fields = "id,Invoice_Number,Invoice_Date,Grand_Total,Currency,Subject,Status,Description,Account_Name";

        if ($pageToken) {
            // Token-based pagination (for records beyond 2000)
            $endpoint = "/Invoices?fields=$fields&per_page=$perPage&page_token=$pageToken";
        } else {
            // Simple page-based pagination (for first 2000 records)
            $endpoint = "/Invoices?fields=$fields&page=$page&per_page=$perPage";
        }

        $response = $this->request('GET', $endpoint);

        // Return the response with pagination info intact
        return [
            'data' => $response['data'] ?? [],
            'info' => $response['info'] ?? [],
            'next_page_token' => $response['info']['next_page_token'] ?? null,
            'more_records' => $response['info']['more_records'] ?? false
        ];
    }

    public function updateInvoiceStatus($invoiceId, $status)
    {
        writeLog("Updating Zoho Invoice status: $invoiceId -> $status");
        $data = [
            'data' => [
                [
                    'id' => $invoiceId,
                    'Status' => $status
                ]
            ]
        ];
        return $this->request('PUT', '/Invoices', $data);
    }

    /**
     * Update arbitrary fields on a Zoho Invoice (for custom fields like E_Fatura_No, Payment_Status)
     */
    public function updateInvoiceFields($invoiceId, $fields)
    {
        $data = [
            'data' => [
                array_merge(['id' => $invoiceId], $fields)
            ]
        ];
        writeLog("Updating Zoho Invoice fields: $invoiceId -> " . json_encode($fields));
        return $this->request('PUT', '/Invoices', $data);
    }

    /**
     * Get full invoice with line items for Zoho → Parasut export
     */
    public function getInvoiceWithLineItems($invoiceId)
    {
        $fields = "id,Invoice_Number,Invoice_Date,Due_Date,Grand_Total,Currency,Subject,Status,Description,Account_Name,Invoiced_Items,Parasut_ID,Exchange_Rate";
        $response = $this->request('GET', "/Invoices/$invoiceId?fields=$fields");
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    public function getProductsPage($page = 1, $perPage = 200, $modifiedSince = null)
    {
        $endpoint = "/Products?fields=id,Product_Name,Product_Code,Unit_Price,Purchase_Cost,Currency,Qty_in_Stock,Product_Active,Tax&page=$page&per_page=$perPage";
        if ($modifiedSince) {
            $safeTime = urlencode($modifiedSince);
            $endpoint .= "&criteria=(Modified_Time:greater_equal:$safeTime)";
        }
        return $this->request('GET', $endpoint);
    }

    // Fetch full invoice details
    public function getInvoice($invoiceId)
    {
        $response = $this->request('GET', "/Invoices/$invoiceId");
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }


    public function bulkDeleteInvoices($ids)
    {
        if (empty($ids))
            return ['success' => true];
        $idsString = implode(',', $ids);
        // DELETE /Invoices?ids=...,...
        return $this->request('DELETE', '/Invoices', [], ['ids' => $idsString]);
    }

    public function syncProducts($modifiedSince = null)
    {
        $upsertCount = 0;
        $page = 1;
        $moreRecords = true;

        $stmt = $this->pdo->prepare("INSERT INTO zoho_products 
            (zoho_id, product_code, product_name, unit_price, buying_price, currency, stock_quantity, vat_rate, is_active, raw_data) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            product_code = VALUES(product_code),
            product_name = VALUES(product_name),
            unit_price = VALUES(unit_price),
            buying_price = VALUES(buying_price),
            currency = VALUES(currency),
            stock_quantity = VALUES(stock_quantity),
            vat_rate = VALUES(vat_rate),
            is_active = VALUES(is_active),
            raw_data = VALUES(raw_data),
            updated_at = NOW()");

        writeLog("Starting Zoho Products Sync... " . ($modifiedSince ? "Incremental from $modifiedSince" : "Full sync"));

        while ($moreRecords) {
            try {
                $response = $this->getProductsPage($page, 200, $modifiedSince);

                if (isset($response['data']) && is_array($response['data'])) {
                    $this->pdo->beginTransaction();
                    try {
                        foreach ($response['data'] as $item) {
                            $productName = $item['Product_Name'] ?? '';

                            // Extract currency - can be string or object
                            $currency = 'TRY';
                            if (isset($item['Currency'])) {
                                if (is_array($item['Currency'])) {
                                    $currency = $item['Currency']['name'] ?? $item['Currency']['code'] ?? 'TRY';
                                } else {
                                    $currency = $item['Currency'];
                                }
                            }

                            $vatRate = 0;
                            if (isset($item['Tax']) && is_array($item['Tax']) && count($item['Tax']) > 0) {
                                // Extract numeric percentage from first tax name if possible
                                $taxItem = $item['Tax'][0];
                                if (is_array($taxItem)) {
                                    // Tax is an object/array with percentage or name
                                    if (isset($taxItem['percentage'])) {
                                        $vatRate = (float) $taxItem['percentage'];
                                    } elseif (isset($taxItem['name']) && is_string($taxItem['name']) && preg_match('/(\d+)/', $taxItem['name'], $matches)) {
                                        $vatRate = (float) $matches[1];
                                    }
                                } elseif (is_string($taxItem) && preg_match('/(\d+)/', $taxItem, $matches)) {
                                    $vatRate = (float) $matches[1];
                                }
                            }

                            $stmt->execute([
                                $item['id'],
                                $item['Product_Code'] ?? null,
                                $productName,
                                $item['Unit_Price'] ?? 0,
                                $item['Purchase_Cost'] ?? 0,
                                $currency,
                                $item['Qty_in_Stock'] ?? 0,
                                $vatRate,
                                ($item['Product_Active'] ?? true) ? 1 : 0,
                                json_encode($item)
                            ]);
                            $upsertCount++;
                        }
                        $this->pdo->commit();
                        writeLog("Zoho Sync: Page $page processed. Total upserted: $upsertCount");
                    } catch (Exception $e) {
                        $this->pdo->rollBack();
                        writeLog("Transaction Error during Zoho syncProducts: " . $e->getMessage());
                        throw $e;
                    }
                }

                // Check if more records
                if (isset($response['info']['more_records']) && $response['info']['more_records']) {
                    $page++;
                } else {
                    $moreRecords = false;
                }

                // Sleep to be nice to API
                usleep(250000);

            } catch (Exception $e) {
                writeLog("Error fetching Zoho products page $page: " . $e->getMessage());
                // Decide whether to abort or continue. Usually abort.
                $moreRecords = false;
            }
        }

        return $upsertCount;
    }
    public function updateInvoiceTaxes($invoiceId, $items, $targetTaxName = "KDV 18")
    {
        // 1. Fetch Existing Zoho Invoice to get Line Item IDs
        $zohoInvoice = $this->getInvoice($invoiceId);
        if (!$zohoInvoice) {
            throw new Exception('Zoho\'dan fatura detayları alınamadı.');
        }

        // Map Existing Zoho Items by Product ID
        $zohoItemMap = [];
        if (isset($zohoInvoice['Invoiced_Items'])) {
            foreach ($zohoInvoice['Invoiced_Items'] as $zItem) {
                $zProdId = $zItem['Product_Name']['id'] ?? '';
                if ($zProdId) {
                    $zohoItemMap[$zProdId] = $zItem['id'];
                }
            }
        }

        $lineItemsForZoho = [];
        foreach ($items as $item) {
            $pCode = $item['product_code'] ?? '';
            $pName = $item['product_name'] ?? '';

            // Search Zoho Product
            $zProductId = null;
            if (!empty($pCode)) {
                $zProduct = $this->searchProduct($pCode);
                $zProductId = $zProduct['id'] ?? null;
            }
            if (!$zProductId && !empty($pName)) {
                $zProduct = $this->searchProductByName($pName);
                $zProductId = $zProduct['id'] ?? null;
            }

            if ($zProductId) {
                $lineData = [
                    'product_id' => $zProductId,
                    'quantity' => $item['quantity'],
                    'price' => $item['price']
                ];

                if (isset($zohoItemMap[$zProductId])) {
                    $lineData['id'] = $zohoItemMap[$zProductId];
                }

                if (($item['vat_rate'] ?? 0) > 0) {
                    $lineData['tax_name'] = $targetTaxName;
                }

                $lineItemsForZoho[] = $lineData;
            }
        }

        if (empty($lineItemsForZoho)) {
            throw new Exception('Zoho\'da eşleşen ürün bulunamadı, güncelleme yapılamaz.');
        }

        return $this->updateInvoice($invoiceId, $lineItemsForZoho);
    }


    public function getProductIds($limit = 50)
    {
        // limit should not exceed 200 for Zoho API page size
        $limit = min($limit, 200);
        $endpoint = "/Products?fields=id&page=1&per_page=" . $limit;

        $response = $this->request('GET', $endpoint);

        $ids = [];
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $record) {
                $ids[] = $record['id'];
            }
        }

        return $ids;
    }

    public function massDeleteProducts(array $ids)
    {
        if (empty($ids))
            return ['success' => true, 'count' => 0];

        $idsString = implode(',', $ids);
        $endpoint = "/Products?ids=" . $idsString;

        // Zoho Mass Delete via DELETE method with 'ids' param
        $response = $this->request('DELETE', $endpoint);

        // Check response
        // Zoho returns: {"data": [{"code":"SUCCESS", "details":{...}, "message":"record deleted", "status":"success"}, ...]}

        if (isset($response['data']) && is_array($response['data'])) {
            $successCount = 0;
            $errors = [];

            foreach ($response['data'] as $res) {
                if (isset($res['status']) && ($res['status'] === 'success' || $res['code'] === 'SUCCESS')) {
                    $successCount++;
                } else {
                    $errors[] = $res['message'] ?? 'Bilinmeyen hata';
                }
            }

            if ($successCount > 0 && empty($errors)) {
                return ['success' => true, 'count' => $successCount];
            } elseif ($successCount > 0 && !empty($errors)) {
                // Partial success
                return ['success' => true, 'count' => $successCount, 'partial_errors' => $errors];
            } else {
                // All failed
                return ['success' => false, 'error' => implode(' | ', array_unique($errors))];
            }
        }

        return ['success' => false, 'error' => 'Unexpected response format: ' . json_encode($response)];
    }

    public function getRecordIds($module, $limit = 50)
    {
        // limit should not exceed 200 for Zoho API page size
        $limit = min($limit, 200);
        $endpoint = "/$module?fields=id&page=1&per_page=" . $limit;

        $response = $this->request('GET', $endpoint);

        $ids = [];
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $record) {
                $ids[] = $record['id'];
            }
        }

        return $ids;
    }

    public function massDeleteRecords($module, array $ids)
    {
        if (empty($ids))
            return ['success' => true, 'count' => 0];

        $idsString = implode(',', $ids);
        $endpoint = "/$module?ids=" . $idsString;

        // Zoho Mass Delete via DELETE method with 'ids' param
        $response = $this->request('DELETE', $endpoint);

        if (isset($response['data']) && is_array($response['data'])) {
            $successCount = 0;
            $errors = [];

            foreach ($response['data'] as $res) {
                // Accept SUCCESS code or success status
                if (isset($res['status']) && ($res['status'] === 'success' || $res['code'] === 'SUCCESS')) {
                    $successCount++;
                } else {
                    $errors[] = $res['message'] ?? 'Bilinmeyen hata';
                }
            }

            if ($successCount > 0 && empty($errors)) {
                return ['success' => true, 'count' => $successCount];
            } elseif ($successCount > 0 && !empty($errors)) {
                return ['success' => true, 'count' => $successCount, 'partial_errors' => $errors];
            } else {
                return ['success' => false, 'error' => implode(' | ', array_unique($errors))];
            }
        }

        return ['success' => false, 'error' => 'Unexpected response format: ' . json_encode($response)];
    }

    public function getRecordsForApproval($module, $limit = 10)
    {
        // limit should not exceed 200 for Zoho API page size
        $limit = min($limit, 200);

        // Determine Name Field based on Module
        $nameField = 'Subject'; // Default for Sales_Orders, Purchase_Orders, Quotes
        if ($module === 'Products')
            $nameField = 'Product_Name';
        if ($module === 'Invoices')
            $nameField = 'Invoice_Number'; // Invoice Number is more useful than Subject usually
        if ($module === 'Credit_Notes')
            $nameField = 'Credit_Note_Number';
        if ($module === 'Inventory_Adjustments')
            $nameField = 'Reference_Number';
        if ($module === 'Price_Books')
            $nameField = 'Price_Book_Name';
        if (strpos($module, 'CustomModule') === 0)
            $nameField = 'Subject';

        $endpoint = "/$module?fields=id,$nameField&page=1&per_page=" . $limit;

        $response = $this->request('GET', $endpoint);

        $records = [];
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $record) {
                $records[] = [
                    'id' => $record['id'],
                    'name' => $record[$nameField] ?? 'Bilgi Yok'
                ];
            }
        }

        return $records;
    }

    public function getProduct($id)
    {
        $response = $this->request('GET', "/Products/$id");
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    /**
     * Get Tax Rates from Zoho
     */
    public function getTaxes()
    {
        // /settings/taxes failed with 404. Trying /org/taxes (V2 Standard)
        $response = $this->request('GET', "/org/taxes");

        if (isset($response['taxes'])) {
            return $response['taxes'];
        }

        return [];
    }

    // ==================== PURCHASE ORDERS & VENDORS ====================

    /**
     * Search for an existing Invoice by Invoice_Number in Zoho
     * Returns the first matching record or null
     */
    public function searchInvoiceByNumber(string $invoiceNumber): ?array
    {
        if (empty($invoiceNumber))
            return null;
        try {
            $safe = urlencode($invoiceNumber);
            $response = $this->request('GET', "/Invoices/search?criteria=(Invoice_Number:equals:$safe)");
            return $response['data'][0] ?? null;
        } catch (Exception $e) {
            writeLog("Invoice search by number failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Search for an existing Purchase Order by PO_Number in Zoho
     * Returns the first matching record or null
     */
    public function searchPurchaseOrderByNumber(string $poNumber): ?array
    {
        if (empty($poNumber))
            return null;
        try {
            $safe = urlencode($poNumber);
            $response = $this->request('GET', "/Purchase_Orders/search?criteria=(PO_Number:equals:$safe)");
            return $response['data'][0] ?? null;
        } catch (Exception $e) {
            writeLog("PO search by number failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Search for Vendor (Supplier) Account
     */
    public function searchVendor($name)
    {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        $safeName = str_replace(['(', ')'], ['\\(', '\\)'], $name);
        $endpoint = "/Vendors/search?criteria=(Vendor_Name:equals:" . urlencode($safeName) . ")";

        try {
            $response = $this->request('GET', $endpoint);
            if (isset($response['data']) && count($response['data']) > 0) {
                return $response['data'][0];
            }
        } catch (Exception $e) {
            writeLog("Vendor search failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Create a new Vendor (Supplier) account
     * Returns raw Zoho API response for consistent handling in controllers
     */
    public function createVendor($name, $email = null, $phone = null)
    {
        $vendorData = [
            'Vendor_Name' => $name
        ];

        if ($email) {
            $vendorData['Email'] = $email;
        }
        if ($phone) {
            $vendorData['Phone'] = $phone;
        }

        $data = [
            'data' => [$vendorData]
        ];

        $response = $this->request('POST', '/Vendors', $data);

        // Handle duplicate vendor — return existing ID in same format
        if (isset($response['data'][0]['code']) && $response['data'][0]['code'] === 'DUPLICATE_DATA') {
            $dupId = $response['data'][0]['details']['duplicate_record']['id'] ?? null;
            if ($dupId) {
                writeLog("Vendor exists (duplicate). Using ID: $dupId");
                // Normalize response to success format
                $response['data'][0]['status'] = 'success';
                $response['data'][0]['details']['id'] = $dupId;
            }
        }

        return $response;
    }

    /**
     * Get Purchase Orders from Zoho
     */
    public function getPurchaseOrders($page = 1, $perPage = 200, $pageToken = null)
    {
        $fields = "id,Subject,PO_Date,Grand_Total,Currency,Status,Vendor_Name,Description";

        if ($pageToken) {
            $endpoint = "/Purchase_Orders?fields=$fields&per_page=$perPage&page_token=$pageToken";
        } else {
            $endpoint = "/Purchase_Orders?fields=$fields&page=$page&per_page=$perPage";
        }

        $response = $this->request('GET', $endpoint);

        return [
            'data' => $response['data'] ?? [],
            'info' => $response['info'] ?? [],
            'next_page_token' => $response['info']['next_page_token'] ?? null,
            'more_records' => $response['info']['more_records'] ?? false
        ];
    }

    /**
     * Create Purchase Order in Zoho
     */
    public function createPurchaseOrder($subject, $vendorId, $lineItems, $currency = 'TRY', $options = [])
    {
        $poData = [
            'Subject' => $subject,
            'Vendor_Name' => ['id' => $vendorId],
            'Currency' => $currency
        ];

        // Add line items
        if (!empty($lineItems)) {
            $productDetails = [];

            // Tax ID mapping (from DB settings)
            $taxMapJson = getSetting($this->pdo, 'zoho_tax_map');
            $taxIdMap = $taxMapJson ? json_decode($taxMapJson, true) : [];
            if (empty($taxIdMap)) {
                writeLog("WARNING: zoho_tax_map not configured. PO line items will have no tax.");
            }

            foreach ($lineItems as $item) {
                $lineItemData = [
                    'Product_Name' => ['id' => $item['product_id']],
                    'Quantity' => (float) $item['quantity'],
                    'List_Price' => round((float) $item['price'], 2)
                ];

                // Add Description if provided
                if (!empty($item['description'])) {
                    $lineItemData['Description'] = $item['description'];
                }

                // Determine VAT rate
                $vatRate = 0;
                if (!empty($item['tax_name'])) {
                    $vatRate = (int) preg_replace('/[^0-9]/', '', $item['tax_name']);
                }
                if ($vatRate == 0 && isset($item['vat_rate'])) {
                    $vatRate = (int) $item['vat_rate'];
                }

                // Add Line_Tax
                if ($vatRate > 0 && isset($taxIdMap[(string) $vatRate])) {
                    $taxId = $taxIdMap[(string) $vatRate];
                    $taxName = "KDV " . $vatRate;

                    $lineItemData['Line_Tax'] = [
                        [
                            'id' => $taxId,
                            'name' => $taxName,
                            'percentage' => (float) $vatRate
                        ]
                    ];
                }

                // Add Discount if provided
                if (!empty($item['discount']) && (float) $item['discount'] > 0) {
                    $lineItemData['Discount'] = round((float) $item['discount'], 2);
                }

                $productDetails[] = $lineItemData;
            }
            $poData['Purchase_Items'] = $productDetails;
        }

        // Add PO Date if provided
        if (!empty($options['po_date'])) {
            $poData['PO_Date'] = $options['po_date'];
        }

        // Add PO Number if provided (Parasut fatura numarası)
        if (!empty($options['po_number'])) {
            $poData['PO_Number'] = $options['po_number'];
        }

        // Add Due Date if provided
        if (!empty($options['due_date'])) {
            $poData['Due_Date'] = $options['due_date'];
        }

        // Add Description if provided
        if (!empty($options['description'])) {
            $poData['Description'] = $options['description'];
        }

        // Add Status if provided
        if (!empty($options['status'])) {
            $poData['Status'] = $options['status'];
        }

        // Exchange Rate
        if (!empty($options['exchange_rate'])) {
            $poData['Exchange_Rate'] = (float) $options['exchange_rate'];
        }

        // Add Parasut URL if provided
        if (!empty($options['parasut_url'])) {
            $poData['Parasut_URL'] = $options['parasut_url'];
        }

        $data = [
            'data' => [$poData]
        ];

        try {
            return $this->request('POST', '/Purchase_Orders', $data);
        } catch (\Exception $e) {
            // Auto-fix: "Given tax is not present in the corresponding product"
            if (strpos($e->getMessage(), 'tax is not present') !== false) {
                writeLog("PO Tax error detected — auto-fixing product taxes and retrying...");
                foreach ($poData['Purchase_Items'] ?? [] as $item) {
                    $pid = $item['Product_Name']['id'] ?? null;
                    if ($pid)
                        $this->ensureProductTaxes($pid);
                }
                $result = $this->request('POST', '/Purchase_Orders', $data);
                writeLog("PO created after tax auto-fix ✅");
                return $result;
            }
            throw $e;
        }
    }

    // ==================== PRODUCT MERGE HELPERS ====================

    /**
     * Get ALL products from Zoho (paginated, returns full array)
     * Used by merge tool to detect duplicates
     */
    public function getAllProducts()
    {
        $allProducts = [];
        $page = 1;
        $hasMore = true;
        $pageToken = null;

        while ($hasMore) {
            if ($pageToken) {
                $endpoint = "/Products?fields=id,Product_Name,Product_Code,Unit_Price,Created_Time,Modified_Time,Product_Active&per_page=200&page_token=$pageToken";
            } else {
                $endpoint = "/Products?fields=id,Product_Name,Product_Code,Unit_Price,Created_Time,Modified_Time,Product_Active&page=$page&per_page=200";
            }

            $response = $this->request('GET', $endpoint);

            if (isset($response['data']) && is_array($response['data'])) {
                $allProducts = array_merge($allProducts, $response['data']);
            } else {
                break;
            }

            $hasMore = isset($response['info']['more_records']) && $response['info']['more_records'] === true;
            $pageToken = $response['info']['next_page_token'] ?? null;
            $page++;

            if ($page > 200)
                break; // Safety limit
            usleep(300000); // Rate limit: 300ms
        }

        return $allProducts;
    }

    /**
     * Find Invoices that contain a specific product in their line items
     * Uses COQL (Zoho's query language) for efficient searching
     * @param string $productId Zoho Product ID
     * @return array List of invoice records with line items
     */
    public function getInvoicesByProduct($productId)
    {
        $invoices = [];

        try {
            // Search invoices where the product is used in Invoiced_Items
            $endpoint = "/Invoices/search?criteria=(Invoiced_Items.Product_Name.id:equals:" . urlencode($productId) . ")&fields=id,Invoice_Number,Invoiced_Items";
            $response = $this->request('GET', $endpoint);

            if (isset($response['data']) && is_array($response['data'])) {
                $invoices = $response['data'];
            }
        } catch (Exception $e) {
            // Search API might not support submodule criteria, fallback handled by caller
            writeLog("getInvoicesByProduct search failed for $productId: " . $e->getMessage());
        }

        return $invoices;
    }

    /**
     * Update invoice line items: replace oldProductId with newProductId
     * Preserves all other line item data (quantity, price, tax, discount)
     * @param string $invoiceId Zoho Invoice ID
     * @param string $oldProductId Product ID to replace
     * @param string $newProductId Product ID to use instead (master)
     * @return array API response
     */
    public function updateInvoiceLineItemProduct($invoiceId, $oldProductId, $newProductId)
    {
        // 1. Fetch the full invoice to get current line items
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice || empty($invoice['Invoiced_Items'])) {
            throw new Exception("Invoice $invoiceId has no line items");
        }

        // 2. Rebuild line items, replacing the product reference
        $updatedItems = [];
        $changed = false;

        foreach ($invoice['Invoiced_Items'] as $item) {
            $itemData = [
                'id' => $item['id'], // Keep existing line item ID
                'Product_Name' => ['id' => $item['Product_Name']['id'] ?? ''],
                'Quantity' => $item['Quantity'] ?? 1,
                'List_Price' => $item['List_Price'] ?? 0,
            ];

            // Copy Tax if exists
            if (!empty($item['Line_Tax'])) {
                $itemData['Line_Tax'] = $item['Line_Tax'];
            }

            // Copy Discount if exists
            if (isset($item['Discount'])) {
                $itemData['Discount'] = $item['Discount'];
            }

            // Replace the product reference if it matches
            $currentProductId = $item['Product_Name']['id'] ?? '';
            if ($currentProductId === $oldProductId) {
                $itemData['Product_Name'] = ['id' => $newProductId];
                $changed = true;
            }

            $updatedItems[] = $itemData;
        }

        if (!$changed) {
            return ['success' => true, 'message' => 'No matching product found in invoice line items'];
        }

        // 3. Update the invoice
        $data = [
            'data' => [
                [
                    'id' => $invoiceId,
                    'Invoiced_Items' => $updatedItems
                ]
            ]
        ];

        writeLog("Merging product in Invoice $invoiceId: $oldProductId → $newProductId");
        return $this->request('PUT', "/Invoices/$invoiceId", $data);
    }

    /**
     * Find Purchase Orders that contain a specific product
     * @param string $productId Zoho Product ID
     * @return array List of PO records
     */
    public function getPurchaseOrdersByProduct($productId)
    {
        $orders = [];

        try {
            $endpoint = "/Purchase_Orders/search?criteria=(Purchase_Items.Product_Name.id:equals:" . urlencode($productId) . ")&fields=id,Subject,Purchase_Items";
            $response = $this->request('GET', $endpoint);

            if (isset($response['data']) && is_array($response['data'])) {
                $orders = $response['data'];
            }
        } catch (Exception $e) {
            writeLog("getPurchaseOrdersByProduct search failed for $productId: " . $e->getMessage());
        }

        return $orders;
    }

    /**
     * Update PO line items: replace oldProductId with newProductId
     * @param string $poId Zoho Purchase Order ID
     * @param string $oldProductId Product ID to replace
     * @param string $newProductId Product ID to use instead (master)
     * @return array API response
     */
    public function updatePOLineItemProduct($poId, $oldProductId, $newProductId)
    {
        // 1. Fetch the full PO
        $response = $this->request('GET', "/Purchase_Orders/$poId");
        $po = $response['data'][0] ?? null;

        if (!$po || empty($po['Purchase_Items'])) {
            throw new Exception("Purchase Order $poId has no line items");
        }

        // 2. Rebuild line items
        $updatedItems = [];
        $changed = false;

        foreach ($po['Purchase_Items'] as $item) {
            $itemData = [
                'id' => $item['id'],
                'Product_Name' => ['id' => $item['Product_Name']['id'] ?? ''],
                'Quantity' => $item['Quantity'] ?? 1,
                'List_Price' => $item['List_Price'] ?? 0,
            ];

            if (!empty($item['Line_Tax'])) {
                $itemData['Line_Tax'] = $item['Line_Tax'];
            }

            if (isset($item['Discount'])) {
                $itemData['Discount'] = $item['Discount'];
            }

            $currentProductId = $item['Product_Name']['id'] ?? '';
            if ($currentProductId === $oldProductId) {
                $itemData['Product_Name'] = ['id' => $newProductId];
                $changed = true;
            }

            $updatedItems[] = $itemData;
        }

        if (!$changed) {
            return ['success' => true, 'message' => 'No matching product found in PO line items'];
        }

        // 3. Update the PO
        $data = [
            'data' => [
                [
                    'id' => $poId,
                    'Purchase_Items' => $updatedItems
                ]
            ]
        ];

        writeLog("Merging product in PO $poId: $oldProductId → $newProductId");
        return $this->request('PUT', "/Purchase_Orders/$poId", $data);
    }
}
