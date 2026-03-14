<?php
// classes/SyncService.php

class SyncService
{
    private $pdo;
    private $parasut;
    private $zoho;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->parasut = new ParasutService($pdo);
        $this->zoho = new ZohoService($pdo);

        // Auto-cleanup expired locks to prevent table bloat
        $this->clearOldLocks();
    }

    /**
     * Write to sync_history audit log
     */
    public function logAudit($actionType, $resourceType = null, $resourceId = null, $details = null)
    {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO sync_history (action_type, resource_type, resource_id, details, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $actionType,
                $resourceType,
                $resourceId,
                is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Exception $e) {
            // Audit log failure should not break main operations
            writeLog("Audit log write failed: " . $e->getMessage());
        }
    }

    /**
     * Webhook Loop Prevention: Set a lock for a resource
     */
    public function setLock($resourceType, $remoteId, $systemSource, $durationSeconds = 60)
    {
        $stmt = $this->pdo->prepare("INSERT INTO sync_locks (resource_type, remote_id, system_source, locked_until) 
            VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE locked_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND)");
        return $stmt->execute([$resourceType, $remoteId, $systemSource, $durationSeconds, $durationSeconds]);
    }

    /**
     * Webhook Loop Prevention: Check if a resource is locked
     */
    public function isLocked($resourceType, $remoteId, $systemSource)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sync_locks 
            WHERE resource_type = ? AND remote_id = ? AND system_source = ? AND locked_until > CURRENT_TIMESTAMP");
        $stmt->execute([$resourceType, $remoteId, $systemSource]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Webhook Loop Prevention: Cleanup expired locks
     */
    public function clearOldLocks()
    {
        return $this->pdo->exec("DELETE FROM sync_locks WHERE locked_until < CURRENT_TIMESTAMP");
    }

    /**
     * Sync Stock from Zoho to Paraşüt
     */
    public function syncStockZohoToParasut()
    {
        writeLog("Starting Stock Sync: Zoho -> Paraşüt");

        // 1. Refresh Zoho products (Incremental)
        $lastZohoSync = getSetting($this->pdo, 'last_sync_zoho_products');
        $this->zoho->syncProducts($lastZohoSync);
        updateSetting($this->pdo, 'last_sync_zoho_products', date('c')); // ISO8601 for Zoho

        // 2. Refresh Paraşüt products (Incremental)
        $lastParasutSync = getSetting($this->pdo, 'last_sync_parasut_products');
        $this->parasut->syncProducts($lastParasutSync);
        updateSetting($this->pdo, 'last_sync_parasut_products', date('Y-m-d H:i:s'));

        // 3. Get all products from local DB where both have codes
        $stmt = $this->pdo->query("
            SELECT zp.product_code, zp.Qty_in_Stock, pp.parasut_id, pp.stock_quantity as current_p_stock
            FROM zoho_products zp
            INNER JOIN parasut_products pp ON zp.product_code = pp.product_code
            WHERE zp.product_code IS NOT NULL AND zp.product_code != ''
        ");
        $products = $stmt->fetchAll();

        $updateCount = 0;
        foreach ($products as $p) {
            $zohoStock = (float) ($p['Qty_in_Stock'] ?? 0);
            $parasutStock = (float) ($p['current_p_stock'] ?? 0);

            // Only update if there is a significant difference
            if (abs($zohoStock - $parasutStock) > 0.001) {
                try {
                    writeLog("Updating Stock for SKU {$p['product_code']}: Zoho ($zohoStock) != Paraşüt ($parasutStock)");

                    // Set lock BEFORE updating Parasut to prevent webhook loop
                    $this->setLock('products', $p['parasut_id'], 'parasut');

                    $this->parasut->updateStock($p['parasut_id'], $zohoStock);

                    // Update local DB to reflect change
                    $upd = $this->pdo->prepare("UPDATE parasut_products SET stock_quantity = ? WHERE parasut_id = ?");
                    $upd->execute([$zohoStock, $p['parasut_id']]);

                    $updateCount++;
                } catch (Exception $e) {
                    writeLog("Error updating stock for {$p['product_code']}: " . $e->getMessage());
                }
            }
        }

        writeLog("Stock Sync Complete. Updated $updateCount products.");
        return $updateCount;
    }

    /**
     * Sync Invoices from Zoho to Paraşüt
     */
    public function syncInvoicesZohoToParasut($limit = 50)
    {
        writeLog("Starting Invoice Sync: Zoho -> Paraşüt (Limit: $limit)");

        // 1. Fetch recent invoices from Zoho
        $response = $this->zoho->getInvoices(1, $limit);
        if (empty($response['data'])) {
            return 0;
        }

        $newCount = 0;
        foreach ($response['data'] as $zInvSummary) {
            $zohoId = $zInvSummary['id'];

            // 2. Check if already synced
            $stmt = $this->pdo->prepare("SELECT id FROM parasut_invoices WHERE zoho_invoice_id = ?");
            $stmt->execute([$zohoId]);
            if ($stmt->fetch()) {
                continue; // Already exists
            }

            try {
                // 3. Fetch full Zoho Invoice details
                $zInvoice = $this->zoho->getInvoice($zohoId);
                if (!$zInvoice)
                    continue;

                writeLog("Processing Zoho Invoice: " . ($zInvoice['Invoice_Number'] ?? $zohoId));

                // 4. Find/Create Contact in Paraşüt
                $pContactId = $this->ensureContactInParasut($zInvoice);
                if (!$pContactId) {
                    throw new Exception("Could not map/create contact for Zoho Invoice $zohoId");
                }

                // 5. Build Line Items for Paraşüt
                $lineItems = $this->buildParasutLineItems($zInvoice);
                if (empty($lineItems)) {
                    throw new Exception("Invoice $zohoId has no valid line items for Paraşüt");
                }

                // 6. Create Invoice in Paraşüt
                $pInvoiceData = [
                    'type' => 'sales_invoices',
                    'attributes' => [
                        'invoice_no' => $zInvoice['Invoice_Number'] ?? null,
                        'issue_date' => $zInvoice['Invoice_Date'] ?? date('Y-m-d'),
                        'due_date' => $zInvoice['Due_Date'] ?? null,
                        'description' => $zInvoice['Subject'] ?? 'Zoho Import',
                        'currency' => $zInvoice['Currency'] ?? 'TRY',
                        'item_type' => 'invoice'
                    ],
                    'relationships' => [
                        'contact' => [
                            'data' => ['id' => $pContactId, 'type' => 'contacts']
                        ],
                        'details' => [
                            'data' => $lineItems
                        ]
                    ]
                ];

                try {
                    // Start transaction for atomic lock + create operation
                    $this->pdo->beginTransaction();

                    // Acquire lock BEFORE creating invoice to prevent webhook race condition
                    // Use temporary lock ID until we get real invoice ID
                    $tempLockId = 'pending_' . uniqid();
                    $this->setLock('sales_invoices', $tempLockId, 'parasut', 120);

                    $response = $this->parasut->createSalesInvoice($pInvoiceData);
                    $newPInvoiceId = $response['data']['id'] ?? null;

                    if ($newPInvoiceId) {
                        // Update lock with real invoice ID
                        $this->setLock('sales_invoices', $newPInvoiceId, 'parasut');

                        // 7. Save to local DB mapping
                        $stmt = $this->pdo->prepare("INSERT INTO parasut_invoices 
                            (parasut_id, invoice_number, issue_date, net_total, zoho_invoice_id, synced_to_zoho, synced_at, raw_data) 
                            VALUES (?, ?, ?, ?, ?, 1, NOW(), ?)");
                        $stmt->execute([
                            $newPInvoiceId,
                            $zInvoice['Invoice_Number'] ?? null,
                            $zInvoice['Invoice_Date'] ?? date('Y-m-d'),
                            $zInvoice['Grand_Total'] ?? 0,
                            $zohoId,
                            json_encode($zInvoice)
                        ]);

                        $this->pdo->commit();
                        $newCount++;
                        writeLog("Successfully created Paraşüt Invoice $newPInvoiceId from Zoho $zohoId");
                    } else {
                        $this->pdo->rollBack();
                        throw new Exception("No invoice ID returned from Parasut API");
                    }
                } catch (Exception $txnException) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $txnException;
                }

            } catch (Exception $e) {
                writeLog("Error syncing Zoho Invoice $zohoId: " . $e->getMessage());
            }
        }

        return $newCount;
    }

    private function ensureContactInParasut($zInvoice)
    {
        $account = $zInvoice['Account_Name'] ?? null;
        if (!$account)
            return null;

        $accountName = is_array($account) ? ($account['name'] ?? null) : $account;
        $accountId = is_array($account) ? ($account['id'] ?? null) : null;

        // Try searching in Paraşüt by name
        $search = $this->parasut->searchContact($accountName);
        if (!empty($search['data'])) {
            return $search['data'][0]['id'];
        }

        // If not found, create it (Simplified)
        try {
            writeLog("Creating new Contact in Paraşüt: $accountName");
            $res = $this->parasut->createContact([
                'name' => $accountName,
                'contact_type' => 'company',
                'email' => $zInvoice['Email'] ?? null
            ]);
            return $res['data']['id'] ?? null;
        } catch (Exception $e) {
            writeLog("Contact creation failed: " . $e->getMessage());
            return null;
        }
    }

    private function buildParasutLineItems($zInvoice)
    {
        $items = [];
        if (empty($zInvoice['Invoiced_Items']))
            return [];

        foreach ($zInvoice['Invoiced_Items'] as $zItem) {
            $product = $zItem['product'] ?? null;
            $productCode = $product['Product_Code'] ?? null;

            // Find product in Paraşüt
            $pProductId = null;
            if ($productCode) {
                $stmt = $this->pdo->prepare("SELECT parasut_id FROM parasut_products WHERE product_code = ?");
                $stmt->execute([$productCode]);
                $pProductId = $stmt->fetchColumn();
            }

            if (!$pProductId && $productCode) {
                // Try searching via API if not in DB
                $search = $this->parasut->searchProductByCode($productCode);
                if (!empty($search['data'])) {
                    $pProductId = $search['data'][0]['id'];
                }
            }

            if (!$pProductId) {
                writeLog("Warning: Product SKU $productCode not found in Paraşüt. Skipping line item.");
                continue;
            }

            $items[] = [
                'type' => 'sales_invoice_details',
                'attributes' => [
                    'quantity' => $zItem['quantity'] ?? 1,
                    'unit_price' => $zItem['list_price'] ?? 0,
                    'vat_rate' => $zItem['tax'] ?? 0, // Simplified tax mapping
                    'description' => $zItem['product_description'] ?? ''
                ],
                'relationships' => [
                    'product' => [
                        'data' => ['id' => $pProductId, 'type' => 'products']
                    ]
                ]
            ];
        }

        return $items;
    }

    /**
     * Sync Invoice Statuses from Paraşüt to Zoho
     */
    public function syncInvoiceStatuses($limit = 50)
    {
        writeLog("Starting Invoice Status Sync: Paraşüt -> Zoho (Limit: $limit)");

        // 1. Get recent synced invoices from local DB
        $stmt = $this->pdo->prepare("
            SELECT id, parasut_id, zoho_invoice_id, payment_status 
            FROM parasut_invoices 
            WHERE zoho_invoice_id IS NOT NULL 
            ORDER BY updated_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $invoices = $stmt->fetchAll();

        $updatedCount = 0;
        foreach ($invoices as $inv) {
            try {
                // 2. Refresh from Paraşüt API
                $pInvoice = $this->parasut->getInvoiceDetails($inv['id'] ?? $inv['parasut_id']);
                if (!isset($pInvoice['data']['attributes']))
                    continue;

                $attr = $pInvoice['data']['attributes'];
                $remaining = (float) ($attr['remaining_payment'] ?? 0);
                $total = (float) ($attr['net_total'] ?? 0);

                $newStatus = 'unpaid';
                if ($remaining <= 0) {
                    $newStatus = 'paid';
                } elseif ($remaining < $total) {
                    $newStatus = 'partially_paid';
                }

                // 3. Update local DB if changed
                if ($newStatus !== $inv['payment_status']) {
                    writeLog("Status changed for Invoice {$inv['parasut_id']}: {$inv['payment_status']} -> $newStatus");

                    $upd = $this->pdo->prepare("UPDATE parasut_invoices SET payment_status = ?, remaining_payment = ?, updated_at = NOW(), last_status_check_at = NOW() WHERE id = ?");
                    $upd->execute([$newStatus, $remaining, $inv['id']]);

                    // 4. Update Zoho
                    $zohoStatus = 'Sent'; // Default
                    if ($newStatus === 'paid') {
                        $zohoStatus = 'Paid';
                    } elseif ($newStatus === 'partially_paid') {
                        $zohoStatus = 'Partially Paid';
                    }

                    // Set lock for Zoho invoice to prevent webhook echo
                    $this->setLock('Invoices', $inv['zoho_invoice_id'], 'zoho');

                    $this->zoho->updateInvoiceStatus($inv['zoho_invoice_id'], $zohoStatus);
                    $updatedCount++;
                }
            } catch (Exception $e) {
                writeLog("Error syncing status for invoice {$inv['parasut_id']}: " . $e->getMessage());
            }
        }

        return $updatedCount;
    }

    /**
     * Handle Incoming Paraşüt Webhook
     */
    public function handleParasutWebhook($data)
    {
        $resource = $data['resource'] ?? '';
        $action = $data['action'] ?? '';
        $pId = $data['data']['id'] ?? null;

        if (!$pId)
            return;

        writeLog("Processing Paraşüt Webhook: $resource $action ($pId)");

        if ($resource === 'sales_invoice') {
            try {
                $pInvoice = $this->parasut->getInvoiceDetails($pId);
                if (!isset($pInvoice['data']['attributes']))
                    return;

                $attr = $pInvoice['data']['attributes'];
                $included = $pInvoice['included'] ?? [];

                // A. Check for e-document (formalization) — sync back to Zoho
                $hasEDocument = false;
                foreach ($included as $inc) {
                    if (in_array($inc['type'], ['active_e_document', 'e_document', 'e_invoices', 'e_archives'])) {
                        $hasEDocument = true;
                        break;
                    }
                }

                if ($hasEDocument) {
                    writeLog("Webhook: E-document detected for Invoice $pId — syncing formalization to Zoho");
                    $this->logAudit('parasut_formalization', 'sales_invoice', $pId, 'E-document detected, syncing to Zoho');
                    $this->syncFormalizationToZoho($pId);
                }

                // B. Payment status sync
                $remaining = (float) ($attr['remaining_payment'] ?? 0);
                $total = (float) ($attr['net_total'] ?? 0);

                $newStatus = 'unpaid';
                if ($remaining <= 0)
                    $newStatus = 'paid';
                elseif ($remaining < $total)
                    $newStatus = 'partially_paid';

                // Check local DB
                $stmt = $this->pdo->prepare("SELECT id, zoho_invoice_id, payment_status FROM parasut_invoices WHERE parasut_id = ?");
                $stmt->execute([$pId]);
                $inv = $stmt->fetch();

                if ($inv) {
                    if ($newStatus !== $inv['payment_status']) {
                        writeLog("Webhook: Status changed for Invoice $pId -> $newStatus");
                        $this->logAudit('payment_status_change', 'sales_invoice', $pId, ['old' => $inv['payment_status'], 'new' => $newStatus]);
                        $this->pdo->prepare("UPDATE parasut_invoices SET payment_status = ?, remaining_payment = ?, updated_at = NOW(), last_status_check_at = NOW() WHERE id = ?")
                            ->execute([$newStatus, $remaining, $inv['id']]);

                        if ($inv['zoho_invoice_id']) {
                            $zohoStatus = ($newStatus === 'paid') ? 'Paid' : (($newStatus === 'partially_paid') ? 'Partially Paid' : 'Sent');
                            $this->setLock('Invoices', $inv['zoho_invoice_id'], 'zoho');
                            $this->zoho->updateInvoiceStatus($inv['zoho_invoice_id'], $zohoStatus);

                            // Also update Payment_Status custom field
                            $paymentStatusTR = match ($newStatus) {
                                'paid' => 'Ödendi',
                                'partially_paid' => 'Kısmi Ödeme',
                                default => 'Ödenmedi'
                            };
                            try {
                                $this->zoho->updateInvoiceFields($inv['zoho_invoice_id'], [
                                    'Payment_Status' => $paymentStatusTR
                                ]);
                            } catch (Exception $e) {
                                writeLog("Warning: Payment_Status custom field update failed: " . $e->getMessage());
                            }
                        }
                    }
                }

                // C. Also check invoice_mapping for Zoho-originated invoices
                $mapStmt = $this->pdo->prepare("SELECT zoho_invoice_id FROM invoice_mapping WHERE parasut_invoice_id = ?");
                $mapStmt->execute([$pId]);
                $mappedZohoId = $mapStmt->fetchColumn();

                if ($mappedZohoId && (!$inv || !$inv['zoho_invoice_id'])) {
                    // This invoice was created from Zoho — update payment status there too
                    $zohoStatus = ($newStatus === 'paid') ? 'Paid' : (($newStatus === 'partially_paid') ? 'Partially Paid' : 'Sent');
                    $paymentStatusTR = match ($newStatus) {
                        'paid' => 'Ödendi',
                        'partially_paid' => 'Kısmi Ödeme',
                        default => 'Ödenmedi'
                    };
                    $this->setLock('Invoices', $mappedZohoId, 'zoho');
                    $this->zoho->updateInvoiceStatus($mappedZohoId, $zohoStatus);
                    try {
                        $this->zoho->updateInvoiceFields($mappedZohoId, [
                            'Payment_Status' => $paymentStatusTR
                        ]);
                    } catch (Exception $e) {
                        writeLog("Warning: Mapped invoice Payment_Status update failed: " . $e->getMessage());
                    }
                }

            } catch (Exception $e) {
                writeLog("Webhook Error (Paraşüt): " . $e->getMessage());
            }
        }
    }


    /**
     * Handle Incoming Zoho Webhook
     */
    public function handleZohoWebhook($data)
    {
        $module = $data['module'] ?? '';
        $zId = $data['id'] ?? null;
        $action = $data['action'] ?? 'update';

        if (!$zId)
            return;

        writeLog("Processing Zoho Webhook: $module $action ($zId)");

        if ($module === 'Invoices') {
            // Check if this invoice already has a Parasut mapping (avoid duplicates)
            $checkStmt = $this->pdo->prepare("SELECT id FROM invoice_mapping WHERE zoho_invoice_id = ?");
            $checkStmt->execute([$zId]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                writeLog("Zoho Webhook: Invoice $zId already mapped to Parasut. Skipping.");
                return;
            }

            // Check if invoice has a Parasut_ID custom field (means it was synced FROM Parasut)
            try {
                $zohoInvoice = $this->zoho->getInvoiceWithLineItems($zId);
                if ($zohoInvoice && !empty($zohoInvoice['Parasut_ID'])) {
                    writeLog("Zoho Webhook: Invoice $zId has Parasut_ID={$zohoInvoice['Parasut_ID']}. Originated from Parasut. Skipping.");
                    return;
                }
            } catch (Exception $e) {
                writeLog("Zoho Webhook: Could not fetch invoice details: " . $e->getMessage());
            }

            // This is a new invoice created in Zoho — export to Parasut
            try {
                $this->setLock('sales_invoice', $zId, 'parasut'); // Prevent loop
                $result = $this->exportInvoiceFromZohoToParasut($zId);
                $this->logAudit('zoho_invoice_export', 'Invoices', $zId, $result);
                writeLog("Zoho → Parasut export result: " . json_encode($result));
            } catch (Exception $e) {
                writeLog("Zoho Webhook Error: " . $e->getMessage());
            }
        }
    }

    /**
     * Export Invoice from Zoho to Parasut
     * @param string $zohoInvoiceId Zoho Invoice ID
     * @return array Result with success/message
     */
    public function exportInvoiceFromZohoToParasut($zohoInvoiceId)
    {
        writeLog("=== exportInvoiceFromZohoToParasut: $zohoInvoiceId ===");

        try {
            // 1. Get full invoice from Zoho
            $zohoInvoice = $this->zoho->getInvoiceWithLineItems($zohoInvoiceId);
            if (!$zohoInvoice) {
                return ['success' => false, 'message' => 'Zoho invoice not found'];
            }

            // 2. Get Account info for Parasut contact matching
            $accountName = '';
            if (is_array($zohoInvoice['Account_Name'])) {
                $accountName = $zohoInvoice['Account_Name']['name'] ?? '';
            } else {
                $accountName = $zohoInvoice['Account_Name'] ?? '';
            }

            // 3. Find or create Parasut contact
            $parasutContact = $this->parasut->findContact($accountName, 'name');
            $parasutContactId = null;

            if ($parasutContact) {
                $parasutContactId = $parasutContact['id'];
                writeLog("Found Parasut contact: $parasutContactId for '$accountName'");
            } else {
                // Create new contact in Parasut
                $contactData = [
                    'name' => $accountName,
                    'contact_type' => 'customer',
                    'category' => 'customer',
                ];
                $result = $this->parasut->createContact($contactData);
                $parasutContactId = $result['data']['id'] ?? null;
                writeLog("Created Parasut contact: $parasutContactId for '$accountName'");
            }

            if (!$parasutContactId) {
                return ['success' => false, 'message' => 'Could not find/create Parasut contact'];
            }

            // 4. Map line items: Zoho products → Parasut products
            $lineItems = [];
            $invoicedItems = $zohoInvoice['Invoiced_Items'] ?? [];

            foreach ($invoicedItems as $zItem) {
                $zProductId = $zItem['Product_Name']['id'] ?? null;
                $zProductName = $zItem['Product_Name']['name'] ?? '';
                $quantity = (float) ($zItem['Quantity'] ?? 1);
                $unitPrice = (float) ($zItem['List_Price'] ?? 0);
                $discount = (float) ($zItem['Discount'] ?? 0);

                // Find Parasut product by code first, then name
                $parasutProductId = null;

                // Search local DB for product mapping
                if ($zProductId) {
                    $stmt = $this->pdo->prepare("
                        SELECT pp.parasut_id FROM parasut_products pp
                        INNER JOIN zoho_products zp ON pp.product_code = zp.product_code
                        WHERE zp.zoho_id = ?
                    ");
                    $stmt->execute([$zProductId]);
                    $parasutProductId = $stmt->fetchColumn();
                }

                if (!$parasutProductId) {
                    writeLog("WARNING: Could not find Parasut product for Zoho product: $zProductName ($zProductId)");
                    continue;
                }

                // Determine VAT rate from product or line item tax
                $vatRate = 0;
                if (!empty($zItem['Line_Tax'])) {
                    foreach ($zItem['Line_Tax'] as $tax) {
                        $vatRate = (float) ($tax['percentage'] ?? 0);
                        break;
                    }
                }

                $lineItems[] = [
                    'product_id' => $parasutProductId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $vatRate,
                    'discount' => $discount,
                ];
            }

            if (empty($lineItems)) {
                return ['success' => false, 'message' => 'No mappable line items found'];
            }

            // 5. Get currency
            $currency = 'TRY';
            if (is_array($zohoInvoice['Currency'] ?? null)) {
                $currency = $zohoInvoice['Currency']['code'] ?? $zohoInvoice['Currency']['name'] ?? 'TRY';
            } elseif (is_string($zohoInvoice['Currency'] ?? null)) {
                $currency = $zohoInvoice['Currency'];
            }

            // 6. Create invoice in Parasut
            $options = [
                'issue_date' => $zohoInvoice['Invoice_Date'] ?? date('Y-m-d'),
                'due_date' => $zohoInvoice['Due_Date'] ?? null,
                'currency' => $currency,
                'description' => $zohoInvoice['Description'] ?? $zohoInvoice['Subject'] ?? '',
            ];

            $parasutResult = $this->parasut->createFullSalesInvoice($parasutContactId, $lineItems, $options);
            $parasutInvoiceId = $parasutResult['data']['id'] ?? null;

            if (!$parasutInvoiceId) {
                return ['success' => false, 'message' => 'Parasut invoice creation failed: ' . json_encode($parasutResult)];
            }

            // 7. Save mapping
            $mapStmt = $this->pdo->prepare("
                INSERT INTO invoice_mapping 
                (zoho_invoice_id, parasut_invoice_id, source, zoho_invoice_number, sync_status) 
                VALUES (?, ?, 'zoho', ?, 'synced')
                ON DUPLICATE KEY UPDATE parasut_invoice_id = VALUES(parasut_invoice_id), sync_status = 'synced', last_synced_at = NOW()
            ");
            $mapStmt->execute([
                $zohoInvoiceId,
                $parasutInvoiceId,
                $zohoInvoice['Invoice_Number'] ?? ''
            ]);

            // 8. Update Zoho invoice with Parasut_ID for tracking
            try {
                $this->zoho->updateInvoiceFields($zohoInvoiceId, [
                    'Parasut_ID' => $parasutInvoiceId
                ]);
            } catch (Exception $e) {
                writeLog("Warning: Could not update Zoho invoice with Parasut_ID: " . $e->getMessage());
            }

            writeLog("Successfully exported Zoho Invoice $zohoInvoiceId → Parasut Invoice $parasutInvoiceId");
            $this->logAudit('invoice_exported_to_parasut', 'Invoices', $zohoInvoiceId, ['parasut_id' => $parasutInvoiceId]);

            return [
                'success' => true,
                'parasut_id' => $parasutInvoiceId,
                'message' => "Fatura Parasut'a aktarıldı"
            ];

        } catch (Exception $e) {
            writeLog("exportInvoiceFromZohoToParasut ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sync formalization data from Parasut back to Zoho
     * Called when Parasut webhook reports e-document creation
     */
    public function syncFormalizationToZoho($parasutInvoiceId)
    {
        writeLog("=== syncFormalizationToZoho: $parasutInvoiceId ===");

        try {
            // 1. Get invoice with e-document data from Parasut
            $pInvoice = $this->parasut->getInvoiceDetails($parasutInvoiceId);
            if (!isset($pInvoice['data'])) {
                writeLog("Parasut invoice not found: $parasutInvoiceId");
                return;
            }

            $pAttr = $pInvoice['data']['attributes'];
            $included = $pInvoice['included'] ?? [];

            // 2. Find e-document in included data
            $eDocNumber = null;
            $eDocStatus = null;
            $eDocType = null;
            $eDocId = null;

            foreach ($included as $inc) {
                if (in_array($inc['type'], ['active_e_document', 'e_document', 'e_invoices', 'e_archives'])) {
                    $eAttr = $inc['attributes'] ?? [];
                    $eDocNumber = $eAttr['uuid'] ?? $eAttr['number'] ?? $eAttr['vkn'] ?? null;
                    $eDocStatus = $eAttr['status'] ?? 'unknown';
                    $eDocType = $inc['type'];
                    $eDocId = $inc['id'];
                    writeLog("Found e-document: type=$eDocType, id=$eDocId, number=$eDocNumber, status=$eDocStatus");
                    break;
                }
            }

            // 3. Find the Zoho invoice ID from mapping
            $mapStmt = $this->pdo->prepare("SELECT zoho_invoice_id FROM invoice_mapping WHERE parasut_invoice_id = ?");
            $mapStmt->execute([$parasutInvoiceId]);
            $zohoInvoiceId = $mapStmt->fetchColumn();

            // Also try from parasut_invoices table
            if (!$zohoInvoiceId) {
                $invStmt = $this->pdo->prepare("SELECT zoho_invoice_id FROM parasut_invoices WHERE parasut_id = ?");
                $invStmt->execute([$parasutInvoiceId]);
                $zohoInvoiceId = $invStmt->fetchColumn();
            }

            if (!$zohoInvoiceId) {
                writeLog("No Zoho invoice found for Parasut invoice $parasutInvoiceId");
                return;
            }

            // 4. Update invoice_mapping with e-document info
            $updateMapStmt = $this->pdo->prepare("
                UPDATE invoice_mapping SET 
                    parasut_invoice_number = ?,
                    parasut_e_invoice_id = CASE WHEN ? IN ('e_invoices', 'active_e_document') THEN ? ELSE parasut_e_invoice_id END,
                    parasut_e_archive_id = CASE WHEN ? IN ('e_archives') THEN ? ELSE parasut_e_archive_id END,
                    e_document_number = ?,
                    e_document_status = ?,
                    updated_at = NOW()
                WHERE parasut_invoice_id = ?
            ");
            $updateMapStmt->execute([
                $pAttr['invoice_no'] ?? $pAttr['invoice_number'] ?? '',
                $eDocType,
                $eDocId,
                $eDocType,
                $eDocId,
                $eDocNumber,
                $eDocStatus,
                $parasutInvoiceId
            ]);

            // 5. Update Zoho Invoice with e-document info
            $zohoUpdateFields = [];

            if ($eDocNumber) {
                $zohoUpdateFields['E_Fatura_No'] = $eDocNumber;
            }

            // Calculate payment status
            $remaining = (float) ($pAttr['remaining_payment'] ?? $pAttr['remaining'] ?? 0);
            $netTotal = (float) ($pAttr['net_total'] ?? 0);
            if ($remaining <= 0 && $netTotal > 0) {
                $zohoUpdateFields['Payment_Status'] = 'Ödendi';
            } elseif ($remaining < $netTotal && $remaining > 0) {
                $zohoUpdateFields['Payment_Status'] = 'Kısmi Ödeme';
            } else {
                $zohoUpdateFields['Payment_Status'] = 'Ödenmedi';
            }

            // Pass Parasut invoice number
            $parasutInvNo = $pAttr['invoice_no'] ?? $pAttr['invoice_number'] ?? '';
            if (!empty($parasutInvNo)) {
                $zohoUpdateFields['Description'] = "Parasut Fatura No: $parasutInvNo";
            }

            if (!empty($zohoUpdateFields)) {
                $this->setLock('Invoices', $zohoInvoiceId, 'zoho');
                $this->zoho->updateInvoiceFields($zohoInvoiceId, $zohoUpdateFields);
                writeLog("Updated Zoho Invoice $zohoInvoiceId with formalization data: " . json_encode($zohoUpdateFields));
            }

        } catch (Exception $e) {
            writeLog("syncFormalizationToZoho ERROR: " . $e->getMessage());
        }
    }

    /**
     * Export Purchase Order to Zoho
     * @param string $poId Paraşüt Purchase Order ID
     * @return array Result with 'success' boolean and 'message' or 'zoho_id'
     */
    public function exportPurchaseOrderToZoho($poId)
    {
        writeLog("=== exportPurchaseOrderToZoho: $poId ===");

        try {
            // 1. Check if already synced
            $checkStmt = $this->pdo->prepare("SELECT parasut_id, zoho_po_id, synced_to_zoho, payment_status FROM parasut_purchase_orders WHERE parasut_id = ?");
            $checkStmt->execute([$poId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                return ['success' => false, 'message' => "Purchase order database'de bulunamadı"];
            }

            if ($existing['synced_to_zoho'] == 1 || !empty($existing['zoho_po_id'])) {
                return ['success' => true, 'message' => "Purchase order zaten Zoho'ya aktarılmış", 'zoho_id' => $existing['zoho_po_id']];
            }

            // 2. Fetch Purchase Order details from Paraşüt
            $pPO = $this->parasut->getPurchaseBillDetails($poId);
            if (!isset($pPO['data'])) {
                return ['success' => false, 'message' => "Paraşüt'ten geçersiz yanıt alındı"];
            }

            $poData = $pPO['data'];
            $included = $pPO['included'] ?? [];
            $poAttribs = $poData['attributes'] ?? [];

            // 3. Extract Supplier and Products
            $supplier = null;
            $productsMap = [];

            foreach ($included as $inc) {
                if ($inc['type'] === 'contacts' || $inc['type'] === 'suppliers') {
                    $supplier = $inc['attributes'];
                }
                if ($inc['type'] === 'products') {
                    $productsMap[$inc['id']] = $inc['attributes'];
                }
            }

            if (!$supplier) {
                return ['success' => false, 'message' => "Tedarikçi bilgisi eksik"];
            }

            // 4. Match or Create Vendor in Zoho
            $vendorName = $supplier['name'];
            $vendorEmail = $supplier['email'] ?? '';
            $vendorPhone = $supplier['phone'] ?? '';

            $existingVendor = $this->zoho->searchVendor($vendorName);
            if ($existingVendor) {
                $vendorId = $existingVendor['id'];
            } else {
                $vendorResult = $this->zoho->createVendor($vendorName, $vendorEmail, $vendorPhone);
                if (isset($vendorResult['data'][0]['status']) && $vendorResult['data'][0]['status'] === 'success') {
                    $vendorId = $vendorResult['data'][0]['details']['id'];
                } else {
                    return ['success' => false, 'message' => "Vendor oluşturulamadı"];
                }
            }

            // 5. Build Line Items - OPTIMIZED: Batch query instead of N+1
            $lineItems = [];

            // First pass: collect all product codes
            $productCodes = [];
            foreach ($included as $inc) {
                if ($inc['type'] === 'purchase_bill_details' || $inc['type'] === 'purchase_bill_detail') {
                    $productId = $inc['relationships']['product']['data']['id'] ?? null;
                    $productInfo = $productsMap[$productId] ?? [];
                    $productCode = $productInfo['code'] ?? null;

                    if (empty($productCode)) {
                        return ['success' => false, 'message' => "Ürün kodu eksik olan kalem var"];
                    }

                    $productCodes[] = $productCode;
                }
            }

            // Batch query: Get all Zoho product IDs at once
            $zohoProductMap = [];
            if (!empty($productCodes)) {
                $placeholders = str_repeat('?,', count($productCodes) - 1) . '?';
                $zohoProductStmt = $this->pdo->prepare("SELECT product_code, zoho_id FROM zoho_products WHERE product_code IN ($placeholders)");
                $zohoProductStmt->execute($productCodes);
                $zohoProductMap = $zohoProductStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            }

            // Second pass: Build line items using the map
            foreach ($included as $inc) {
                if ($inc['type'] === 'purchase_bill_details' || $inc['type'] === 'purchase_bill_detail') {
                    $productId = $inc['relationships']['product']['data']['id'] ?? null;
                    $productInfo = $productsMap[$productId] ?? [];
                    $productCode = $productInfo['code'] ?? null;

                    if (empty($productCode)) {
                        continue; // Already checked above
                    }

                    // Lookup from batch query result
                    $zohoProductId = $zohoProductMap[$productCode] ?? null;


                    if (!$zohoProductId) {
                        // Auto-create product in Zoho
                        $productName = $productInfo['name'] ?? $productCode;
                        $unitPrice = $inc['attributes']['unit_price'] ?? 0;
                        $productVatRate = $inc['attributes']['vat_rate'] ?? 18;

                        $createResult = $this->zoho->createProduct([
                            'name' => $productName,
                            'code' => $productCode,
                            'price' => $unitPrice,
                            'vat_rate' => $productVatRate
                        ]);

                        if (isset($createResult['data'][0]['status']) && $createResult['data'][0]['status'] === 'success') {
                            $zohoProductId = $createResult['data'][0]['details']['id'];

                            // Insert into local DB
                            $insertStmt = $this->pdo->prepare("
                                INSERT INTO zoho_products (zoho_id, product_code, product_name, unit_price, created_at, updated_at)
                                VALUES (?, ?, ?, ?, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), unit_price = VALUES(unit_price), updated_at = NOW()
                            ");
                            $insertStmt->execute([$zohoProductId, $productCode, $productName, $unitPrice]);
                        } else {
                            return ['success' => false, 'message' => "Ürün kodu '$productCode' Zoho'da oluşturulamadı"];
                        }
                    }

                    $quantity = $inc['attributes']['quantity'] ?? 1;
                    $unitPrice = $inc['attributes']['unit_price'] ?? 0;
                    $vatRate = $inc['attributes']['vat_rate'] ?? 0;

                    $lineItems[] = [
                        'product_id' => $zohoProductId,
                        'quantity' => $quantity,
                        'price' => $unitPrice,
                        'vat_rate' => $vatRate,
                        'discount' => (float) ($inc['attributes']['discount_amount'] ?? $inc['attributes']['discount'] ?? 0)
                    ];
                }
            }

            if (empty($lineItems)) {
                return ['success' => false, 'message' => "Purchase order'da hiç kalem bulunamadı"];
            }

            // 6. Create Purchase Order in Zoho
            $poSubject = $poAttribs['invoice_no'] ?? "PO-" . $poId;
            $poCurrency = $poAttribs['currency'] ?? 'TRY';
            $poDate = $poAttribs['issue_date'] ?? date('Y-m-d');

            // Map payment status
            $paymentStatusFromDB = $existing['payment_status'] ?? 'unpaid';
            $zohoStatus = match ($paymentStatusFromDB) {
                'paid' => 'ÖDENDİ',
                'partially_paid' => 'KISMİ ÖDEME',
                default => 'BEKLEMEDE'
            };

            $parasutCompanyId = getSetting($this->pdo, 'parasut_company_id') ?? '136555';
            $parasutUrl = "https://uygulama.parasut.com/{$parasutCompanyId}/fis-faturalar/{$poId}";

            $options = [
                'po_date' => $poDate,
                'po_number' => $poAttribs['invoice_no'] ?? '',
                'due_date' => $poAttribs['due_date'] ?? null,
                'exchange_rate' => $poAttribs['exchange_rate'] ?? null,
                'description' => $poAttribs['description'] ?? '',
                'status' => $zohoStatus,
                'parasut_url' => $parasutUrl
            ];

            $zohoResult = $this->zoho->createPurchaseOrder($poSubject, $vendorId, $lineItems, $poCurrency, $options);

            if (isset($zohoResult['data'][0]['status']) && $zohoResult['data'][0]['status'] === 'success') {
                $zohoPOId = $zohoResult['data'][0]['details']['id'];

                // 7. Update local database
                $updateStmt = $this->pdo->prepare("UPDATE parasut_purchase_orders SET zoho_po_id = ?, synced_to_zoho = 1, synced_at = NOW(), sync_error = NULL WHERE parasut_id = ?");
                $updateStmt->execute([$zohoPOId, $poId]);

                writeLog("✅ Successfully exported PO $poId to Zoho: $zohoPOId");
                return ['success' => true, 'message' => "Purchase Order başarıyla Zoho'ya aktarıldı!", 'zoho_id' => $zohoPOId];
            } else {
                $errorMsg = $zohoResult['data'][0]['message'] ?? json_encode($zohoResult);
                throw new Exception("Zoho karşılık hatası: " . $errorMsg);
            }

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            writeLog("❌ PO Export Error: $errorMessage");

            // Mark as error in database
            try {
                $errorStmt = $this->pdo->prepare("UPDATE parasut_purchase_orders SET synced_to_zoho = 2, sync_error = ? WHERE parasut_id = ?");
                $errorStmt->execute([$errorMessage, $poId]);
            } catch (Exception $dbError) {
                // Silent fail on DB update error
            }

            return ['success' => false, 'message' => $errorMessage];
        }
    }

    /**
     * Export a Paraşüt Sales Invoice to Zoho Books
     * This is the Queue/Cron-safe version of InvoiceController::export_invoice_to_zoho()
     * Returns array instead of calling jsonResponse() so it can be used in non-HTTP contexts.
     *
     * @param string $invoiceId  Paraşüt Invoice ID (parasut_id)
     * @param bool   $force      Force re-export even if already synced
     * @return array ['success' => bool, 'message' => string, 'zoho_id' => string|null]
     */
    public function exportInvoiceToZoho(string $invoiceId, bool $force = false): array
    {
        writeLog("=== exportInvoiceToZoho: $invoiceId ===", 'INFO', 'sync');

        try {
            // 1. Check if already synced
            $checkStmt = $this->pdo->prepare("SELECT zoho_invoice_id, synced_to_zoho FROM parasut_invoices WHERE parasut_id = ?");
            $checkStmt->execute([$invoiceId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$force && $existing && ($existing['synced_to_zoho'] == 1 || !empty($existing['zoho_invoice_id']))) {
                return ['success' => true, 'message' => "Fatura zaten Zoho'ya aktarılmış.", 'zoho_id' => $existing['zoho_invoice_id']];
            }

            // 2. Fetch Full Invoice Data from Paraşüt
            $pInvoice = $this->parasut->getInvoiceDetails($invoiceId);
            if (!isset($pInvoice['data'])) {
                throw new Exception("Paraşüt Fatura verisi alınamadı: $invoiceId");
            }

            $invoiceData = $pInvoice['data'];
            $included = $pInvoice['included'] ?? [];
            $invoiceAttribs = $invoiceData['attributes'] ?? [];

            // 3. Extract Contact, Products, E-Invoice data
            $contact = null;
            $productsMap = [];
            $eInvoiceNotes = [];
            $parasutPdfUrl = '';

            foreach ($included as $inc) {
                if ($inc['type'] === 'contacts')
                    $contact = $inc['attributes'];
                if ($inc['type'] === 'products')
                    $productsMap[$inc['id']] = $inc['attributes'];
                if (in_array($inc['type'], ['active_e_document', 'e_document', 'e_invoices'])) {
                    $eAttr = $inc['attributes'] ?? [];
                    foreach (['note', 'notes'] as $nf) {
                        if (!empty($eAttr[$nf])) {
                            $eInvoiceNotes = array_merge(
                                $eInvoiceNotes,
                                is_array($eAttr[$nf]) ? $eAttr[$nf] : array_filter(explode("\n", $eAttr[$nf]))
                            );
                        }
                    }
                    $pdfCand = $eAttr['signed_pdf_url'] ?? $eAttr['pdf_url'] ?? '';
                    if (!empty($pdfCand))
                        $parasutPdfUrl = $pdfCand;
                }
            }

            if (!$contact)
                throw new Exception("Fatura için Müşteri (Contact) bilgisi bulunamadı.");

            // 4. Sync / find Zoho Account
            $accountName = $contact['name'];
            $accountEmail = $contact['email'] ?? '';
            $accountPhone = preg_replace('/[^0-9+\-]/', '', $contact['phone'] ?? '');
            if (empty($accountPhone) || strlen($accountPhone) < 5)
                $accountPhone = null;
            $accountWebsite = $contact['website'] ?? '';
            if (empty($accountWebsite) && !empty($accountEmail)) {
                $parts = explode('@', $accountEmail);
                if (count($parts) === 2)
                    $accountWebsite = $parts[1];
            }

            $billingStreet = $contact['address'] ?? '';
            $billingCity = $contact['city'] ?? '';
            $billingDistrict = $contact['district'] ?? '';
            $billingCountry = $contact['country'] ?? 'Türkiye';

            $zohoAccountId = null;
            if (!empty($accountWebsite)) {
                $za = $this->zoho->searchAccountByWebsite($accountWebsite);
                if ($za)
                    $zohoAccountId = $za['id'];
            }
            if (!$zohoAccountId) {
                $za = $this->zoho->searchAccount($accountName);
                if ($za)
                    $zohoAccountId = $za['id'];
            }
            if (!$zohoAccountId && !empty($accountPhone)) {
                $za = $this->zoho->searchAccountByPhone($accountPhone);
                if ($za)
                    $zohoAccountId = $za['id'];
            }
            if (!$zohoAccountId) {
                $emailForZoho = !empty($accountEmail) ? $accountEmail : "no-email-" . uniqid() . "@example.com";
                $zohoAccountId = $this->zoho->createAccount($accountName, $emailForZoho, $accountPhone, [
                    'tax_number' => $contact['tax_number'] ?? '',
                    'tax_office' => $contact['tax_office'] ?? '',
                    'billing_street' => $billingStreet,
                    'billing_city' => $billingCity,
                    'billing_state' => $billingDistrict,
                    'billing_country' => $billingCountry,
                    'website' => $accountWebsite,
                ]);
            }
            if (!$zohoAccountId)
                throw new Exception("Zoho Müşteri (Account) oluşturulamadı.");

            // 5. Calculate VAT rate
            $iNet = (float) ($invoiceAttribs['net_total'] ?? 0);
            $iGross = (float) ($invoiceAttribs['gross_total'] ?? 0);
            $subTotal = min($iNet, $iGross);
            $grandTotal = max($iNet, $iGross);
            $calculatedVatRate = 0;
            if ($subTotal > 0) {
                $rateRaw = (($grandTotal - $subTotal) / $subTotal) * 100;
                foreach ([0, 1, 8, 10, 18, 20] as $r) {
                    if (abs($rateRaw - $r) < 0.5) {
                        $calculatedVatRate = $r;
                        break;
                    }
                }
            }

            // 6. Build Line Items
            $dbTaxMap = [];
            try {
                $stmtTax = $this->pdo->prepare("SELECT pii.parasut_product_id, pii.vat_rate FROM parasut_invoice_items pii JOIN parasut_invoices pi ON pii.invoice_id = pi.id WHERE pi.parasut_id = ?");
                $stmtTax->execute([$invoiceId]);
                foreach ($stmtTax->fetchAll(PDO::FETCH_ASSOC) as $dbi) {
                    $dbTaxMap[$dbi['parasut_product_id']] = $dbi['vat_rate'];
                }
            } catch (Exception $e) {
                writeLog("Warning: Could not fetch local VAT rates: " . $e->getMessage(), 'WARNING', 'sync');
            }

            $zohoLineItems = [];
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
                        $zProduct = $this->zoho->searchProduct($pCode);
                        $zProductId = $zProduct['id'] ?? null;
                        if (!$zProductId) {
                            $createRes = $this->zoho->createProduct(['name' => $pName, 'code' => $pCode, 'price' => $unitPrice, 'vat_rate' => $vatRate]);
                            $zProductId = $createRes['data'][0]['details']['id'] ?? null;
                        }
                        if ($zProductId) {
                            $zohoLineItems[] = [
                                'product_id' => $zProductId,
                                'quantity' => $quantity,
                                'price' => $unitPrice,
                                'vat_rate' => $vatRate,
                                'discount' => (float) ($inc['attributes']['discount_amount'] ?? $inc['attributes']['discount'] ?? 0),
                            ];
                        }
                    }
                }
            }

            if (empty($zohoLineItems))
                throw new Exception("Fatura kalemleri (Ürünler) Zoho'ya aktarılamadı.");

            // 7. Notes
            $notesArray = $eInvoiceNotes;
            if (empty($notesArray)) {
                foreach (['invoice_note', 'e_invoice_notes', 'notes', 'invoice_notes', 'Note'] as $field) {
                    if (!empty($invoiceAttribs[$field])) {
                        $notesArray = array_merge(
                            $notesArray,
                            is_array($invoiceAttribs[$field])
                            ? $invoiceAttribs[$field]
                            : array_filter(explode("\n", $invoiceAttribs[$field]))
                        );
                    }
                }
            }
            $currency = $invoiceAttribs['currency'] ?? 'TRY';
            if ($currency === 'TRL')
                $currency = 'TRY';
            if (!empty($subTotal) && !empty($currency)) {
                $notesArray[] = amountToTurkishWords($subTotal, $currency);
            }
            $notesContent = implode("\n", $notesArray);

            // 8. Invoice status from e-doc
            $invoiceStatus = 'Oluşturuldu';
            foreach ($included as $inc) {
                if (in_array($inc['type'], ['active_e_document', 'e_document', 'e_invoices'])) {
                    $eDocStatus = $inc['attributes']['status'] ?? '';
                    if (!empty($eDocStatus)) {
                        $statusMap = ['successful' => 'Onaylandı', 'approved' => 'Onaylandı', 'waiting' => 'Onay Bekliyor', 'pending' => "GİB'den Onay Bekliyor", 'error' => 'Zarf Hatalı/Hata Var', 'failed' => 'Zarf Hatalı/Hata Var', 'rejected' => 'Reddedildi', 'cancelled' => 'İptal Edildi', 'sent' => 'Gönderildi'];
                        $invoiceStatus = $statusMap[$eDocStatus] ?? ucfirst($eDocStatus);
                    }
                    break;
                }
            }

            // 9. Build invoice options
            $invoiceNumber = $invoiceAttribs['invoice_id'] ?? $invoiceAttribs['invoice_no'] ?? '';
            $invoiceDate = $invoiceAttribs['issue_date'] ?? '';
            $dueDate = $invoiceAttribs['due_date'] ?? '';
            $description = $invoiceAttribs['description'] ?? '';
            $descriptionRaw = $description ?: $invoiceNumber ?: $invoiceId;
            $subject = mb_strlen($descriptionRaw) > 120 ? mb_substr($descriptionRaw, 0, 117) . '...' : $descriptionRaw;

            $remaining = (float) ($invoiceAttribs['remaining_payment'] ?? 0);
            $netTotalFloat = (float) ($invoiceAttribs['net_total'] ?? 0);

            $parasutCompanyId = getSetting($this->pdo, 'parasut_company_id') ?: '';
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
                'exchange_rate' => $invoiceAttribs['exchange_rate'] ?? null,
                'payment_status' => ($remaining <= 0 && $netTotalFloat > 0) ? 'Ödendi' : (($remaining < $netTotalFloat && $remaining > 0) ? 'Kısmi Ödeme' : 'Ödenmedi'),
                'parasut_url' => $parasutCompanyId ? "https://uygulama.parasut.com/$parasutCompanyId/satislar/$invoiceId" : '',
            ];
            if (!empty($parasutPdfUrl))
                $invoiceOptions['parasut_pdf_url'] = $parasutPdfUrl;

            // 10. Create in Zoho
            $zohoInvoice = $this->zoho->createInvoice($subject, $zohoAccountId, $zohoLineItems, $currency, $invoiceOptions);
            $zohoInvoiceId = $zohoInvoice['data'][0]['details']['id'] ?? null;

            // Tax auto-fix: if Zoho rejects due to missing product tax, assign taxes and retry
            if (!$zohoInvoiceId) {
                $errorMsg = $zohoInvoice['data'][0]['message'] ?? '';
                if (stripos($errorMsg, 'tax is not present') !== false) {
                    writeLog("Tax error detected in SyncService — auto-fixing product taxes and retrying...", 'INFO', 'sync');
                    // Find affected product IDs and assign all org taxes
                    foreach ($zohoLineItems as $item) {
                        try {
                            $this->zoho->ensureProductTaxes($item['product_id']);
                        } catch (Exception $taxEx) {
                            writeLog("Tax fix failed for {$item['product_id']}: " . $taxEx->getMessage(), 'WARNING', 'sync');
                        }
                    }
                    // Retry
                    $zohoInvoice = $this->zoho->createInvoice($subject, $zohoAccountId, $zohoLineItems, $currency, $invoiceOptions);
                    $zohoInvoiceId = $zohoInvoice['data'][0]['details']['id'] ?? null;
                }
            }

            if (!$zohoInvoiceId) {
                throw new Exception("Zoho Fatura Oluşturma Hatası: " . ($zohoInvoice['data'][0]['message'] ?? 'Bilinmeyen hata'));
            }

            // Add notes
            if (!empty($notesContent)) {
                try {
                    $this->zoho->addNote('Invoices', $zohoInvoiceId, $notesContent);
                } catch (Exception $e) {
                    writeLog("Warning: Could not add notes: " . $e->getMessage(), 'WARNING', 'sync');
                }
            }

            // Update DB
            $zohoTotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['price'], $zohoLineItems));
            $this->pdo->prepare("UPDATE parasut_invoices SET zoho_invoice_id = ?, zoho_total = ?, synced_to_zoho = 1, synced_at = NOW() WHERE parasut_id = ?")
                ->execute([$zohoInvoiceId, $zohoTotal, $invoiceId]);

            writeLog("exportInvoiceToZoho SUCCESS: Parasut $invoiceId → Zoho $zohoInvoiceId", 'INFO', 'sync');
            return ['success' => true, 'message' => "Fatura Zoho'ya aktarıldı.", 'zoho_id' => $zohoInvoiceId];

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            writeLog("exportInvoiceToZoho ERROR ($invoiceId): $errorMsg", 'ERROR', 'sync');
            try {
                $this->pdo->prepare("UPDATE parasut_invoices SET synced_to_zoho = 2, sync_error = ? WHERE parasut_id = ?")
                    ->execute([$errorMsg, $invoiceId]);
            } catch (Exception $dbEx) {
                writeLog("Could not save error to DB: " . $dbEx->getMessage(), 'ERROR', 'sync');
            }
            return ['success' => false, 'message' => $errorMsg, 'zoho_id' => null];
        }
    }
}

