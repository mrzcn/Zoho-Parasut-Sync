<?php
// controllers/InvoiceController.php

class InvoiceController extends BaseController
{
    // ==================== PARASUT INVOICES ====================

    public function fetch_parasut_invoices(): void
    {
        enableLongRunningMode();

        $fullSync = isset($_POST['full_sync']) && $_POST['full_sync'] === 'true';

        try {
            $syncResult = $this->parasut()->syncInvoices(500, $fullSync);
            $upsertCount = $syncResult['invoices'];

            $this->pdo->exec("UPDATE parasut_products p 
                SET invoice_count = (
                    SELECT COUNT(DISTINCT invoice_id) 
                    FROM parasut_invoice_items ii 
                    WHERE ii.parasut_product_id = p.id
                )");

            $invoicesData = getInvoicesFromDB($this->pdo, 1, 250, null, null);

            jsonResponse([
                'success' => true,
                'message' => "Paraşüt'ten $upsertCount kayıt güncellendi.",
                'inserted_count' => $upsertCount,
                'data' => $invoicesData['data'],
                'meta' => $invoicesData['meta']
            ]);
        } catch (Exception $e) {
            writeLog("Fetch Error: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function get_parasut_invoices(): void
    {
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 200);
        $syncStatus = $this->input('sync_status');
        $year = $this->input('year');

        $invoicesData = getInvoicesFromDB($this->pdo, $page, $limit, $syncStatus, $year);

        $stmtMax = $this->pdo->query("SELECT MAX(issue_date) FROM parasut_invoices");
        $invoicesData['meta']['latest_issue_date'] = $stmtMax->fetchColumn();

        jsonResponse(['success' => true, 'data' => $invoicesData['data'], 'meta' => $invoicesData['meta']]);
    }

    public function fetch_parasut_invoice_details(): void
    {
        $id = $this->input('id');

        $response = $this->parasut()->getInvoiceDetails($id);

        $stmt = $this->pdo->prepare("SELECT id FROM parasut_invoices WHERE parasut_id = ?");
        $stmt->execute([$id]);
        $localInvoiceId = $stmt->fetchColumn();

        if (!$localInvoiceId) {
            $data = $response['data'];
            $stmt = $this->pdo->prepare("INSERT INTO parasut_invoices (parasut_id, invoice_number, issue_date, net_total, currency, description, invoice_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $id,
                $data['attributes']['invoice_id'] ?? $data['attributes']['invoice_no'] ?? '',
                $data['attributes']['issue_date'] ?? null,
                $data['attributes']['net_total'] ?? 0,
                $data['attributes']['currency'] ?? 'TRY',
                $data['attributes']['description'] ?? 'Detaydan oluşturuldu',
                $data['attributes']['item_type'] ?? 'invoice'
            ]);
            $localInvoiceId = $this->pdo->lastInsertId();
        }

        $productsMap = [];
        if (isset($response['included'])) {
            foreach ($response['included'] as $inc) {
                if ($inc['type'] === 'products')
                    $productsMap[$inc['id']] = $inc['attributes'];
            }
        }

        $this->pdo->prepare("DELETE FROM parasut_invoice_items WHERE invoice_id = ?")->execute([$localInvoiceId]);

        $insertStmt = $this->pdo->prepare("INSERT INTO parasut_invoice_items 
            (invoice_id, parasut_product_id, parasut_detail_id, product_name, quantity, unit_price, discount_amount, vat_rate, net_total) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (isset($response['included'])) {
            foreach ($response['included'] as $inc) {
                if ($inc['type'] === 'sales_invoice_details' || $inc['type'] === 'sales_invoice_detail') {
                    $pProductId = $inc['relationships']['product']['data']['id'] ?? null;
                    $productName = $productsMap[$pProductId]['name'] ?? 'Bilinmeyen Ürün';

                    $localProductId = null;
                    if ($pProductId) {
                        $pStmt = $this->pdo->prepare("SELECT id FROM parasut_products WHERE parasut_id = ?");
                        $pStmt->execute([$pProductId]);
                        $localProductId = $pStmt->fetchColumn();
                    }

                    $insertStmt->execute([
                        $localInvoiceId,
                        $localProductId,
                        $inc['id'],
                        $productName,
                        $inc['attributes']['quantity'] ?? 0,
                        $inc['attributes']['unit_price'] ?? 0,
                        $inc['attributes']['discount_amount'] ?? 0,
                        $inc['attributes']['vat_rate'] ?? 0,
                        $inc['attributes']['net_total'] ?? 0
                    ]);
                }
            }
        }

        jsonResponse(['success' => true, 'data' => getInvoiceItemsFromDB($this->pdo, $localInvoiceId)]);
    }

    public function get_parasut_invoice_details(): void
    {
        $parasutId = $this->input('id');

        $stmt = $this->pdo->prepare("SELECT id FROM parasut_invoices WHERE parasut_id = ?");
        $stmt->execute([$parasutId]);
        $localInvoiceId = $stmt->fetchColumn();

        if ($localInvoiceId) {
            $lineItems = getInvoiceItemsFromDB($this->pdo, $localInvoiceId);
            if (!empty($lineItems)) {
                jsonResponse(['success' => true, 'data' => $lineItems]);
            }
        }

        jsonResponse(['success' => true, 'data' => []]);
    }

    public function get_invoice_sync_status(): void
    {
        $stmt = $this->pdo->query("SELECT parasut_invoice_id, zoho_invoice_id, last_synced_at AS synced_at, sync_count FROM invoice_mapping");
        $syncs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $syncMap = [];
        foreach ($syncs as $sync) {
            $syncMap[$sync['parasut_invoice_id']] = $sync;
        }

        jsonResponse(['success' => true, 'data' => $syncMap]);
    }

    public function get_parasut_invoices_list(): void
    {
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 50);
        $search = $this->input('search', '');
        $syncStatus = $this->input('sync_status');
        $year = $this->input('year');

        $offset = ($page - 1) * $limit;
        $params = [];
        $where = ["1=1"];

        if (!empty($search)) {
            $where[] = "(invoice_number LIKE :search OR description LIKE :search)";
            $params[':search'] = "%$search%";
        }
        if ($syncStatus !== null && $syncStatus !== '') {
            $where[] = "synced_to_zoho = :sync_status";
            $params[':sync_status'] = $syncStatus;
        }
        if (!empty($year)) {
            $where[] = "YEAR(issue_date) = :year";
            $params[':year'] = $year;
        }

        $whereSql = implode(" AND ", $where);

        $sql = "SELECT id, parasut_id, invoice_number, issue_date, net_total, currency, synced_to_zoho, zoho_invoice_id, description, sync_error 
                FROM parasut_invoices WHERE $whereSql ORDER BY issue_date DESC, id DESC LIMIT $limit OFFSET $offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val)
            $stmt->bindValue($key, $val);
        $stmt->execute();
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM parasut_invoices WHERE $whereSql");
        foreach ($params as $key => $val)
            $countStmt->bindValue($key, $val);
        $countStmt->execute();
        $total = $countStmt->fetchColumn();

        jsonResponse([
            'success' => true,
            'data' => $invoices,
            'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'last_page' => ceil($total / $limit)]
        ]);
    }

    public function get_unsynced_invoices_ids(): void
    {
        $limit = $this->inputInt('limit', 50);
        $syncStatus = $this->input('sync_status', '0');
        $year = $this->input('year');

        // Auto-recover stuck in-progress records (status=3 older than 5 min)
        $this->pdo->exec("UPDATE parasut_invoices SET synced_to_zoho = 0, sync_error = 'Stuck in-progress - auto recovered' WHERE synced_to_zoho = 3 AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

        $params = [];
        $where = ["1=1"];

        if ($syncStatus === 'error')
            $where[] = "synced_to_zoho = 2";
        elseif ($syncStatus === 'all')
            $where[] = "(synced_to_zoho != 1 OR synced_to_zoho IS NULL)";
        else
            $where[] = "(synced_to_zoho = 0 OR synced_to_zoho IS NULL)";

        if (!empty($year)) {
            $where[] = "YEAR(issue_date) = :year";
            $params[':year'] = $year;
        }

        $whereSql = implode(" AND ", $where);

        $stmt = $this->pdo->prepare("SELECT parasut_id as id, invoice_number FROM parasut_invoices WHERE $whereSql ORDER BY issue_date DESC LIMIT :limit");
        foreach ($params as $key => $val)
            $stmt->bindValue($key, $val);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM parasut_invoices WHERE $whereSql");
        foreach ($params as $key => $val)
            $countStmt->bindValue($key, $val);
        $countStmt->execute();

        jsonResponse(['success' => true, 'count' => count($invoices), 'total_remaining' => $countStmt->fetchColumn(), 'data' => $invoices]);
    }

    // ==================== ZOHO INVOICES ====================

    public function fetch_zoho_invoices(): void
    {
        enableLongRunningMode();

        $zoho = $this->zoho();
        $upsertCount = 0;
        $pageToken = null;

        $stmtUpsert = $this->pdo->prepare("INSERT INTO zoho_invoices 
            (id, invoice_number, invoice_date, total, currency, status, customer_name, description, raw_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            invoice_number = VALUES(invoice_number), invoice_date = VALUES(invoice_date),
            total = VALUES(total), currency = VALUES(currency), status = VALUES(status),
            customer_name = VALUES(customer_name), description = VALUES(description), raw_data = VALUES(raw_data)");

        $saveBatch = function ($invoices) use ($stmtUpsert, &$upsertCount) {
            foreach ($invoices as $inv) {
                $rawNumber = $inv['Invoice_Number'];
                $extractedNumber = $rawNumber;
                $sourceText = $inv['Description'] ?? $inv['Subject'] ?? '';

                if (strpos($sourceText, '#') !== false) {
                    $parts = explode('#', $sourceText, 2);
                    if (count($parts) > 1)
                        $extractedNumber = trim($parts[1]);
                }

                $stmtUpsert->execute([
                    $inv['id'],
                    $extractedNumber,
                    $inv['Invoice_Date'],
                    $inv['Grand_Total'],
                    $inv['Currency'],
                    $inv['Status'],
                    $inv['Account_Name']['name'] ?? '',
                    $sourceText,
                    json_encode($inv)
                ]);
                $upsertCount++;
            }
        };

        writeLog("Starting Zoho invoice fetch - Phase 1: Page-based pagination (first 2000)");

        for ($page = 1; $page <= 10; $page++) {
            $response = $zoho->getInvoices($page, 200, null);
            if (!empty($response['data'])) {
                $saveBatch($response['data']);
                writeLog("Page $page: Saved " . count($response['data']) . " invoices. Total: $upsertCount");
            }
            if (!$response['more_records']) {
                jsonResponse(['success' => true, 'message' => "Zoho'dan $upsertCount fatura çekildi.", 'count' => $upsertCount]);
                return;
            }
            if ($page == 10 && !empty($response['next_page_token'])) {
                $pageToken = $response['next_page_token'];
            }
        }

        if ($pageToken) {
            writeLog("Starting Phase 2: Token-based pagination (beyond 2000)");
            $tokenLoops = 0;
            while ($pageToken && $tokenLoops < 100) {
                $response = $zoho->getInvoices(1, 200, $pageToken);
                if (!empty($response['data'])) {
                    $saveBatch($response['data']);
                    writeLog("Token batch: Saved " . count($response['data']) . ". Total: $upsertCount");
                }
                $pageToken = $response['next_page_token'];
                $tokenLoops++;
                if (!$response['more_records'])
                    break;
            }
        }

        writeLog("Completed fetching $upsertCount invoices from Zoho");
        jsonResponse(['success' => true, 'message' => "Zoho'dan $upsertCount fatura çekildi.", 'count' => $upsertCount]);
    }

    // ==================== CONTACTS ====================

    public function fetch_contacts(): void
    {
        $pResponse = $this->parasut()->getContacts();
        $parasutContacts = $pResponse['data'] ?? [];

        $zResponse = $this->zoho()->getAccounts();
        $zohoAccounts = $zResponse['data'] ?? [];

        $data = [
            'parasut' => array_map(fn($c) => [
                'id' => $c['id'],
                'name' => $c['attributes']['name'],
                'email' => $c['attributes']['email'] ?? '',
                'phone' => $c['attributes']['phone'] ?? '',
                'tax_number' => $c['attributes']['tax_number'] ?? ''
            ], $parasutContacts),
            'zoho' => array_map(fn($z) => [
                'id' => $z['id'],
                'name' => $z['Account_Name'],
                'phone' => $z['Phone'] ?? '',
                'website' => $z['Website'] ?? ''
            ], $zohoAccounts)
        ];

        jsonResponse(['success' => true, 'data' => $data]);
    }

    public function create_zoho_account(): void
    {
        $name = $this->input('name');
        $phone = $this->input('phone', '');
        $email = $this->input('email', '');

        $res = $this->zoho()->createAccount($name, $phone, $email);

        if (isset($res['data'][0]['details']['id'])) {
            jsonResponse(['success' => true, 'message' => 'Müşteri oluşturuldu.', 'zoho_id' => $res['data'][0]['details']['id']]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Oluşturulamadı: ' . json_encode($res)], 400);
        }
    }

    // ==================== EXPORT INVOICE TO ZOHO ====================

    public function export_invoice_to_zoho(): void
    {
        try {
            $invoiceId = $this->input('invoice_id');
            $force = filter_var($this->input('force', false), FILTER_VALIDATE_BOOLEAN);

            // === Layer 1: DB Lock — Prevent concurrent duplicate ===
            $this->pdo->beginTransaction();

            $checkStmt = $this->pdo->prepare("SELECT zoho_invoice_id, synced_to_zoho, invoice_number FROM parasut_invoices WHERE parasut_id = ? FOR UPDATE");
            $checkStmt->execute([$invoiceId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$force && $existing && ($existing['synced_to_zoho'] == 1 || !empty($existing['zoho_invoice_id']))) {
                $this->pdo->rollBack();
                jsonResponse(['success' => true, 'message' => "Fatura zaten Zoho'ya aktarılmış.", 'zoho_id' => $existing['zoho_invoice_id']]);
                return;
            }

            // Mark as in-progress to prevent concurrent export
            $this->pdo->prepare("UPDATE parasut_invoices SET synced_to_zoho = 3 WHERE parasut_id = ?")->execute([$invoiceId]);
            $this->pdo->commit();

            $parasut = $this->parasut();
            $zoho = $this->zoho();

            // === Layer 2: Zoho Duplicate Search ===
            $invoiceNo = $existing['invoice_number'] ?? '';
            if (!$force && !empty($invoiceNo)) {
                $existingInvoice = $zoho->searchInvoiceByNumber($invoiceNo);
                if ($existingInvoice) {
                    $zohoId = $existingInvoice['id'];
                    writeLog("Invoice duplicate found in Zoho: $invoiceNo → $zohoId");
                    $this->pdo->prepare("UPDATE parasut_invoices SET zoho_invoice_id = ?, synced_to_zoho = 1, synced_at = NOW(), sync_error = NULL WHERE parasut_id = ?")
                        ->execute([$zohoId, $invoiceId]);
                    jsonResponse(['success' => true, 'message' => "Fatura zaten Zoho'da mevcut (mükerrer engellendi).", 'zoho_id' => $zohoId]);
                    return;
                }
            }

            // 1. Fetch Full Invoice Data from Paraşüt
            $pInvoice = $parasut->getInvoiceDetails($invoiceId);

            // Validation: Check for items without product code
            if (isset($pInvoice['included'])) {
                foreach ($pInvoice['included'] as $inc) {
                    if ($inc['type'] === 'sales_invoice_details') {
                        $prodId = $inc['relationships']['product']['data']['id'] ?? null;
                        $prodCode = null;
                        if ($prodId) {
                            foreach ($pInvoice['included'] as $i2) {
                                if ($i2['type'] === 'products' && $i2['id'] == $prodId) {
                                    $prodCode = $i2['attributes']['code'] ?? null;
                                    break;
                                }
                            }
                        }
                        if (empty($prodCode)) {
                            throw new Exception("Fatura, ürün kodu olmayan ürünler içeriyor. Senkronize edilemez.");
                        }
                    }
                }
            }

            if (!isset($pInvoice['data'])) {
                throw new Exception("Paraşüt Fatura verisi alınamadı.");
            }

            $invoiceData = $pInvoice['data'];
            $included = $pInvoice['included'] ?? [];
            $invoiceAttribs = $invoiceData['attributes'] ?? [];

            // 2. Extract Contact, Products, and E-Invoice data
            $contact = null;
            $productsMap = [];
            $eInvoiceNotes = [];
            $parasutPdfUrl = '';

            foreach ($included as $inc) {
                if ($inc['type'] === 'contacts')
                    $contact = $inc['attributes'];
                if ($inc['type'] === 'products')
                    $productsMap[$inc['id']] = $inc['attributes'];
                if ($inc['type'] === 'active_e_document' || $inc['type'] === 'e_document' || $inc['type'] === 'e_invoices') {
                    $eInvoiceAttribs = $inc['attributes'] ?? [];
                    $signedUblUrl = $eInvoiceAttribs['signed_ubl_url'] ?? '';

                    // Extract notes from e-invoice
                    foreach (['note', 'notes'] as $noteField) {
                        if (!empty($eInvoiceAttribs[$noteField])) {
                            if (is_array($eInvoiceAttribs[$noteField])) {
                                $eInvoiceNotes = array_merge($eInvoiceNotes, $eInvoiceAttribs[$noteField]);
                            } elseif (is_string($eInvoiceAttribs[$noteField])) {
                                $eInvoiceNotes = array_merge($eInvoiceNotes, array_filter(explode("\n", $eInvoiceAttribs[$noteField])));
                            }
                        }
                    }

                    // Fetch XML notes if UBL URL available
                    if (!empty($signedUblUrl)) {
                        $xmlNotes = $parasut->fetchEInvoiceXmlNotes($inc['id']);
                        if (!empty($xmlNotes) && count($xmlNotes) > count($eInvoiceNotes)) {
                            $eInvoiceNotes = $xmlNotes;
                        }
                    }

                    // Extract PDF URL
                    $pdfUrlCandidate = $eInvoiceAttribs['signed_pdf_url'] ?? $eInvoiceAttribs['pdf_url'] ?? '';
                    if (!empty($pdfUrlCandidate))
                        $parasutPdfUrl = $pdfUrlCandidate;
                }
            }

            // Calculate VAT Rate from totals
            $calculatedVatRate = 0;
            $iNet = $invoiceAttribs['net_total'] ?? 0;
            $iGross = $invoiceAttribs['gross_total'] ?? 0;
            $grandTotal = max($iNet, $iGross);
            $subTotal = min($iNet, $iGross);

            if ($subTotal > 0) {
                $rateRaw = (($grandTotal - $subTotal) / $subTotal) * 100;
                foreach ([0, 1, 8, 10, 18, 20] as $r) {
                    if (abs($rateRaw - $r) < 0.5) {
                        $calculatedVatRate = $r;
                        break;
                    }
                }
            }

            if (!$contact)
                throw new Exception("Fatura için Müşteri (Contact) bilgisi bulunamadı.");

            // 3. Sync Account (Customer)
            $accountName = $contact['name'];
            $accountEmail = $contact['email'] ?? '';
            $accountPhone = preg_replace('/[^0-9+\-]/', '', $contact['phone'] ?? '');
            if (empty($accountPhone) || strlen($accountPhone) < 5)
                $accountPhone = null;
            $accountWebsite = $contact['website'] ?? '';
            if (empty($accountWebsite) && !empty($accountEmail)) {
                $emailParts = explode('@', $accountEmail);
                if (count($emailParts) === 2)
                    $accountWebsite = $emailParts[1];
            }

            // Billing address
            $billingStreet = $contact['address'] ?? '';
            $billingCity = $contact['city'] ?? '';
            $billingDistrict = $contact['district'] ?? '';
            $billingCountry = $contact['country'] ?? 'Türkiye';

            // Search Zoho Account (multiple strategies)
            $zohoAccountId = null;
            if (!$zohoAccountId && !empty($accountWebsite)) {
                $zohoAccount = $zoho->searchAccountByWebsite($accountWebsite);
                if ($zohoAccount)
                    $zohoAccountId = $zohoAccount['id'];
            }
            if (!$zohoAccountId) {
                $zohoAccount = $zoho->searchAccount($accountName);
                if ($zohoAccount)
                    $zohoAccountId = $zohoAccount['id'];
            }
            if (!$zohoAccountId && !empty($accountPhone)) {
                $zohoAccount = $zoho->searchAccountByPhone($accountPhone);
                if ($zohoAccount)
                    $zohoAccountId = $zohoAccount['id'];
            }

            if (!$zohoAccountId) {
                $emailForZoho = !empty($accountEmail) ? $accountEmail : "no-email-" . uniqid() . "@example.com";
                $accountExtras = [
                    'tax_number' => $contact['tax_number'] ?? '',
                    'tax_office' => $contact['tax_office'] ?? '',
                    'billing_street' => $billingStreet,
                    'billing_city' => $billingCity,
                    'billing_state' => $billingDistrict,
                    'billing_country' => $billingCountry,
                    'website' => $accountWebsite
                ];
                $zohoAccountId = $zoho->createAccount($accountName, $emailForZoho, $accountPhone, $accountExtras);
            }

            if (!$zohoAccountId)
                throw new Exception("Zoho Müşteri (Account) oluşturulamadı.");

            // 4. Sync Products & Line Items
            $zohoLineItems = [];
            $dbTaxMap = [];
            try {
                $stmtTax = $this->pdo->prepare("SELECT pii.parasut_product_id, pii.vat_rate FROM parasut_invoice_items pii JOIN parasut_invoices pi ON pii.invoice_id = pi.id WHERE pi.parasut_id = ?");
                $stmtTax->execute([$invoiceId]);
                foreach ($stmtTax->fetchAll(PDO::FETCH_ASSOC) as $dbi) {
                    $dbTaxMap[$dbi['parasut_product_id']] = $dbi['vat_rate'];
                }
            } catch (Exception $e) {
                writeLog("Warning: Could not fetch local VAT rates: " . $e->getMessage());
            }

            foreach ($included as $inc) {
                if ($inc['type'] === 'sales_invoice_details') {
                    $pProductId = $inc['relationships']['product']['data']['id'] ?? null;
                    $quantity = $inc['attributes']['quantity'];
                    $unitPrice = $inc['attributes']['unit_price'];
                    $vatRate = $inc['attributes']['vat_rate'] ?? 0;

                    if ($pProductId && isset($dbTaxMap[$pProductId]))
                        $vatRate = $dbTaxMap[$pProductId];
                    if ($vatRate == 0 && $calculatedVatRate > 0)
                        $vatRate = $calculatedVatRate;

                    $pProduct = $productsMap[$pProductId] ?? null;
                    if ($pProduct) {
                        $pCode = $pProduct['code'];
                        $pName = $pProduct['name'];
                        $zProduct = $zoho->searchProduct($pCode);
                        $zProductId = $zProduct['id'] ?? null;

                        if (!$zProductId) {
                            $createRes = $zoho->createProduct(['name' => $pName, 'code' => $pCode, 'price' => $unitPrice, 'vat_rate' => $vatRate]);
                            $zProductId = $createRes['data'][0]['details']['id'] ?? null;
                        }

                        if ($zProductId) {
                            $zohoLineItems[] = [
                                'product_id' => $zProductId,
                                'quantity' => $quantity,
                                'price' => $unitPrice,
                                'vat_rate' => $vatRate,
                                'discount' => (float) ($inc['attributes']['discount_amount'] ?? $inc['attributes']['discount'] ?? 0)
                            ];
                        }
                    }
                }
            }

            if (empty($zohoLineItems))
                throw new Exception("Fatura kalemleri (Ürünler) Zoho'ya aktarılamadı.");

            // 5. Extract Additional Invoice Data
            $invoiceNumber = $invoiceAttribs['invoice_id'] ?? $invoiceAttribs['invoice_no'] ?? '';
            $invoiceDate = $invoiceAttribs['issue_date'] ?? '';
            $dueDate = $invoiceAttribs['due_date'] ?? '';
            $currency = $invoiceAttribs['currency'] ?? 'TRY';
            if ($currency === 'TRL')
                $currency = 'TRY';

            // Notes
            $notesArray = !empty($eInvoiceNotes) ? $eInvoiceNotes : [];
            if (empty($notesArray)) {
                foreach (['invoice_note', 'e_invoice_notes', 'notes', 'invoice_notes', 'Note'] as $field) {
                    if (!empty($invoiceAttribs[$field])) {
                        if (is_array($invoiceAttribs[$field]))
                            $notesArray = array_merge($notesArray, $invoiceAttribs[$field]);
                        elseif (is_string($invoiceAttribs[$field]))
                            $notesArray = array_merge($notesArray, array_filter(explode("\n", $invoiceAttribs[$field])));
                    }
                }
            }

            $netTotal = $invoiceAttribs['net_total'] ?? $invoiceAttribs['gross_total'] ?? 0;
            if (!empty($netTotal) && !empty($currency)) {
                $notesArray[] = amountToTurkishWords($netTotal, $currency);
            }
            $notesContent = implode("\n", $notesArray);
            $description = $invoiceAttribs['description'] ?? '';

            // Terms and Conditions
            $currencyDisplay = strtoupper($currency);
            if ($currency === 'TRY' || $currency === 'TRL')
                $currencyDisplay = 'TL';

            // E-document status
            $invoiceStatus = 'Oluşturuldu';
            foreach ($included as $inc) {
                if ($inc['type'] === 'active_e_document' || $inc['type'] === 'e_document' || $inc['type'] === 'e_invoices') {
                    $eDocStatus = $inc['attributes']['status'] ?? '';
                    if (!empty($eDocStatus)) {
                        $statusMap = ['successful' => 'Onaylandı', 'approved' => 'Onaylandı', 'waiting' => 'Onay Bekliyor', 'waiting_for_approval' => 'Onay Bekliyor', 'pending' => 'GİB\'den Onay Bekliyor', 'gib_waiting' => 'GİB\'den Onay Bekliyor', 'error' => 'Zarf Hatalı/Hata Var', 'failed' => 'Zarf Hatalı/Hata Var', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal Edildi', 'sent' => 'Gönderildi', 'draft' => 'Taslak'];
                        $invoiceStatus = $statusMap[$eDocStatus] ?? $inc['attributes']['status_message'] ?? ucfirst($eDocStatus);
                    }
                    break;
                }
            }

            // 6. Create Invoice in Zoho
            $descriptionRaw = $description ?: $invoiceNumber ?: $invoiceId;
            $subject = mb_strlen($descriptionRaw) > 120 ? mb_substr($descriptionRaw, 0, 117) . '...' : $descriptionRaw;

            $invoiceOptions = [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'billing_street' => $billingStreet,
                'billing_city' => $billingCity,
                'billing_state' => $billingDistrict,
                'billing_country' => $billingCountry,
                'description' => "Fatura #" . ($invoiceNumber ?: $invoiceId),
                'status' => $invoiceStatus,
                'exchange_rate' => $invoiceAttribs['exchange_rate'] ?? null
            ];

            $parasutCompanyId = getSetting($this->pdo, 'parasut_company_id') ?: '136555';
            $invoiceOptions['parasut_url'] = "https://uygulama.parasut.com/$parasutCompanyId/satislar/$invoiceId";
            if (!empty($parasutPdfUrl))
                $invoiceOptions['parasut_pdf_url'] = $parasutPdfUrl;

            // Payment status
            $remaining = (float) ($invoiceAttribs['remaining_payment'] ?? 0);
            $netTotalFloat = (float) ($invoiceAttribs['net_total'] ?? 0);
            if ($remaining <= 0 && $netTotalFloat > 0)
                $invoiceOptions['payment_status'] = 'Ödendi';
            elseif ($remaining < $netTotalFloat && $remaining > 0)
                $invoiceOptions['payment_status'] = 'Kısmi Ödeme';
            else
                $invoiceOptions['payment_status'] = 'Ödenmedi';

            $zohoInvoice = $zoho->createInvoice($subject, $zohoAccountId, $zohoLineItems, $currency, $invoiceOptions);

            // Tax auto-fix: If Zoho rejects because product doesn't have required tax,
            // assign all org taxes to the product and retry once
            $firstResult = $zohoInvoice['data'][0] ?? [];
            if (
                isset($firstResult['code']) && $firstResult['code'] === 'INVALID_DATA' &&
                isset($firstResult['message']) && stripos($firstResult['message'], 'tax is not present') !== false
            ) {
                writeLog("Tax error detected — auto-fixing product taxes and retrying...");

                // Fix all products used in this invoice
                foreach ($zohoLineItems as $item) {
                    $zoho->ensureProductTaxes($item['product_id']);
                }

                // Retry invoice creation
                $zohoInvoice = $zoho->createInvoice($subject, $zohoAccountId, $zohoLineItems, $currency, $invoiceOptions);
            }

            if (isset($zohoInvoice['data'][0]['details']['id'])) {
                $zohoInvoiceId = $zohoInvoice['data'][0]['details']['id'];

                // Add notes
                if (!empty($notesContent)) {
                    try {
                        $zoho->addNote('Invoices', $zohoInvoiceId, $notesContent);
                    } catch (Exception $e) {
                        writeLog("Warning: Could not add notes: " . $e->getMessage());
                    }
                }

                // Update DB
                $zohoTotal = 0;
                foreach ($zohoLineItems as $item)
                    $zohoTotal += ($item['quantity'] * $item['price']);

                try {
                    $this->pdo->prepare("UPDATE parasut_invoices SET zoho_invoice_id = ?, zoho_total = ?, synced_to_zoho = 1, synced_at = NOW() WHERE parasut_id = ?")
                        ->execute([$zohoInvoiceId, $zohoTotal, $invoiceId]);
                } catch (Exception $e) {
                    writeLog("Warning: Could not update sync status: " . $e->getMessage());
                }

                jsonResponse(['success' => true, 'message' => 'Fatura Zoho\'ya başarıyla aktarıldı. ID: ' . $zohoInvoiceId]);
            } else {
                throw new Exception("Zoho Fatura Oluşturma Hatası: " . ($zohoInvoice['data'][0]['message'] ?? 'Bilinmeyen hata'));
            }

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            writeLog("Export Error for Invoice $invoiceId: " . $errorMsg);

            // Recover status: 3 (in-progress) → 2 (error) so record can be retried
            try {
                $this->pdo->prepare("UPDATE parasut_invoices SET synced_to_zoho = 2, sync_error = ?, updated_at = NOW() WHERE parasut_id = ?")
                    ->execute([$errorMsg, $invoiceId]);
            } catch (Exception $dbEx) {
                writeLog("Could not save error to DB: " . $dbEx->getMessage());
            }

            jsonResponse(['success' => false, 'message' => $errorMsg], 500);
        }
    }

    // ==================== UPDATE ZOHO TAXES ====================

    public function update_zoho_taxes(): void
    {
        $invoiceId = $this->input('invoice_id');
        $zoho = $this->zoho();

        $stmt = $this->pdo->prepare("SELECT id, zoho_invoice_id FROM parasut_invoices WHERE parasut_id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice || empty($invoice['zoho_invoice_id'])) {
            jsonResponse(['success' => false, 'message' => 'Fatura Zoho\'da bulunamadı veya senkronize edilmemiş.']);
        }

        $localInvoiceId = $invoice['id'];
        $zohoInvoiceId = $invoice['zoho_invoice_id'];

        $zohoInvoice = $zoho->getInvoice($zohoInvoiceId);
        if (!$zohoInvoice)
            jsonResponse(['success' => false, 'message' => 'Zoho\'dan fatura detayları alınamadı.']);

        $zohoItemMap = [];
        if (isset($zohoInvoice['Invoiced_Items'])) {
            foreach ($zohoInvoice['Invoiced_Items'] as $zItem) {
                $zProdId = $zItem['Product_Name']['id'] ?? '';
                if ($zProdId)
                    $zohoItemMap[$zProdId] = $zItem['id'];
            }
        }

        $stmtItems = $this->pdo->prepare("SELECT ii.product_name, ii.quantity, ii.unit_price as price, ii.vat_rate, pp.product_code
            FROM parasut_invoice_items ii LEFT JOIN parasut_products pp ON ii.parasut_product_id = pp.id WHERE ii.invoice_id = ?");
        $stmtItems->execute([$localInvoiceId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $targetTaxName = "KDV 18";
        $lineItemsForZoho = [];

        foreach ($items as $item) {
            $pCode = $item['product_code'] ?? '';
            $pName = $item['product_name'] ?? '';
            $zProductId = null;

            if (!empty($pCode)) {
                $zProduct = $zoho->searchProduct($pCode);
                $zProductId = $zProduct['id'] ?? null;
            }
            if (!$zProductId && !empty($pName)) {
                $zProduct = $zoho->searchProductByName($pName);
                $zProductId = $zProduct['id'] ?? null;
            }

            if ($zProductId) {
                $lineData = ['product_id' => $zProductId, 'quantity' => $item['quantity'], 'price' => $item['price']];
                if (isset($zohoItemMap[$zProductId]))
                    $lineData['id'] = $zohoItemMap[$zProductId];
                if ($item['vat_rate'] > 0)
                    $lineData['tax_name'] = $targetTaxName;
                $lineItemsForZoho[] = $lineData;
            }
        }

        if (empty($lineItemsForZoho)) {
            jsonResponse(['success' => false, 'message' => 'Zoho\'da eşleşen ürün bulunamadı, güncelleme yapılamaz.']);
        }

        try {
            $zoho->updateInvoice($zohoInvoiceId, $lineItemsForZoho);
            jsonResponse(['success' => true, 'message' => 'Fatura vergileri (%18) Zoho\'da güncellendi.']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Zoho Hatası: ' . $e->getMessage()], 500);
        }
    }

    // ==================== INVOICE COMPARISON ====================

    public function get_invoices_comparison(): void
    {
        try {
            $page = $this->inputInt('page', 1);
            $limit = $this->inputInt('limit', 50);
            $search = trim($this->input('search', ''));
            $statusFilter = $this->input('status', '');
            $year = $this->input('year', '');
            $sortField = $this->input('sort_field', 'issue_date');
            $sortOrder = strtoupper($this->input('sort_order', 'DESC')) === 'DESC' ? 'DESC' : 'ASC';

            // Allowed sort fields
            $allowedSorts = ['issue_date', 'invoice_number', 'customer_name', 'zoho_total', 'parasut_total', 'match_status'];
            if ($sortField === 'status')
                $sortField = 'match_status';
            if (!in_array($sortField, $allowedSorts))
                $sortField = 'issue_date';

            // Year filter
            $yearCond = '';
            $yearParams = [];
            if (!empty($year) && $year !== 'ALL' && is_numeric($year)) {
                $yearCond = " AND YEAR(p.issue_date) = ?";
                $yearParams[] = (int) $year;
            }

            // Search filter  
            $searchCond = '';
            $searchParams = [];
            if (!empty($search)) {
                $searchCond = " AND (p.invoice_number LIKE ? OR z.customer_name LIKE ? OR p.description LIKE ?)";
                $searchLike = "%$search%";
                $searchParams = [$searchLike, $searchLike, $searchLike];
            }

            // If user wants only_zoho, use separate handler
            if ($statusFilter === 'only_zoho') {
                $this->getZohoOnlyInvoices($page, $limit, $year, $search, $sortField, $sortOrder);
                return;
            }

            // Main query: parasut LEFT JOIN zoho (no UNION - much faster!)
            $matchCase = "CASE 
                WHEN p.zoho_invoice_id IS NULL OR p.zoho_invoice_id = '' THEN 'only_parasut'
                WHEN ABS(COALESCE(p.net_total,0) - COALESCE(z.total,0)) <= 0.5 
                     AND LEFT(COALESCE(p.currency,''),2) = LEFT(COALESCE(z.currency,''),2) THEN 'matched'
                ELSE 'price_diff'
            END";

            $baseSql = "FROM parasut_invoices p 
                LEFT JOIN zoho_invoices z ON p.zoho_invoice_id = z.id
                WHERE p.deleted_at IS NULL $yearCond $searchCond";

            $statusCond = '';
            $statusParams = [];
            if (!empty($statusFilter)) {
                $statusCond = " AND $matchCase = ?";
                $statusParams[] = $statusFilter;
            }

            $allParams = array_merge($yearParams, $searchParams, $statusParams);

            // Stats (simple fast counts - no JOIN needed)
            $stats = ['total' => 0, 'matched' => 0, 'price_diff' => 0, 'only_zoho' => 0, 'only_parasut' => 0];
            try {
                $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN p.synced_to_zoho = 0 OR p.zoho_invoice_id IS NULL OR p.zoho_invoice_id = '' THEN 1 ELSE 0 END) as only_parasut,
                    SUM(CASE WHEN p.synced_to_zoho = 1 AND p.zoho_invoice_id IS NOT NULL AND p.zoho_invoice_id != '' THEN 1 ELSE 0 END) as synced
                FROM parasut_invoices p WHERE p.deleted_at IS NULL $yearCond";
                $statsStmt = $this->pdo->prepare($statsSql);
                $statsStmt->execute($yearParams);
                $s = $statsStmt->fetch(PDO::FETCH_ASSOC);
                $stats['total'] = (int) ($s['total'] ?? 0);
                $stats['only_parasut'] = (int) ($s['only_parasut'] ?? 0);
                $stats['matched'] = (int) ($s['synced'] ?? 0);
            } catch (Exception $e) {
                writeLog("Stats error: " . $e->getMessage());
            }

            // Count
            $countSql = "SELECT COUNT(*) $baseSql $statusCond";
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($allParams);
            $totalRecords = (int) $countStmt->fetchColumn();

            // Data
            $offset = ($page - 1) * $limit;
            $dataSql = "SELECT 
                p.issue_date,
                p.invoice_number,
                z.customer_name,
                p.description,
                p.parasut_id, p.net_total as parasut_total, p.currency as parasut_currency, p.payment_status as parasut_status,
                z.id as zoho_id, z.total as zoho_total, z.currency as zoho_currency, z.status as zoho_status,
                $matchCase as match_status
                $baseSql $statusCond
                ORDER BY $sortField $sortOrder LIMIT $limit OFFSET $offset";
            $dataStmt = $this->pdo->prepare($dataSql);
            $dataStmt->execute($allParams);

            jsonResponse([
                'success' => true,
                'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
                'stats' => $stats,
                'meta' => ['current_page' => $page, 'total_pages' => max(1, ceil($totalRecords / $limit)), 'total_records' => $totalRecords]
            ]);
        } catch (Exception $e) {
            writeLog("Invoice Comparison Error: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Sistem hatası: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Separate handler for zoho-only records (not linked to any parasut invoice)
     */
    private function getZohoOnlyInvoices(int $page, int $limit, string $year, string $search, string $sortField, string $sortOrder): void
    {
        $yearCond = '';
        $params = [];
        if (!empty($year) && $year !== 'ALL' && is_numeric($year)) {
            $yearCond = " AND YEAR(z.invoice_date) = ?";
            $params[] = (int) $year;
        }
        $searchCond = '';
        if (!empty($search)) {
            $searchCond = " AND (z.invoice_number LIKE ? OR z.customer_name LIKE ?)";
            $searchLike = "%$search%";
            $params = array_merge($params, [$searchLike, $searchLike]);
        }

        $baseSql = "FROM zoho_invoices z 
            WHERE NOT EXISTS (SELECT 1 FROM parasut_invoices p WHERE p.zoho_invoice_id = z.id AND p.deleted_at IS NULL)
            $yearCond $searchCond";

        $sortMap = ['issue_date' => 'z.invoice_date', 'match_status' => 'z.invoice_date', 'parasut_total' => 'z.total'];
        $dbSort = $sortMap[$sortField] ?? "z.$sortField";

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) $baseSql");
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $dataStmt = $this->pdo->prepare("SELECT 
            z.invoice_date as issue_date, z.invoice_number, z.customer_name, z.description,
            NULL as parasut_id, NULL as parasut_total, NULL as parasut_currency, NULL as parasut_status,
            z.id as zoho_id, z.total as zoho_total, z.currency as zoho_currency, z.status as zoho_status,
            'only_zoho' as match_status
            $baseSql ORDER BY $dbSort $sortOrder LIMIT $limit OFFSET $offset");
        $dataStmt->execute($params);

        jsonResponse([
            'success' => true,
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'stats' => ['total' => 0, 'matched' => 0, 'price_diff' => 0, 'only_zoho' => $totalRecords, 'only_parasut' => 0],
            'meta' => ['current_page' => $page, 'total_pages' => max(1, ceil($totalRecords / $limit)), 'total_records' => $totalRecords]
        ]);
    }

    // Also register the old action name as an alias
    public function get_invoice_comparison(): void
    {
        $this->get_invoices_comparison();
    }

    // ==================== FULL SYNC HELPERS ====================

    /**
     * Get synced invoices that may have status changes (non-final statuses)
     * Final statuses: synced_to_zoho=1 AND zoho_invoice_id IS NOT NULL
     * Only returns invoices where the Paraşüt payment may have changed.
     */
    public function get_status_update_candidates(): void
    {
        $limit = $this->inputInt('limit', 10);

        // Get synced invoices where payment could have changed
        // We check Paraşüt for remaining_payment changes
        $stmt = $this->pdo->prepare("
            SELECT pi.parasut_id as id, pi.invoice_number, pi.zoho_invoice_id, pi.net_total, pi.currency, pi.issue_date
            FROM parasut_invoices pi
            WHERE pi.synced_to_zoho = 1 
              AND pi.zoho_invoice_id IS NOT NULL
              AND pi.zoho_invoice_id != ''
            ORDER BY pi.issue_date DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->pdo->query("
            SELECT COUNT(*) FROM parasut_invoices 
            WHERE synced_to_zoho = 1 AND zoho_invoice_id IS NOT NULL AND zoho_invoice_id != ''
        ");
        $total = $countStmt->fetchColumn();

        jsonResponse([
            'success' => true,
            'count' => count($invoices),
            'total' => (int) $total,
            'data' => $invoices
        ]);
    }

    /**
     * Update a single synced invoice's status in Zoho
     * Re-checks Paraşüt for payment status and updates Zoho accordingly
     */
    public function update_invoice_status_in_zoho(): void
    {
        $invoiceId = $this->input('invoice_id');
        $zohoInvoiceId = $this->input('zoho_invoice_id');

        if (!$invoiceId || !$zohoInvoiceId) {
            jsonResponse(['success' => false, 'message' => 'invoice_id ve zoho_invoice_id gerekli.'], 400);
        }

        try {
            $parasut = $this->parasut();
            $zoho = $this->zoho();

            // 1. Get current data from Paraşüt
            $pInvoice = $parasut->getInvoiceDetails($invoiceId);
            if (!isset($pInvoice['data'])) {
                jsonResponse(['success' => true, 'message' => 'Paraşüt verisi alınamadı, atlandı.', 'skipped' => true]);
                return;
            }

            $attribs = $pInvoice['data']['attributes'] ?? [];
            $included = $pInvoice['included'] ?? [];

            // 2. Determine payment status
            $remaining = (float) ($attribs['remaining_payment'] ?? 0);
            $netTotal = (float) ($attribs['net_total'] ?? 0);
            $paymentStatus = 'Ödenmedi';
            if ($remaining <= 0 && $netTotal > 0)
                $paymentStatus = 'Ödendi';
            elseif ($remaining < $netTotal && $remaining > 0)
                $paymentStatus = 'Kısmi Ödeme';

            // 3. Determine e-document status
            $invoiceStatus = null;
            foreach ($included as $inc) {
                if (in_array($inc['type'], ['active_e_document', 'e_document', 'e_invoices'])) {
                    $eDocStatus = $inc['attributes']['status'] ?? '';
                    if (!empty($eDocStatus)) {
                        $statusMap = [
                            'successful' => 'Onaylandı',
                            'approved' => 'Onaylandı',
                            'waiting' => 'Onay Bekliyor',
                            'waiting_for_approval' => 'Onay Bekliyor',
                            'pending' => 'GİB\'den Onay Bekliyor',
                            'gib_waiting' => 'GİB\'den Onay Bekliyor',
                            'error' => 'Hata',
                            'failed' => 'Hata',
                            'rejected' => 'Reddedildi',
                            'cancelled' => 'İptal Edildi',
                            'sent' => 'Gönderildi',
                            'draft' => 'Taslak'
                        ];
                        $invoiceStatus = $statusMap[$eDocStatus] ?? ucfirst($eDocStatus);
                    }
                    break;
                }
            }

            // 4. Update Zoho record
            $updateData = ['Ödeme_Durumu' => $paymentStatus];
            if ($invoiceStatus) {
                $updateData['Status'] = $invoiceStatus;
            }

            $zoho->updateInvoiceFields($zohoInvoiceId, $updateData);

            jsonResponse([
                'success' => true,
                'message' => "Güncellendi: $paymentStatus" . ($invoiceStatus ? " / $invoiceStatus" : ''),
                'payment_status' => $paymentStatus,
                'invoice_status' => $invoiceStatus
            ]);

        } catch (Exception $e) {
            writeLog("Status update error for invoice $invoiceId: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
