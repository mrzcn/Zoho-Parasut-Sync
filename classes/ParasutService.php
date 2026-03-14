<?php
// classes/ParasutService.php

class ParasutService
{
    private $pdo;
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $companyId;
    private $baseUrl = 'https://api.parasut.com/v4';
    private $authUrl = 'https://api.parasut.com/oauth/token';

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->clientId = getSetting($pdo, 'parasut_client_id');
        $this->clientSecret = getSetting($pdo, 'parasut_client_secret');
        $this->username = getSetting($pdo, 'parasut_username');
        $this->password = getSetting($pdo, 'parasut_password');
        $this->companyId = getSetting($pdo, 'parasut_company_id');
    }

    private function getAccessToken()
    {
        $token = getSetting($this->pdo, 'parasut_access_token');
        $expiresAt = getSetting($this->pdo, 'parasut_expires_at');

        if ($token && $expiresAt > time()) {
            return $token;
        }

        return $this->login();
    }

    private function login()
    {
        if (!$this->clientId || !$this->clientSecret || !$this->username || !$this->password) {
            throw new Exception("Paraşüt eksik ayarlar!");
        }

        $fields = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $this->username,
            'password' => $this->password,
            'grant_type' => 'password',
            'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob'
        ];

        $retryCount = 0;
        $maxRetries = 3;

        do {
            $ch = curl_init($this->authUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Log authentication attempt details
            writeLog("Paraşüt Login Attempt " . ($retryCount + 1) . " - HTTP Code: $httpCode");

            if ($httpCode === 429) {
                $retryCount++;
                if ($retryCount <= $maxRetries) {
                    $waitTime = 30; // Default wait time
                    // Try to parse wait time from response
                    $data = json_decode($response, true);
                    if (isset($data['errors'][0]['detail']) && preg_match('/(\d+)\s+seconds/', $data['errors'][0]['detail'], $matches)) {
                        $waitTime = (int) $matches[1] + 5; // Add 5s buffer
                    }
                    writeLog("Login Rate Limit Hit (429). Waiting $waitTime seconds... (Attempt $retryCount)");
                    sleep($waitTime);
                    continue;
                }
            }

            if ($curlError) {
                writeLog("Paraşüt Login - cURL Error: $curlError");
            }
            writeLog("Paraşüt Login - Response: " . substr($response, 0, 500));

            $data = json_decode($response, true);

            if ($httpCode === 200 && isset($data['access_token'])) {
                $accessToken = $data['access_token'];
                $refreshToken = $data['refresh_token'];
                $expiresIn = $data['expires_in'];
                $expiresAt = time() + $expiresIn - 60; // Buffer

                updateSetting($this->pdo, 'parasut_access_token', $accessToken);
                updateSetting($this->pdo, 'parasut_refresh_token', $refreshToken);
                updateSetting($this->pdo, 'parasut_expires_at', (string) $expiresAt);

                writeLog("Paraşüt Login - Success");
                return $accessToken;
            }

            // Detailed error logging & Exit loop on non-retryable error
            $errorMsg = "Paraşüt Giriş Hatası: ";
            if (isset($data['error'])) {
                $errorMsg .= "Error: " . $data['error'];
                if (isset($data['error_description'])) {
                    $errorMsg .= " - " . $data['error_description'];
                }
            } else {
                $errorMsg .= "HTTP Code: $httpCode, Response: " . ($response ?: 'Empty response');
            }

            writeLog($errorMsg);
            throw new Exception($errorMsg);

        } while ($retryCount <= $maxRetries);
    }

    // Log API metrics to database (best-effort, never throws)
    private function logApiMetric(string $method, string $endpoint, ?int $httpCode, ?int $durationMs, bool $isRetry = false, ?string $error = null): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO api_metrics (service, method, endpoint, http_code, duration_ms, is_retry, error_message, created_at) VALUES ('parasut', ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$method, substr($endpoint, 0, 500), $httpCode, $durationMs, $isRetry ? 1 : 0, $error ? substr($error, 0, 500) : null]);
        } catch (\Exception $e) {
            // Table might not exist yet
        }
    }

    public function request($method, $endpoint, $data = [])
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl . '/' . $this->companyId . $endpoint;
        $requestStartTime = microtime(true);

        $maxRetries = 3;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $ch = curl_init($url);
            $headers = [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ];

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } elseif ($method === 'PATCH') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // CURL transport error (timeout, DNS, connection refused, etc.)
            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
                $curlErrNo = curl_errno($ch);
                curl_close($ch);
                writeLog("Paraşüt CURL ERROR #$curlErrNo: $curlError (attempt $attempt/$maxRetries)", 'ERROR', 'parasut');

                if ($attempt < $maxRetries) {
                    $attempt++;
                    $sleepTime = pow(2, $attempt);
                    writeLog("Retrying in {$sleepTime}s...", 'WARNING', 'parasut');
                    sleep($sleepTime);
                    continue;
                }
                throw new Exception("Paraşüt Bağlantı Hatası ($maxRetries deneme sonrası): $curlError");
            }

            curl_close($ch);
            writeLog("Paraşüt Response [$httpCode]", 'DEBUG', 'parasut');

            $result = json_decode($response, true);

            // Rate limit (429) — retry with exponential backoff
            if ($httpCode === 429 && $attempt < $maxRetries) {
                $waitTime = 10;
                if (isset($result['errors'][0]['detail']) && preg_match('/(\d+)\s+seconds/', $result['errors'][0]['detail'], $matches)) {
                    $waitTime = (int) $matches[1] + 5;
                }
                $attempt++;
                writeLog("Paraşüt Rate limit (429). Waiting {$waitTime}s (Attempt $attempt/$maxRetries)...", 'WARNING', 'parasut');
                sleep($waitTime);
                continue;
            }

            break; // Success or non-retryable error
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            // Log successful API metric
            $durationMs = (int) ((microtime(true) - $requestStartTime) * 1000);
            $this->logApiMetric($method, $endpoint, $httpCode, $durationMs, $attempt > 0);
            return $result;
        }

        // Log failed API metric
        $durationMs = (int) ((microtime(true) - $requestStartTime) * 1000);
        $this->logApiMetric($method, $endpoint, $httpCode, $durationMs, $attempt > 0, json_encode($result));
        writeLog("Paraşüt API Error: " . json_encode($result));
        throw new Exception("Paraşüt API Hatası ($httpCode): " . json_encode($result));
    }

    public function testConnection()
    {
        // '/home' endpoints does not exist in v4. Using '/products' with limit 1 to verify company access.
        return $this->request('GET', '/products?page[size]=1');
    }

    public function getProducts($limit = 25, $updatedSince = null, $includeArchived = false)
    {
        $allProducts = ['data' => [], 'included' => [], 'meta' => []];

        // First, fetch active (non-archived) products
        $page = 1;
        $pageSize = 25;
        $filterStr = $updatedSince ? "&filter[updated_at][gteq]=" . urlencode($updatedSince) : "";
        writeLog("Starting Paraşüt Product Fetch (Active). Limit: $limit" . ($updatedSince ? " from $updatedSince" : ""));

        do {
            $endpoint = "/products?page[number]=$page&page[size]=$pageSize" . $filterStr;
            writeLog("Fetching Active Products Page $page: $endpoint");

            $response = $this->request('GET', $endpoint);

            $count = isset($response['data']) ? count($response['data']) : 0;
            writeLog("Page $page fetched. Count: $count");

            if (isset($response['data']) && is_array($response['data'])) {
                // Mark active products
                foreach ($response['data'] as &$product) {
                    $product['attributes']['archived'] = false;
                }
                $allProducts['data'] = array_merge($allProducts['data'], $response['data']);
            }
            if (isset($response['included'])) {
                $allProducts['included'] = array_merge($allProducts['included'], $response['included']);
            }

            $totalPages = $response['meta']['total_pages'] ?? $response['meta']['page_count'] ?? 1;
            $currentTotal = count($allProducts['data']);

            writeLog("Meta Total Pages: $totalPages. Current Page: $page. Total Fetched: $currentTotal");

            if ($page >= $totalPages || $currentTotal >= $limit) {
                break;
            }
            $page++;
            usleep(200000); // 0.2s sleep between pages
        } while (true);

        $activeCount = count($allProducts['data']);
        writeLog("Active Products Fetched: $activeCount");

        // Now fetch archived products if requested and active < limit
        if ($includeArchived && count($allProducts['data']) < $limit) {
            $page = 1;
            writeLog("Starting Paraşüt Product Fetch (Archived)");

            do {
                $endpoint = "/products?page[number]=$page&page[size]=$pageSize&filter[archived]=true" . $filterStr;
                writeLog("Fetching Archived Products Page $page: $endpoint");

                $response = $this->request('GET', $endpoint);

                $count = isset($response['data']) ? count($response['data']) : 0;
                writeLog("Archived Page $page fetched. Count: $count");

                if (isset($response['data']) && is_array($response['data'])) {
                    // Mark archived products
                    foreach ($response['data'] as &$product) {
                        $product['attributes']['archived'] = true;
                    }
                    $allProducts['data'] = array_merge($allProducts['data'], $response['data']);
                }
                if (isset($response['included'])) {
                    $allProducts['included'] = array_merge($allProducts['included'], $response['included']);
                }

                $totalPages = $response['meta']['total_pages'] ?? $response['meta']['page_count'] ?? 1;
                $currentTotal = count($allProducts['data']);

                writeLog("Archived Meta Total Pages: $totalPages. Current Page: $page. Total: $currentTotal");

                if ($page >= $totalPages || $currentTotal >= $limit) {
                    break;
                }
                $page++;
                usleep(200000); // 0.2s sleep between archived pages
            } while (true);
        }

        $archivedCount = count($allProducts['data']) - $activeCount;
        writeLog("Archived Products Fetched: $archivedCount");
        writeLog("Paraşüt Fetch Complete. Total Products: " . count($allProducts['data']) . " (Active: $activeCount, Archived: $archivedCount)");

        return $allProducts;
    }

    public function updateProduct($id, $attributes)
    {
        $data = [
            'data' => [
                'type' => 'products',
                'id' => (string) $id,
                'attributes' => $attributes
            ]
        ];
        return $this->request('PATCH', "/products/$id", $data);
    }

    public function archiveProduct($id)
    {
        $data = [
            'data' => [
                'type' => 'products',
                'id' => (string) $id,
                'attributes' => [
                    'archived' => true
                ]
            ]
        ];
        return $this->request('PATCH', "/products/$id", $data);
    }

    public function unarchiveProduct($id)
    {
        $data = [
            'data' => [
                'type' => 'products',
                'id' => (string) $id,
                'attributes' => [
                    'archived' => false
                ]
            ]
        ];
        return $this->request('PATCH', "/products/$id", $data);
    }

    public function getSalesInvoices($limit = 75, $filters = [])
    {
        // Sales Invoices - Fetch up to $limit
        $allInvoices = ['data' => [], 'included' => [], 'meta' => []];
        $page = 1;
        $pageSize = 25;

        $filterStr = '';
        if (!empty($filters['issue_date'])) {
            // The API error explicitly listed valid operators: eq, lt, gt, gteq, lteq, not_eq
            // So we must use [gteq] instead of [gte]
            $filterStr = "&filter[issue_date][gteq]=" . $filters['issue_date'];
        }
        if (!empty($filters['issue_date_end'])) {
            // Add end date filter to limit results (e.g., only 2024)
            $filterStr .= "&filter[issue_date][lteq]=" . $filters['issue_date_end'];
        }

        $sort = $filters['sort'] ?? '-issue_date';

        $maxIterations = 200; // Safety limit to prevent infinite loops
        $iteration = 0;

        do {
            $iteration++;
            if ($iteration > $maxIterations) {
                writeLog("WARNING: Pagination limit ($maxIterations) reached in getSalesInvoices(). Breaking loop.");
                break;
            }

            $endpoint = "/sales_invoices?page[number]=$page&page[size]=$pageSize&sort=$sort&include=details.product" . $filterStr;
            writeLog("Fetching Invoices: $endpoint");
            $response = $this->request('GET', $endpoint);

            if (isset($response['data']) && is_array($response['data'])) {
                $allInvoices['data'] = array_merge($allInvoices['data'], $response['data']);
            }
            if (isset($response['included']) && is_array($response['included'])) {
                $allInvoices['included'] = array_merge($allInvoices['included'], $response['included']);
            }
            if (isset($response['meta'])) {
                $allInvoices['meta'] = $response['meta'];
            }

            $fetchedCount = count($allInvoices['data']);

            $totalPages = $response['meta']['total_pages'] ?? 1;
            if ($page >= $totalPages || $fetchedCount >= $limit) {
                break;
            }

            $page++;
            sleep(2);

        } while (true);

        return $allInvoices;
    }

    public function getInvoiceDetails($id)
    {
        // Fetch single invoice with details, products, contact AND active_e_document (for e-invoice notes)
        return $this->request('GET', "/sales_invoices/$id?include=details.product,contact,active_e_document");
    }

    public function getContacts($page = 1, $limit = 100)
    {
        return $this->request('GET', "/contacts?page[number]=$page&page[size]=$limit");
    }

    // Get e-invoice XML/data for extracting notes
    public function getEInvoice($eInvoiceId)
    {
        return $this->request('GET', "/e_invoices/$eInvoiceId");
    }

    public function createSalesInvoice($data)
    {
        return $this->request('POST', "/sales_invoices", ['data' => $data]);
    }

    public function createContact($attributes)
    {
        $data = [
            'data' => [
                'type' => 'contacts',
                'attributes' => $attributes
            ]
        ];
        return $this->request('POST', "/contacts", $data);
    }

    public function searchContact($query)
    {
        // Simple search by name or email
        $endpoint = "/contacts?filter[name_or_email]=" . urlencode($query);
        return $this->request('GET', $endpoint);
    }

    public function updateStock($productId, $count, $entryDate = null)
    {
        if (!$entryDate) {
            $entryDate = date('c'); // ISO8601
        }
        $data = [
            'data' => [
                'type' => 'stock_counts',
                'attributes' => [
                    'count' => $count,
                    'entry_date' => $entryDate
                ]
            ]
        ];
        return $this->request('POST', "/products/$productId/stock_counts", $data);
    }

    public function searchProducts($query)
    {
        // 1. Try as ID if numeric
        if (is_numeric($query)) {
            try {
                $result = $this->request('GET', "/products/$query");
                if (isset($result['data']['id'])) {
                    return [$result['data']];
                }
            } catch (Exception $e) {
                // Ignore 404 or other errors, continue to search by code/name
            }
        }

        // 2. Search by Code
        $codeEndpoint = "/products?filter[code]=" . urlencode($query);
        $codeResult = $this->request('GET', $codeEndpoint);
        if (!empty($codeResult['data'])) {
            return $codeResult['data'];
        }

        // 2b. Search by Code (Archived)
        $codeEndpointArchived = "/products?filter[code]=" . urlencode($query) . "&filter[archived]=true";
        $codeResultArchived = $this->request('GET', $codeEndpointArchived);
        if (!empty($codeResultArchived['data'])) {
            return $codeResultArchived['data'];
        }

        // 3. Search by Name
        $nameEndpoint = "/products?filter[name]=" . urlencode($query);
        $nameResult = $this->request('GET', $nameEndpoint);
        if (!empty($nameResult['data'])) {
            return $nameResult['data'];
        }

        // 3b. Search by Name (Archived)
        $nameEndpointArchived = "/products?filter[name]=" . urlencode($query) . "&filter[archived]=true";
        $nameResultArchived = $this->request('GET', $nameEndpointArchived);
        if (!empty($nameResultArchived['data'])) {
            return $nameResultArchived['data'];
        }

        return [];
    }

    // Fetch e-invoice XML and parse all cbc:Note tags
    public function fetchEInvoiceXmlNotes($eInvoiceId)
    {
        if (empty($eInvoiceId)) {
            return [];
        }

        $token = $this->getAccessToken();

        // Construct API URL: https://api.parasut.com/v4/{company_id}/e_invoices/{id}/signed_ubl
        $url = $this->baseUrl . '/' . $this->companyId . '/e_invoices/' . $eInvoiceId . '/signed_ubl';

        // Download the XML file
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/xml, text/xml, */*'
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Handle gzip/deflate

        $xmlContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($xmlContent)) {
            writeLog("Failed to fetch e-invoice XML from: $url (HTTP $httpCode)");
            return [];
        }

        // Check if content is HTML (login page)
        if (strpos($xmlContent, '<!DOCTYPE html') !== false || strpos($xmlContent, '<html') !== false) {
            writeLog("Received HTML instead of XML from: $url (Likely authentication issue or wrong endpoint)");
            return [];
        }

        writeLog("Fetched e-invoice XML (" . strlen($xmlContent) . " bytes, type: $contentType)");
        writeLog("XML Content Preview: " . substr($xmlContent, 0, 300));

        // Parse XML and extract cbc:Note tags
        $notes = [];

        // Suppress warnings and capture errors
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = libxml_get_errors();
            foreach ($errors as $error) {
                writeLog("XML Parse Error: " . trim($error->message) . " (line " . $error->line . ")");
            }
            libxml_clear_errors();
            writeLog("Failed to parse e-invoice XML");
            return [];
        }

        // Get all namespaces from the document
        $namespaces = $xml->getNamespaces(true);
        writeLog("XML Namespaces: " . json_encode(array_keys($namespaces)));

        // Try to find cbc namespace
        $cbcNs = null;
        foreach ($namespaces as $prefix => $uri) {
            if (strpos($uri, 'CommonBasicComponents') !== false || $prefix === 'cbc') {
                $cbcNs = $uri;
                writeLog("Found cbc namespace: $uri");
                break;
            }
        }

        if ($cbcNs) {
            $xml->registerXPathNamespace('cbc', $cbcNs);
            $noteElements = $xml->xpath('//cbc:Note');
        } else {
            // Try without namespace if cbc not found
            $noteElements = $xml->xpath('//Note');
        }

        if ($noteElements) {
            foreach ($noteElements as $note) {
                $noteText = trim((string) $note);
                if (!empty($noteText)) {
                    $notes[] = $noteText;
                }
            }
        }

        writeLog("Parsed " . count($notes) . " cbc:Note tags from e-invoice XML");

        return $notes;
    }

    public function syncProducts($updatedSince = null, $includeArchived = false)
    {
        // Fetch from API — skip archived by default (saves ~60s)
        $limit = $includeArchived ? 10000 : 5000;
        $products = $this->getProducts($limit, $updatedSince, $includeArchived);

        $upsertCount = 0;
        $stmt = $this->pdo->prepare("INSERT INTO parasut_products 
            (parasut_id, product_code, product_name, list_price, buying_price, currency, is_archived, stock_quantity, vat_rate, raw_data) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            product_code = VALUES(product_code),
            product_name = VALUES(product_name),
            list_price = VALUES(list_price),
            buying_price = VALUES(buying_price),
            currency = VALUES(currency),
            is_archived = VALUES(is_archived),
            stock_quantity = VALUES(stock_quantity),
            vat_rate = VALUES(vat_rate),
            raw_data = VALUES(raw_data),
            updated_at = NOW()");

        if (isset($products['data'])) {
            $this->pdo->beginTransaction();
            try {
                foreach ($products['data'] as $item) {

                    $productName = $item['attributes']['name'] ?? '';

                    $stmt->execute([
                        $item['id'],
                        $item['attributes']['code'] ?? '',
                        $productName,
                        $item['attributes']['list_price'] ?? 0,
                        $item['attributes']['buying_price'] ?? 0,
                        $item['attributes']['currency'] ?? 'TRY',
                        ($item['attributes']['archived'] ?? false) ? 1 : 0,
                        $item['attributes']['stock_quantity'] ?? 0,
                        $item['attributes']['vat_rate'] ?? 0,
                        json_encode($item)
                    ]);
                    $upsertCount++;
                }
                $this->pdo->commit();
            } catch (Exception $e) {
                $this->pdo->rollBack();
                writeLog("Transaction Error during syncProducts: " . $e->getMessage());
                throw $e;
            }
        }

        writeLog("Parasut products upserted: $upsertCount");
        return $upsertCount;
    }

    public function syncInvoices($limit = 500, $fullSync = false)
    {
        // ... (existing code for latestDate and existingIds)
        $latestDateStmt = $this->pdo->query("SELECT MAX(issue_date) as latest_date FROM parasut_invoices");
        $latestDate = $latestDateStmt->fetchColumn();

        $existingIdsStmt = $this->pdo->query("SELECT parasut_id FROM parasut_invoices");
        $existingIds = $existingIdsStmt->fetchAll(PDO::FETCH_COLUMN);
        $existingIdsMap = array_flip($existingIds);

        $filters = ['sort' => '-issue_date'];

        if ($latestDate && !$fullSync) {
            // Safety: If latestDate is in the future, cap it to today
            if (strtotime($latestDate) > time()) {
                $latestDate = date('Y-m-d');
            }
            $filterDate = date('Y-m-d', strtotime($latestDate . ' -90 days')); // Increased to 90 days for safety
            $filters['issue_date'] = $filterDate;
            $filters['issue_date_end'] = date('Y-m-d', strtotime('+1 day'));
            writeLog("Incremental sync: Fetching from $filterDate to today (latest in DB: $latestDate)");
        } else {
            writeLog($fullSync ? "Full sync requested" : "First sync");
        }

        $response = $this->getSalesInvoices($limit, $filters);

        // ... (existing maps)
        $productsMap = [];
        $invoiceDetailsMap = [];
        if (isset($response['included'])) {
            foreach ($response['included'] as $inc) {
                if ($inc['type'] === 'products') {
                    $productsMap[$inc['id']] = $inc['attributes'];
                }
                if ($inc['type'] === 'sales_invoice_details' || $inc['type'] === 'sales_invoice_detail') {
                    $invoiceDetailsMap[$inc['id']] = $inc;
                }
            }
        }

        $upsertCount = 0;
        $itemsCount = 0;

        $stmtInvoice = $this->pdo->prepare("INSERT INTO parasut_invoices 
            (parasut_id, invoice_number, issue_date, due_date, net_total, gross_total, currency, description, invoice_type, payment_status, remaining_payment, raw_data) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            invoice_number = VALUES(invoice_number),
            issue_date = VALUES(issue_date),
            due_date = VALUES(due_date),
            net_total = VALUES(net_total),
            gross_total = VALUES(gross_total),
            currency = VALUES(currency),
            description = VALUES(description),
            invoice_type = VALUES(invoice_type),
            payment_status = VALUES(payment_status),
            remaining_payment = VALUES(remaining_payment),
            raw_data = VALUES(raw_data),
            updated_at = CURRENT_TIMESTAMP");

        $stmtGetLocalId = $this->pdo->prepare("SELECT id FROM parasut_invoices WHERE parasut_id = ?");
        $stmtDeleteItems = $this->pdo->prepare("DELETE FROM parasut_invoice_items WHERE invoice_id = ?");
        $stmtInsertItem = $this->pdo->prepare("INSERT INTO parasut_invoice_items 
            (invoice_id, parasut_product_id, parasut_detail_id, product_name, quantity, unit_price, discount_amount, vat_rate, net_total) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtGetLocalProdId = $this->pdo->prepare("SELECT id FROM parasut_products WHERE parasut_id = ?");

        if (isset($response['data'])) {
            foreach ($response['data'] as $item) {
                $parasutId = $item['id'];

                $attr = $item['attributes'];

                // Determine payment status
                $remaining = (float) ($attr['remaining_payment'] ?? 0);
                $total = (float) ($attr['net_total'] ?? 0);
                $status = 'unpaid';
                if ($remaining <= 0) {
                    $status = 'paid';
                } elseif ($remaining < $total) {
                    $status = 'partially_paid';
                }

                $stmtInvoice->execute([
                    $parasutId,
                    $attr['invoice_number'] ?? '',
                    $attr['issue_date'],
                    $attr['due_date'] ?? null,
                    $attr['net_total'],
                    $attr['gross_total'],
                    $attr['currency'],
                    $attr['description'] ?? '',
                    $attr['item_type'] ?? 'invoice',
                    $status,
                    $remaining,
                    json_encode($item)
                ]);
                $upsertCount++;

                // Get local ID to insert items
                $stmtGetLocalId->execute([$parasutId]);
                $localInvoiceId = $stmtGetLocalId->fetchColumn();

                if ($localInvoiceId) {
                    $stmtDeleteItems->execute([$localInvoiceId]);

                    $detailIds = [];
                    if (isset($item['relationships']['sales_invoice_details']['data'])) {
                        foreach ($item['relationships']['sales_invoice_details']['data'] as $rel) {
                            $detailIds[] = $rel['id'];
                        }
                    }

                    foreach ($detailIds as $dId) {
                        if (isset($invoiceDetailsMap[$dId])) {
                            $inc = $invoiceDetailsMap[$dId];
                            $pId = $inc['relationships']['product']['data']['id'] ?? null;
                            $localProductId = null;
                            if ($pId) {
                                $stmtGetLocalProdId->execute([$pId]);
                                $localProductId = $stmtGetLocalProdId->fetchColumn();
                            }

                            $productName = $productsMap[$pId]['name'] ?? $inc['attributes']['product_name'] ?? 'Bilinmeyen Ürün';

                            $stmtInsertItem->execute([
                                $localInvoiceId,
                                $localProductId,
                                $dId,
                                $productName,
                                $inc['attributes']['quantity'] ?? 0,
                                $inc['attributes']['unit_price'] ?? 0,
                                $inc['attributes']['discount_amount'] ?? 0,
                                $inc['attributes']['vat_rate'] ?? 0,
                                $inc['attributes']['net_total'] ?? 0
                            ]);
                            $itemsCount++;
                        }
                    }
                }
            }
        }

        return ['invoices' => $upsertCount, 'items' => $itemsCount];
    }

    // ==================== PURCHASE BILLS (Expense Invoices) ====================

    public function getPurchaseBills($limit = 75, $filters = [])
    {
        // Purchase Bills - Fetch up to $limit
        $allBills = ['data' => [], 'included' => [], 'meta' => []];
        $page = 1;
        $pageSize = 25;

        $filterStr = '';
        if (!empty($filters['issue_date'])) {
            $filterStr = "&filter[issue_date][gteq]=" . $filters['issue_date'];
        }
        if (!empty($filters['issue_date_end'])) {
            $filterStr .= "&filter[issue_date][lteq]=" . $filters['issue_date_end'];
        }

        $sort = $filters['sort'] ?? '-issue_date';

        do {
            $endpoint = "/purchase_bills?page[number]=$page&page[size]=$pageSize&sort=$sort&include=details.product,supplier" . $filterStr;
            writeLog("Fetching Purchase Bills: $endpoint");
            $response = $this->request('GET', $endpoint);

            if (isset($response['data']) && is_array($response['data'])) {
                $allBills['data'] = array_merge($allBills['data'], $response['data']);
            }
            if (isset($response['included']) && is_array($response['included'])) {
                $allBills['included'] = array_merge($allBills['included'], $response['included']);
            }
            if (isset($response['meta'])) {
                $allBills['meta'] = $response['meta'];
            }

            $fetchedCount = count($allBills['data']);

            $totalPages = $response['meta']['total_pages'] ?? 1;
            if ($page >= $totalPages || $fetchedCount >= $limit) {
                break;
            }

            $page++;
            sleep(2);

        } while (true);

        return $allBills;
    }

    public function getPurchaseBillDetails($id)
    {
        // Parasut API v4 valid relations: supplier, spender, details, details.product
        return $this->request('GET', "/purchase_bills/$id?include=details,details.product,supplier,spender");
    }

    public function syncPurchaseBills($limit = 500, $fullSync = false)
    {
        $latestDateStmt = $this->pdo->query("SELECT MAX(issue_date) as latest_date FROM parasut_purchase_orders");
        $latestDate = $latestDateStmt->fetchColumn();

        $existingIdsStmt = $this->pdo->query("SELECT parasut_id FROM parasut_purchase_orders");
        $existingIds = $existingIdsStmt->fetchAll(PDO::FETCH_COLUMN);
        $existingIdsMap = array_flip($existingIds);

        $filters = ['sort' => '-issue_date'];

        if ($latestDate && !$fullSync) {
            if (strtotime($latestDate) > time()) {
                $latestDate = date('Y-m-d');
            }
            $filterDate = date('Y-m-d', strtotime($latestDate . ' -90 days'));
            $filters['issue_date'] = $filterDate;
            $filters['issue_date_end'] = date('Y-m-d', strtotime('+1 day'));
            writeLog("Incremental sync (Purchase Bills): Fetching from $filterDate to today (latest in DB: $latestDate)");
        } else {
            writeLog($fullSync ? "Full sync (Purchase Bills) requested" : "First sync (Purchase Bills)");
        }

        $response = $this->getPurchaseBills($limit, $filters);

        // Build maps for products and details
        $productsMap = [];
        $billDetailsMap = [];
        if (isset($response['included'])) {
            foreach ($response['included'] as $inc) {
                if ($inc['type'] === 'products') {
                    $productsMap[$inc['id']] = $inc['attributes'];
                }
                if ($inc['type'] === 'purchase_bill_details' || $inc['type'] === 'purchase_bill_detail') {
                    $billDetailsMap[$inc['id']] = $inc;
                }
            }
        }

        $upsertCount = 0;
        $itemsCount = 0;

        $stmtPO = $this->pdo->prepare("INSERT INTO parasut_purchase_orders 
            (parasut_id, invoice_number, issue_date, due_date, net_total, gross_total, currency, description, payment_status, remaining_payment, raw_data) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            invoice_number = VALUES(invoice_number),
            issue_date = VALUES(issue_date),
            due_date = VALUES(due_date),
            net_total = VALUES(net_total),
            gross_total = VALUES(gross_total),
            currency = VALUES(currency),
            description = VALUES(description),
            payment_status = VALUES(payment_status),
            remaining_payment = VALUES(remaining_payment),
            raw_data = VALUES(raw_data),
            updated_at = CURRENT_TIMESTAMP");

        $stmtGetLocalId = $this->pdo->prepare("SELECT id FROM parasut_purchase_orders WHERE parasut_id = ?");
        $stmtDeleteItems = $this->pdo->prepare("DELETE FROM parasut_purchase_order_items WHERE purchase_order_id = ?");
        $stmtInsertItem = $this->pdo->prepare("INSERT INTO parasut_purchase_order_items 
            (purchase_order_id, parasut_product_id, parasut_detail_id, product_name, quantity, unit_price, discount_amount, vat_rate, net_total) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtGetLocalProdId = $this->pdo->prepare("SELECT id FROM parasut_products WHERE parasut_id = ?");

        if (isset($response['data'])) {
            foreach ($response['data'] as $item) {
                $parasutId = $item['id'];
                $attr = $item['attributes'];

                // Determine payment status
                $remaining = (float) ($attr['remaining'] ?? 0);
                $total = (float) ($attr['net_total'] ?? 0);
                $status = 'unpaid';
                if ($remaining <= 0) {
                    $status = 'paid';
                } elseif ($remaining < $total) {
                    $status = 'partially_paid';
                }

                $stmtPO->execute([
                    $parasutId,
                    $attr['invoice_no'] ?? '',
                    $attr['issue_date'],
                    $attr['due_date'] ?? null,
                    $attr['net_total'],
                    $attr['gross_total'] ?? $attr['net_total'],
                    $attr['currency'] ?? 'TRY',
                    $attr['description'] ?? '',
                    $status,
                    $remaining,
                    json_encode($item)
                ]);
                $upsertCount++;

                // Get local ID to insert items
                $stmtGetLocalId->execute([$parasutId]);
                $localPOId = $stmtGetLocalId->fetchColumn();

                if ($localPOId) {
                    $stmtDeleteItems->execute([$localPOId]);

                    $detailIds = [];
                    if (isset($item['relationships']['purchase_bill_details']['data'])) {
                        foreach ($item['relationships']['purchase_bill_details']['data'] as $rel) {
                            $detailIds[] = $rel['id'];
                        }
                    }

                    foreach ($detailIds as $dId) {
                        if (isset($billDetailsMap[$dId])) {
                            $inc = $billDetailsMap[$dId];
                            $pId = $inc['relationships']['product']['data']['id'] ?? null;
                            $localProductId = null;
                            if ($pId) {
                                $stmtGetLocalProdId->execute([$pId]);
                                $localProductId = $stmtGetLocalProdId->fetchColumn();
                            }

                            $productName = $productsMap[$pId]['name'] ?? $inc['attributes']['product_name'] ?? 'Bilinmeyen Ürün';

                            $stmtInsertItem->execute([
                                $localPOId,
                                $localProductId,
                                $dId,
                                $productName,
                                $inc['attributes']['quantity'] ?? 0,
                                $inc['attributes']['unit_price'] ?? 0,
                                $inc['attributes']['discount'] ?? 0,
                                $inc['attributes']['vat_rate'] ?? 0,
                                $inc['attributes']['net_total'] ?? 0
                            ]);
                            $itemsCount++;
                        }
                    }
                }
            }
        }

        return ['purchase_orders' => $upsertCount, 'items' => $itemsCount];
    }

    // ==================== BIDIRECTIONAL SYNC FUNCTIONS ====================

    /**
     * Create a full sales invoice in Parasut (JSONAPI format)
     * @param string $contactId Parasut contact ID
     * @param array $lineItems [{product_id, quantity, unit_price, vat_rate, discount, description}]
     * @param array $options [issue_date, due_date, currency, description, invoice_series, order_no, order_date]
     * @return array API response
     */
    public function createFullSalesInvoice($contactId, $lineItems, $options = [])
    {
        $details = [];
        foreach ($lineItems as $item) {
            $detail = [
                'type' => 'sales_invoice_details',
                'attributes' => [
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'vat_rate' => (float) ($item['vat_rate'] ?? 0),
                ],
                'relationships' => [
                    'product' => [
                        'data' => [
                            'id' => (string) $item['product_id'],
                            'type' => 'products'
                        ]
                    ]
                ]
            ];

            if (!empty($item['discount'])) {
                $detail['attributes']['discount_type'] = 'amount';
                $detail['attributes']['discount_value'] = (float) $item['discount'];
            }
            if (!empty($item['description'])) {
                $detail['attributes']['description'] = $item['description'];
            }

            $details[] = $detail;
        }

        $attributes = [
            'item_type' => 'invoice',
            'issue_date' => $options['issue_date'] ?? date('Y-m-d'),
            'currency' => $options['currency'] ?? 'TRY',
        ];

        if (!empty($options['due_date'])) {
            $attributes['due_date'] = $options['due_date'];
        }
        if (!empty($options['description'])) {
            $attributes['description'] = $options['description'];
        }
        if (!empty($options['order_no'])) {
            $attributes['order_no'] = $options['order_no'];
        }
        if (!empty($options['order_date'])) {
            $attributes['order_date'] = $options['order_date'];
        }
        if (!empty($options['invoice_series'])) {
            $attributes['invoice_series'] = $options['invoice_series'];
        }

        $data = [
            'data' => [
                'type' => 'sales_invoices',
                'attributes' => $attributes,
                'relationships' => [
                    'contact' => [
                        'data' => [
                            'id' => (string) $contactId,
                            'type' => 'contacts'
                        ]
                    ],
                    'details' => [
                        'data' => $details
                    ]
                ]
            ]
        ];

        writeLog("Creating Parasut Sales Invoice: contact=$contactId, items=" . count($lineItems));
        return $this->request('POST', '/sales_invoices', $data);
    }

    /**
     * Check if a contact is an e-invoice user (has e-invoice inbox)
     * @param string $vkn Tax identification number (VKN/TCKN)
     * @return array|null E-invoice inbox data or null
     */
    public function checkEInvoiceInbox($vkn)
    {
        if (empty($vkn))
            return null;

        try {
            $endpoint = "/e_invoice_inboxes?filter[vkn]=" . urlencode($vkn);
            $response = $this->request('GET', $endpoint);

            if (!empty($response['data'])) {
                writeLog("E-Invoice Inbox found for VKN: $vkn");
                return $response['data'][0];
            }
        } catch (Exception $e) {
            writeLog("E-Invoice inbox check failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Create an e-invoice for a sales invoice (formalization)
     * @param string $salesInvoiceId Parasut sales invoice ID
     * @param array $options [scenario, note, vkn, e_invoice_inbox_id]
     * @return array API response with trackable_job ID
     */
    public function createEInvoice($salesInvoiceId, $options = [])
    {
        $attributes = [
            'vat_withholding_code' => $options['vat_withholding_code'] ?? null,
            'vat_exemption_reason_code' => $options['vat_exemption_reason_code'] ?? null,
            'vat_exemption_reason' => $options['vat_exemption_reason'] ?? null,
            'note' => $options['note'] ?? '',
            'scenario' => $options['scenario'] ?? 'commercial', // commercial, basic
        ];

        // Clean null values
        $attributes = array_filter($attributes, fn($v) => $v !== null);

        $relationships = [
            'invoice' => [
                'data' => [
                    'id' => (string) $salesInvoiceId,
                    'type' => 'sales_invoices'
                ]
            ]
        ];

        if (!empty($options['e_invoice_inbox_id'])) {
            $relationships['e_invoice_inbox'] = [
                'data' => [
                    'id' => (string) $options['e_invoice_inbox_id'],
                    'type' => 'e_invoice_inboxes'
                ]
            ];
        }

        $data = [
            'data' => [
                'type' => 'e_invoices',
                'attributes' => $attributes,
                'relationships' => $relationships
            ]
        ];

        writeLog("Creating Parasut E-Invoice for Sales Invoice: $salesInvoiceId");
        return $this->request('POST', '/e_invoices', $data);
    }

    /**
     * Create an e-archive for a sales invoice (formalization)
     * @param string $salesInvoiceId Parasut sales invoice ID
     * @param array $options [note, vat_withholding_code, internet_sale]
     * @return array API response with trackable_job ID
     */
    public function createEArchive($salesInvoiceId, $options = [])
    {
        $attributes = [
            'vat_withholding_code' => $options['vat_withholding_code'] ?? null,
            'vat_exemption_reason_code' => $options['vat_exemption_reason_code'] ?? null,
            'note' => $options['note'] ?? '',
            'internet_sale' => $options['internet_sale'] ?? null,
        ];

        $attributes = array_filter($attributes, fn($v) => $v !== null);

        $data = [
            'data' => [
                'type' => 'e_archives',
                'attributes' => $attributes,
                'relationships' => [
                    'sales_invoice' => [
                        'data' => [
                            'id' => (string) $salesInvoiceId,
                            'type' => 'sales_invoices'
                        ]
                    ]
                ]
            ]
        ];

        writeLog("Creating Parasut E-Archive for Sales Invoice: $salesInvoiceId");
        return $this->request('POST', '/e_archives', $data);
    }

    /**
     * Get trackable job status (for async operations like e-invoice creation)
     * @param string $jobId Trackable job ID
     * @return array Job status data
     */
    public function getTrackableJob($jobId)
    {
        return $this->request('GET', "/trackable_jobs/$jobId");
    }

    /**
     * Poll a trackable job until completion (with timeout)
     * @param string $jobId Job ID
     * @param int $maxWaitSeconds Maximum seconds to wait
     * @return array Final job status
     */
    public function waitForTrackableJob($jobId, $maxWaitSeconds = 120)
    {
        $startTime = time();
        $pollInterval = 3; // seconds

        while (time() - $startTime < $maxWaitSeconds) {
            $result = $this->getTrackableJob($jobId);
            $status = $result['data']['attributes']['status'] ?? 'unknown';

            writeLog("Trackable Job $jobId status: $status");

            if ($status === 'done') {
                return $result;
            }
            if ($status === 'error') {
                $errors = $result['data']['attributes']['errors'] ?? [];
                throw new Exception("Parasut trackable job failed: " . json_encode($errors));
            }

            sleep($pollInterval);
            $pollInterval = min($pollInterval + 2, 10); // Exponential backoff
        }

        throw new Exception("Parasut trackable job $jobId timed out after {$maxWaitSeconds}s");
    }

    /**
     * Get e-invoice PDF URL
     * @param string $eInvoiceId E-Invoice ID
     * @return string|null PDF URL or null
     */
    public function getEInvoicePdf($eInvoiceId)
    {
        try {
            $response = $this->request('GET', "/e_invoices/$eInvoiceId/pdf");
            return $response['data']['attributes']['url'] ?? null;
        } catch (Exception $e) {
            writeLog("E-Invoice PDF fetch failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get e-archive PDF URL
     * @param string $eArchiveId E-Archive ID
     * @return string|null PDF URL or null
     */
    public function getEArchivePdf($eArchiveId)
    {
        try {
            $response = $this->request('GET', "/e_archives/$eArchiveId/pdf");
            return $response['data']['attributes']['url'] ?? null;
        } catch (Exception $e) {
            writeLog("E-Archive PDF fetch failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Search contact by name, email, or tax number
     * @param string $query Search query
     * @param string $field Field to search (name, email, tax_number)
     * @return array|null Contact data or null
     */
    public function findContact($query, $field = 'name')
    {
        if (empty($query))
            return null;

        $filterMap = [
            'name' => 'name',
            'email' => 'email',
            'tax_number' => 'tax_number',
        ];

        $filterField = $filterMap[$field] ?? 'name';
        $endpoint = "/contacts?filter[$filterField]=" . urlencode($query);

        try {
            $response = $this->request('GET', $endpoint);
            if (!empty($response['data'])) {
                return $response['data'][0];
            }
        } catch (Exception $e) {
            writeLog("Contact search failed ($field=$query): " . $e->getMessage());
        }

        return null;
    }
}
