<?php
// controllers/PurchaseOrderController.php
// Purchase order CRUD and sync operations

class PurchaseOrderController extends BaseController
{
    // ==================== FETCH FROM PARASUT API ====================

    public function fetch_parasut_purchase_orders(): void
    {
        enableLongRunningMode(256);

        $parasut = $this->parasut();
        $fullSync = filter_var($this->input('full_sync', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $syncResult = $parasut->syncPurchaseBills(10000, $fullSync);
            $upsertCount = $syncResult['purchase_orders'];

            $poData = $this->getPurchaseOrdersFromDB(1, 250, null, null);

            jsonResponse([
                'success' => true,
                'message' => "Paraşüt'ten $upsertCount gider faturası güncellendi.",
                'inserted_count' => $upsertCount,
                'data' => $poData['data'],
                'meta' => $poData['meta']
            ]);
        } catch (\Exception $e) {
            writeLog("Fetch Error (Purchase Orders): " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==================== GET FROM LOCAL DB ====================

    public function get_parasut_purchase_orders(): void
    {
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 200);
        $syncStatus = $this->input('sync_status') ?: null;
        $year = $this->input('year') ?: null;

        $poData = $this->getPurchaseOrdersFromDB($page, $limit, $syncStatus, $year);

        $stmtMax = $this->pdo->query("SELECT MAX(issue_date) FROM parasut_purchase_orders");
        $poData['meta']['latest_issue_date'] = $stmtMax->fetchColumn();

        jsonResponse(['success' => true, 'data' => $poData['data'], 'meta' => $poData['meta']]);
    }

    // ==================== GET LOCAL DETAILS ====================

    public function get_parasut_purchase_order_details(): void
    {
        $parasutId = $this->requireInput('id');

        $stmt = $this->pdo->prepare("SELECT id FROM parasut_purchase_orders WHERE parasut_id = ?");
        $stmt->execute([$parasutId]);
        $localPOId = $stmt->fetchColumn();

        $data = $localPOId ? $this->getPurchaseOrderItemsFromDB($localPOId) : [];
        jsonResponse(['success' => true, 'data' => $data]);
    }

    // ==================== FETCH DETAILS FROM PARASUT API ====================

    public function fetch_parasut_purchase_order_details(): void
    {
        $id = $this->requireInput('id');
        $parasut = $this->parasut();
        $response = $parasut->getPurchaseBillDetails($id);

        // Find or create local PO
        $stmt = $this->pdo->prepare("SELECT id FROM parasut_purchase_orders WHERE parasut_id = ?");
        $stmt->execute([$id]);
        $localPOId = $stmt->fetchColumn();

        if (!$localPOId) {
            $data = $response['data'];
            $stmt = $this->pdo->prepare("INSERT INTO parasut_purchase_orders (parasut_id, invoice_number, issue_date, net_total, currency, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $id,
                $data['attributes']['invoice_no'] ?? '',
                $data['attributes']['issue_date'] ?? null,
                $data['attributes']['net_total'] ?? 0,
                $data['attributes']['currency'] ?? 'TRY',
                $data['attributes']['description'] ?? 'Detaydan oluşturuldu'
            ]);
            $localPOId = $this->pdo->lastInsertId();
        }

        // Build Product Map
        $productsMap = [];
        if (isset($response['included'])) {
            foreach ($response['included'] as $inc) {
                if ($inc['type'] === 'products') {
                    $productsMap[$inc['id']] = $inc['attributes'];
                }
            }
        }

        // Clear and re-insert items
        $this->pdo->prepare("DELETE FROM parasut_purchase_order_items WHERE purchase_order_id = ?")->execute([$localPOId]);

        $insertStmt = $this->pdo->prepare("INSERT INTO parasut_purchase_order_items 
            (purchase_order_id, parasut_product_id, parasut_detail_id, product_name, quantity, unit_price, discount_amount, vat_rate, net_total) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (isset($response['included'])) {
            foreach ($response['included'] as $inc) {
                if ($inc['type'] === 'purchase_bill_details' || $inc['type'] === 'purchase_bill_detail') {
                    $pProductId = $inc['relationships']['product']['data']['id'] ?? null;
                    $productName = $productsMap[$pProductId]['name'] ?? 'Bilinmeyen Ürün';

                    $localProductId = null;
                    if ($pProductId) {
                        $pStmt = $this->pdo->prepare("SELECT id FROM parasut_products WHERE parasut_id = ?");
                        $pStmt->execute([$pProductId]);
                        $localProductId = $pStmt->fetchColumn();
                    }

                    $insertStmt->execute([
                        $localPOId,
                        $localProductId,
                        $inc['id'],
                        $productName,
                        $inc['attributes']['quantity'] ?? 0,
                        $inc['attributes']['unit_price'] ?? 0,
                        $inc['attributes']['discount'] ?? 0,
                        $inc['attributes']['vat_rate'] ?? 0,
                        $inc['attributes']['net_total'] ?? 0
                    ]);
                }
            }
        }

        jsonResponse(['success' => true, 'data' => $this->getPurchaseOrderItemsFromDB($localPOId)]);
    }

    // ==================== FETCH ZOHO PURCHASE ORDERS ====================

    public function fetch_zoho_purchase_orders(): void
    {
        // Stub — can be implemented when needed
        jsonResponse(['success' => true, 'data' => [], 'message' => 'Zoho PO fetch not yet implemented']);
    }

    // ==================== EXPORT PO TO ZOHO ====================

    public function export_purchase_order_to_zoho(): void
    {
        try {
            writeLog("=== Starting Purchase Order Export ===");

            $poId = $this->requireInput('po_id');
            $force = filter_var($this->input('force', false), FILTER_VALIDATE_BOOLEAN);

            // === Layer 1: DB Lock — Prevent concurrent duplicate ===
            $this->pdo->beginTransaction();

            $checkStmt = $this->pdo->prepare("SELECT parasut_id, zoho_po_id, synced_to_zoho, payment_status, invoice_number FROM parasut_purchase_orders WHERE parasut_id = ? FOR UPDATE");
            $checkStmt->execute([$poId]);
            $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$existing) {
                $this->pdo->rollBack();
                throw new \Exception("Purchase order database'de bulunamadı. ID: $poId");
            }

            if (!$force && ($existing['synced_to_zoho'] == 1 || !empty($existing['zoho_po_id']))) {
                $this->pdo->rollBack();
                jsonResponse(['success' => true, 'message' => "Purchase order zaten Zoho'ya aktarılmış.", 'zoho_id' => $existing['zoho_po_id']]);
                return;
            }

            // Mark as in-progress to prevent concurrent export
            $this->pdo->prepare("UPDATE parasut_purchase_orders SET synced_to_zoho = 3 WHERE parasut_id = ?")->execute([$poId]);
            $this->pdo->commit();

            $parasut = $this->parasut();
            $zoho = $this->zoho();

            // === Layer 2: Zoho Duplicate Search ===
            $invoiceNo = $existing['invoice_number'] ?? '';
            if (!$force && !empty($invoiceNo)) {
                $existingPO = $zoho->searchPurchaseOrderByNumber($invoiceNo);
                if ($existingPO) {
                    $zohoId = $existingPO['id'];
                    writeLog("PO duplicate found in Zoho: $invoiceNo → $zohoId");
                    $this->pdo->prepare("UPDATE parasut_purchase_orders SET zoho_po_id = ?, synced_to_zoho = 1, synced_at = NOW(), sync_error = NULL WHERE parasut_id = ?")
                        ->execute([$zohoId, $poId]);
                    jsonResponse(['success' => true, 'message' => "Purchase order zaten Zoho'da mevcut (mükerrer engellendi).", 'zoho_po_id' => $zohoId]);
                    return;
                }
            }

            // 1. Fetch from Parasut
            $pPO = $parasut->getPurchaseBillDetails($poId);
            if (!isset($pPO['data']))
                throw new \Exception("Paraşüt'ten geçersiz yanıt alındı");

            $poData = $pPO['data'];
            $included = $pPO['included'] ?? [];
            $poAttribs = $poData['attributes'] ?? [];

            // 2. Extract Supplier & Products
            $supplier = null;
            $productsMap = [];

            foreach ($included as $inc) {
                $type = $inc['type'] ?? '';
                if (in_array($type, ['contacts', 'suppliers', 'contact', 'supplier', 'spenders']))
                    $supplier = $inc['attributes'];
                if ($type === 'products')
                    $productsMap[$inc['id']] = $inc['attributes'];
            }

            // Fallback: fetch supplier directly from Parasut if relationship ID exists
            if (!$supplier) {
                $supplierRel = $poData['relationships']['supplier']['data']
                    ?? $poData['relationships']['spender']['data']
                    ?? null;

                if ($supplierRel && !empty($supplierRel['id'])) {
                    $supplierId = $supplierRel['id'];
                    writeLog("PO: Fetching supplier directly from Parasut. ID: $supplierId");
                    try {
                        $contactData = $parasut->request('GET', "/contacts/$supplierId");
                        if (isset($contactData['data']['attributes'])) {
                            $supplier = $contactData['data']['attributes'];
                            writeLog("PO supplier fetched: " . ($supplier['name'] ?? 'unknown'));
                        }
                    } catch (\Exception $e) {
                        writeLog("PO: Could not fetch supplier $supplierId: " . $e->getMessage());
                    }
                }
            }

            // If genuinely no supplier — skip this PO gracefully
            if (!$supplier) {
                $desc = trim($poAttribs['description'] ?? '');
                writeLog("PO SKIP: No supplier for PO $poId. (Açıklama: $desc)");
                // Mark as skipped (status 4) so it won't be retried
                $this->pdo->prepare("UPDATE parasut_purchase_orders SET synced_to_zoho = 4, sync_error = ? WHERE parasut_id = ?")
                    ->execute(["Tedarikçi tanımsız - $desc", $poId]);
                jsonResponse([
                    'success' => false,
                    'message' => "Atlandı: Parasut'te tedarikçi tanımlı değil." . ($desc ? " ($desc)" : ''),
                    'skipped' => true
                ]);
                return;
            }

            // 3. Match/Create Vendor in Zoho
            $vendorName = $supplier['name'];
            $vendorId = null;

            // First try to find existing vendor
            $existingVendor = $zoho->searchVendor($vendorName);
            if ($existingVendor) {
                $vendorId = $existingVendor['id'];
                writeLog("Vendor found: $vendorName → $vendorId");
            } else {
                // Create new vendor — returns raw API response
                $vendorResult = $zoho->createVendor($vendorName, $supplier['email'] ?? '', $supplier['phone'] ?? '');
                if (isset($vendorResult['data'][0]['status']) && $vendorResult['data'][0]['status'] === 'success') {
                    $vendorId = $vendorResult['data'][0]['details']['id'];
                    writeLog("Vendor created: $vendorName → $vendorId");
                } else {
                    $errMsg = $vendorResult['data'][0]['message'] ?? json_encode($vendorResult);
                    throw new \Exception("Vendor oluşturulamadı ($vendorName): $errMsg");
                }
            }

            // 4. Build Line Items
            $lineItems = [];
            foreach ($included as $inc) {
                if ($inc['type'] === 'purchase_bill_details' || $inc['type'] === 'purchase_bill_detail') {
                    $productId = $inc['relationships']['product']['data']['id'] ?? null;
                    $productInfo = $productsMap[$productId] ?? [];
                    $productCode = $productInfo['code'] ?? null;

                    if (empty($productCode))
                        throw new \Exception("Ürün kodu eksik olan kalem var.");

                    // Lookup Zoho Product
                    $zohoProductStmt = $this->pdo->prepare("SELECT zoho_id FROM zoho_products WHERE product_code = ? LIMIT 1");
                    $zohoProductStmt->execute([$productCode]);
                    $zohoProductId = $zohoProductStmt->fetchColumn();

                    if (!$zohoProductId) {
                        $productName = $productInfo['name'] ?? $productCode;
                        $unitPrice = $inc['attributes']['unit_price'] ?? 0;
                        $createResult = $zoho->createProduct([
                            'name' => $productName,
                            'code' => $productCode,
                            'price' => $unitPrice,
                            'vat_rate' => $inc['attributes']['vat_rate'] ?? 18
                        ]);

                        if (isset($createResult['data'][0]['status']) && $createResult['data'][0]['status'] === 'success') {
                            $zohoProductId = $createResult['data'][0]['details']['id'];
                            $this->pdo->prepare("INSERT INTO zoho_products (zoho_id, product_code, product_name, unit_price, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), unit_price = VALUES(unit_price), updated_at = NOW()")
                                ->execute([$zohoProductId, $productCode, $productName, $unitPrice]);
                        } else {
                            throw new \Exception("Ürün '$productCode' Zoho'da oluşturulamadı");
                        }
                    }

                    $lineItems[] = [
                        'product_id' => $zohoProductId,
                        'quantity' => $inc['attributes']['quantity'] ?? 1,
                        'price' => $inc['attributes']['unit_price'] ?? 0,
                        'vat_rate' => $inc['attributes']['vat_rate'] ?? 0,
                        'discount' => (float) ($inc['attributes']['discount_amount'] ?? $inc['attributes']['discount'] ?? 0)
                    ];
                }
            }

            if (empty($lineItems)) {
                $defaultProductId = getSetting($this->pdo, 'default_product_id');
                if (empty($defaultProductId)) {
                    throw new \Exception("Kalem bulunamadı ve varsayılan ürün ID'si ayarlanmamış. Ayarlar sayfasından 'default_product_id' değerini girin.");
                }
                $lineItems[] = [
                    'product_id' => $defaultProductId,
                    'quantity' => 1,
                    'price' => $poAttribs['net_total'] ?? 0,
                    'vat_rate' => 0,
                    'description' => $poAttribs['description'] ?: ($poAttribs['invoice_no'] ?? "PO-$poId")
                ];
            }

            // 5. Create PO in Zoho
            $poSubject = $poAttribs['description'] ?: ($poAttribs['invoice_no'] ?? "PO-$poId");
            $poCurrency = ($poAttribs['currency'] ?? 'TRY') === 'TRL' ? 'TRY' : ($poAttribs['currency'] ?? 'TRY');

            $statusMap = ['paid' => 'ÖDENDİ', 'partially_paid' => 'KISMİ ÖDEME'];
            $zohoStatus = $statusMap[$existing['payment_status'] ?? ''] ?? 'BEKLEMEDE';

            $parasutCompanyId = getSetting($this->pdo, 'parasut_company_id') ?: '136555';
            $options = [
                'po_date' => $poAttribs['issue_date'] ?? date('Y-m-d'),
                'po_number' => $poAttribs['invoice_no'] ?? '',
                'due_date' => $poAttribs['due_date'] ?? null,
                'exchange_rate' => $poAttribs['exchange_rate'] ?? null,
                'notes' => $poAttribs['invoice_no'] ?? '',
                'status' => $zohoStatus,
                'parasut_url' => "https://uygulama.parasut.com/$parasutCompanyId/fis-faturalar/$poId"
            ];

            $zohoResult = $zoho->createPurchaseOrder($poSubject, $vendorId, $lineItems, $poCurrency, $options);

            if (isset($zohoResult['data'][0]['status']) && $zohoResult['data'][0]['status'] === 'success') {
                $zohoPOId = $zohoResult['data'][0]['details']['id'];

                $this->pdo->prepare("UPDATE parasut_purchase_orders SET zoho_po_id = ?, synced_to_zoho = 1, synced_at = NOW(), sync_error = NULL WHERE parasut_id = ?")
                    ->execute([$zohoPOId, $poId]);

                $this->pdo->prepare("INSERT INTO zoho_purchase_orders (id, invoice_number, po_date, total, currency, status, vendor_name, description, raw_data, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE invoice_number = VALUES(invoice_number), po_date = VALUES(po_date), total = VALUES(total), currency = VALUES(currency), vendor_name = VALUES(vendor_name), description = VALUES(description), raw_data = VALUES(raw_data)")
                    ->execute([$zohoPOId, $poSubject, $poAttribs['issue_date'] ?? date('Y-m-d'), $poAttribs['net_total'] ?? 0, $poCurrency, $vendorName, $poAttribs['description'] ?? '', json_encode($zohoResult)]);

                jsonResponse(['success' => true, 'message' => "Purchase Order Zoho'ya aktarıldı!", 'zoho_po_id' => $zohoPOId]);
            } else {
                throw new \Exception("Zoho hatası: " . ($zohoResult['data'][0]['message'] ?? 'Bilinmeyen'));
            }

        } catch (\Exception $e) {
            writeLog("PO Export Error: " . $e->getMessage());
            if (isset($poId)) {
                try {
                    $this->pdo->prepare("UPDATE parasut_purchase_orders SET synced_to_zoho = 2, sync_error = ? WHERE parasut_id = ?")->execute([$e->getMessage(), $poId]);
                } catch (\Exception $dbErr) { /* ignore */
                }
            }
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    // ==================== GET UNSYNCED PO IDS ====================

    public function get_unsynced_purchase_orders_ids(): void
    {
        $limit = $this->inputInt('limit', 50);
        $syncStatus = $this->input('sync_status', '0');
        $year = $this->input('year');

        // Auto-recover stuck in-progress records (status=3 older than 5 min)
        $this->pdo->exec("UPDATE parasut_purchase_orders SET synced_to_zoho = 0, sync_error = 'Stuck in-progress - auto recovered' WHERE synced_to_zoho = 3 AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

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

        $stmt = $this->pdo->prepare("SELECT parasut_id as id, invoice_number FROM parasut_purchase_orders WHERE $whereSql ORDER BY issue_date DESC LIMIT :limit");
        foreach ($params as $key => $val)
            $stmt->bindValue($key, $val);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $purchaseOrders = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM parasut_purchase_orders WHERE $whereSql");
        foreach ($params as $key => $val)
            $countStmt->bindValue($key, $val);
        $countStmt->execute();

        jsonResponse(['success' => true, 'count' => count($purchaseOrders), 'total_remaining' => $countStmt->fetchColumn(), 'data' => $purchaseOrders]);
    }

    // ==================== HELPER: DB QUERIES ====================

    private function getPurchaseOrdersFromDB(int $page, int $limit, ?string $syncStatus, ?string $year): array
    {
        $offset = ($page - 1) * $limit;
        $where = [];
        $params = [];

        if ($syncStatus === 'synced')
            $where[] = 'synced_to_zoho = 1';
        elseif ($syncStatus === 'error')
            $where[] = 'sync_error IS NOT NULL';
        else
            $where[] = '(synced_to_zoho = 0 OR synced_to_zoho IS NULL)';

        if ($year) {
            $where[] = 'YEAR(issue_date) = ?';
            $params[] = $year;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM parasut_purchase_orders $whereClause");
        $stmtCount->execute($params);
        $totalRecords = $stmtCount->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT * FROM parasut_purchase_orders $whereClause ORDER BY issue_date DESC, id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'meta' => ['total_pages' => ceil($totalRecords / $limit), 'current_page' => $page, 'total_count' => $totalRecords, 'limit' => $limit]
        ];
    }

    private function getPurchaseOrderItemsFromDB(int $poId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM parasut_purchase_order_items WHERE purchase_order_id = ? ORDER BY id ASC");
        $stmt->execute([$poId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
