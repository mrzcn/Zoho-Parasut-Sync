<?php
// controllers/MergeController.php
// Duplicate detection and safe merge operations

class MergeController extends BaseController
{
    /**
     * Helper: Check if merge_log table exists
     */
    private function mergeLogExists(): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM merge_log LIMIT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Helper: Log a merge operation (safe — skips if table doesn't exist)
     */
    private function logMerge(string $entityType, array $data): ?int
    {
        if (!$this->mergeLogExists())
            return null;

        $stmt = $this->pdo->prepare("INSERT INTO merge_log 
            (entity_type, source_local_id, target_local_id, source_zoho_id, target_zoho_id, 
             affected_records, backup_data, status, performed_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $entityType,
            $data['source_local_id'] ?? null,
            $data['target_local_id'] ?? null,
            $data['source_zoho_id'] ?? null,
            $data['target_zoho_id'] ?? null,
            json_encode($data['affected_records'] ?? []),
            json_encode($data['backup_data'] ?? []),
            $data['status'] ?? 'pending',
            $_SESSION['user_id'] ?? 'system'
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Helper: Update merge log status
     */
    private function updateMergeLog(int $id, string $status, ?array $affectedRecords = null, ?string $error = null): void
    {
        if (!$this->mergeLogExists())
            return;

        $sets = ['status = ?'];
        $params = [$status];

        if ($affectedRecords !== null) {
            $sets[] = 'affected_records = ?';
            $params[] = json_encode($affectedRecords);
        }
        if ($error !== null) {
            $sets[] = 'error_message = ?';
            $params[] = $error;
        }
        if ($status === 'completed') {
            $sets[] = 'completed_at = NOW()';
        }

        $params[] = $id;
        $this->pdo->prepare("UPDATE merge_log SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    // ==================== PRODUCT LISTING ====================

    /**
     * Get all Zoho products from local DB (searchable, paginated)
     */
    public function get_all_zoho_products(): void
    {
        $startTime = microtime(true);
        writeLog("get_all_zoho_products: START");

        $search = trim($this->input('search', ''));
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 200);
        $offset = ($page - 1) * $limit;

        writeLog("get_all_zoho_products: search='$search', page=$page, limit=$limit, offset=$offset");

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = "WHERE product_name LIKE ? OR product_code LIKE ?";
            $params = ["%$search%", "%$search%"];
        }

        try {
            // Count
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM zoho_products $where");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
            writeLog("get_all_zoho_products: COUNT=$total");

            // Fetch
            $stmt = $this->pdo->prepare("SELECT zoho_id, product_name, product_code, unit_price 
                FROM zoho_products $where 
                ORDER BY product_name ASC 
                LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $elapsed = round((microtime(true) - $startTime) * 1000);
            writeLog("get_all_zoho_products: FETCHED " . count($products) . " rows in {$elapsed}ms (total=$total, page=$page)");

            jsonResponse([
                'success' => true,
                'data' => $products,
                'total' => $total,
                'page' => $page,
                'pages' => ceil($total / $limit)
            ]);
        } catch (\Throwable $e) {
            $elapsed = round((microtime(true) - $startTime) * 1000);
            writeLog("get_all_zoho_products: ERROR in {$elapsed}ms - " . $e->getMessage(), 'ERROR');
            jsonResponse([
                'success' => false,
                'message' => 'DB Hatası: ' . $e->getMessage()
            ], 500);
        }
    }

    // ==================== DUPLICATE DETECTION ====================

    /**
     * Detect duplicate products: same product_code, multiple zoho_ids
     */
    public function detect_duplicate_products(): void
    {
        $stmt = $this->pdo->query("
            SELECT product_code, 
                   COUNT(*) as count,
                   GROUP_CONCAT(zoho_id ORDER BY created_at ASC) as zoho_ids,
                   GROUP_CONCAT(product_name ORDER BY created_at ASC SEPARATOR '|||') as names,
                   GROUP_CONCAT(unit_price ORDER BY created_at ASC) as prices
            FROM zoho_products 
            WHERE product_code IS NOT NULL AND product_code != ''
            GROUP BY product_code 
            HAVING COUNT(*) > 1
            ORDER BY count DESC
        ");
        $zohoDuplicates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($zohoDuplicates as $dup) {
            $ids = explode(',', $dup['zoho_ids']);
            $names = explode('|||', $dup['names']);
            $prices = explode(',', $dup['prices']);
            $items = [];
            foreach ($ids as $i => $id) {
                $items[] = [
                    'zoho_id' => $id,
                    'name' => $names[$i] ?? '',
                    'price' => $prices[$i] ?? 0
                ];
            }
            $results[] = [
                'product_code' => $dup['product_code'],
                'count' => (int) $dup['count'],
                'items' => $items
            ];
        }

        jsonResponse([
            'success' => true,
            'data' => $results,
            'summary' => ['total_groups' => count($results)]
        ]);
    }

    /**
     * Detect duplicate invoices: same invoice_number, multiple records
     */
    public function detect_duplicate_invoices(): void
    {
        $stmt = $this->pdo->query("
            SELECT invoice_number,
                   COUNT(*) as count,
                   GROUP_CONCAT(parasut_id ORDER BY created_at ASC) as parasut_ids,
                   GROUP_CONCAT(COALESCE(zoho_invoice_id, 'null') ORDER BY created_at ASC) as zoho_ids,
                   GROUP_CONCAT(synced_to_zoho ORDER BY created_at ASC) as sync_statuses,
                   GROUP_CONCAT(net_total ORDER BY created_at ASC) as amounts,
                   GROUP_CONCAT(issue_date ORDER BY created_at ASC) as dates
            FROM parasut_invoices 
            WHERE invoice_number IS NOT NULL AND invoice_number != ''
            GROUP BY invoice_number 
            HAVING COUNT(*) > 1
            ORDER BY count DESC LIMIT 100
        ");
        $dbDuplicates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt2 = $this->pdo->query("
            SELECT invoice_number,
                   COUNT(*) as count,
                   GROUP_CONCAT(zoho_id ORDER BY created_at ASC) as zoho_ids,
                   GROUP_CONCAT(total ORDER BY created_at ASC) as amounts
            FROM zoho_invoices 
            WHERE invoice_number IS NOT NULL AND invoice_number != ''
            GROUP BY invoice_number 
            HAVING COUNT(*) > 1
            ORDER BY count DESC LIMIT 100
        ");
        $zohoDuplicates = $stmt2->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($dbDuplicates as $dup) {
            $pIds = explode(',', $dup['parasut_ids']);
            $zIds = explode(',', $dup['zoho_ids']);
            $statuses = explode(',', $dup['sync_statuses']);
            $amounts = explode(',', $dup['amounts']);
            $dates = explode(',', $dup['dates']);
            $items = [];
            foreach ($pIds as $i => $pid) {
                $items[] = [
                    'parasut_id' => $pid,
                    'zoho_invoice_id' => ($zIds[$i] ?? 'null') !== 'null' ? $zIds[$i] : null,
                    'synced' => (int) ($statuses[$i] ?? 0),
                    'amount' => $amounts[$i] ?? 0,
                    'date' => $dates[$i] ?? ''
                ];
            }
            $results[] = [
                'source' => 'parasut',
                'invoice_number' => $dup['invoice_number'],
                'count' => (int) $dup['count'],
                'items' => $items
            ];
        }

        foreach ($zohoDuplicates as $dup) {
            $zIds = explode(',', $dup['zoho_ids']);
            $amounts = explode(',', $dup['amounts']);
            $items = [];
            foreach ($zIds as $i => $zid) {
                $items[] = ['zoho_id' => $zid, 'amount' => $amounts[$i] ?? 0];
            }
            $results[] = [
                'source' => 'zoho',
                'invoice_number' => $dup['invoice_number'],
                'count' => (int) $dup['count'],
                'items' => $items
            ];
        }

        jsonResponse([
            'success' => true,
            'data' => $results,
            'summary' => [
                'parasut_duplicates' => count($dbDuplicates),
                'zoho_duplicates' => count($zohoDuplicates),
                'total_groups' => count($results)
            ]
        ]);
    }

    /**
     * Detect duplicate purchase orders
     */
    public function detect_duplicate_purchase_orders(): void
    {
        $stmt = $this->pdo->query("
            SELECT invoice_number,
                   COUNT(*) as count,
                   GROUP_CONCAT(parasut_id ORDER BY created_at ASC) as parasut_ids,
                   GROUP_CONCAT(COALESCE(zoho_po_id, 'null') ORDER BY created_at ASC) as zoho_ids,
                   GROUP_CONCAT(synced_to_zoho ORDER BY created_at ASC) as sync_statuses,
                   GROUP_CONCAT(net_total ORDER BY created_at ASC) as amounts,
                   GROUP_CONCAT(issue_date ORDER BY created_at ASC) as dates
            FROM parasut_purchase_orders 
            WHERE invoice_number IS NOT NULL AND invoice_number != ''
            GROUP BY invoice_number 
            HAVING COUNT(*) > 1
            ORDER BY count DESC LIMIT 100
        ");
        $duplicates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($duplicates as $dup) {
            $pIds = explode(',', $dup['parasut_ids']);
            $zIds = explode(',', $dup['zoho_ids']);
            $statuses = explode(',', $dup['sync_statuses']);
            $amounts = explode(',', $dup['amounts']);
            $dates = explode(',', $dup['dates']);
            $items = [];
            foreach ($pIds as $i => $pid) {
                $items[] = [
                    'parasut_id' => $pid,
                    'zoho_po_id' => ($zIds[$i] ?? 'null') !== 'null' ? $zIds[$i] : null,
                    'synced' => (int) ($statuses[$i] ?? 0),
                    'amount' => $amounts[$i] ?? 0,
                    'date' => $dates[$i] ?? ''
                ];
            }
            $results[] = [
                'invoice_number' => $dup['invoice_number'],
                'count' => (int) $dup['count'],
                'items' => $items
            ];
        }

        jsonResponse([
            'success' => true,
            'data' => $results,
            'summary' => ['total_groups' => count($results)]
        ]);
    }

    // ==================== FAZ 2: PRODUCT MERGE ====================

    /**
     * Merge duplicate Zoho products:
     *   - Keep master_id, delete duplicates from Zoho
     *   - Move invoice/PO references to master
     *   - Update local DB + optional Paraşüt sync
     *   - Accepts: master_id, duplicate_ids (JSON), new_name, parasut_id, product_code
     */
    public function merge_products(): void
    {
        enableLongRunningMode();
        $masterId = $this->requireInput('master_id');
        $duplicateIdsJson = $this->requireInput('duplicate_ids');
        $duplicateIds = json_decode($duplicateIdsJson, true);
        $newName = $this->input('new_name', '');
        $parasutId = $this->input('parasut_id', '');
        $productCode = $this->input('product_code', '');

        if (empty($duplicateIds) || !is_array($duplicateIds)) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz duplicate ID listesi.'], 400);
            return;
        }

        $zoho = $this->zoho();
        $log = [];
        $invoicesMoved = 0;
        $posMoved = 0;
        $duplicatesDeleted = 0;
        $affectedRecords = [];

        try {
            // Create merge log entry
            $mergeLogId = $this->logMerge('product', [
                'source_local_id' => implode(',', $duplicateIds),
                'target_local_id' => $masterId,
                'source_zoho_id' => implode(',', $duplicateIds),
                'target_zoho_id' => $masterId,
                'backup_data' => ['product_code' => $productCode, 'duplicate_ids' => $duplicateIds, 'master_id' => $masterId]
            ]);

            // 1. For each duplicate: move references, then delete/deactivate from Zoho
            foreach ($duplicateIds as $dupId) {
                $log[] = "İşleniyor: $dupId...";

                // 1a. Update local DB references
                try {
                    $invUpdate = $this->pdo->prepare("UPDATE parasut_invoices SET zoho_invoice_id = ? WHERE zoho_invoice_id = ?");
                    $invUpdate->execute([$masterId, $dupId]);
                    $invMoved = $invUpdate->rowCount();
                    if ($invMoved > 0) {
                        $invoicesMoved += $invMoved;
                        $log[] = "  ↪ $invMoved fatura referansı taşındı (lokal DB)";
                    }
                } catch (\Exception $e) {
                    $log[] = "  ⚠ Fatura ref taşınamadı: " . $e->getMessage();
                }

                try {
                    $poUpdate = $this->pdo->prepare("UPDATE parasut_purchase_orders SET zoho_po_id = ? WHERE zoho_po_id = ?");
                    $poUpdate->execute([$masterId, $dupId]);
                    $poMoved = $poUpdate->rowCount();
                    if ($poMoved > 0) {
                        $posMoved += $poMoved;
                        $log[] = "  ↪ $poMoved PO referansı taşındı (lokal DB)";
                    }
                } catch (\Exception $e) {
                    $log[] = "  ⚠ PO ref taşınamadı: " . $e->getMessage();
                }

                // 1b. Move Zoho references in ALL modules via Related Records API
                $moveResult = $this->moveProductReferences($zoho, $dupId, $masterId);
                $invoicesMoved += $moveResult['invoices'];
                $posMoved += $moveResult['pos'];
                $affectedRecords = array_merge($affectedRecords, $moveResult['affected']);
                $log = array_merge($log, $moveResult['log']);

                // 1c. Delete duplicate from Zoho (or deactivate if still involved in records)
                try {
                    $zoho->request('DELETE', "/Products?ids=$dupId");
                    $duplicatesDeleted++;
                    $affectedRecords[] = ['type' => 'product', 'id' => $dupId, 'action' => 'deleted'];
                    $log[] = "  🗑 Ürün $dupId Zoho'dan silindi";
                } catch (\Exception $e) {
                    // Product still involved in inventory records — deactivate instead
                    try {
                        $zoho->request('PUT', '/Products', [
                            'data' => [['id' => $dupId, 'Product_Active' => false]]
                        ]);
                        $duplicatesDeleted++;
                        $affectedRecords[] = ['type' => 'product', 'id' => $dupId, 'action' => 'deactivated'];
                        $log[] = "  🔒 Ürün $dupId pasifleştirildi (envanter kaydı mevcut)";
                    } catch (\Exception $e2) {
                        $log[] = "  ❌ Ürün $dupId silinemedi/pasifleştirilemedi: " . $e2->getMessage();
                    }
                }

                // 1c. Clean local DB
                $this->pdo->prepare("DELETE FROM zoho_products WHERE zoho_id = ?")->execute([$dupId]);
                $log[] = "  🧹 Lokal DB'den silindi";

                // 1d. Save redirect: old_id → master_id (for future syncs)
                try {
                    $this->pdo->exec("CREATE TABLE IF NOT EXISTS product_redirects (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        old_zoho_id VARCHAR(50) NOT NULL,
                        new_zoho_id VARCHAR(50) NOT NULL,
                        product_code VARCHAR(100),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_old_zoho (old_zoho_id)
                    )");
                    $this->pdo->prepare("INSERT IGNORE INTO product_redirects (old_zoho_id, new_zoho_id, product_code) VALUES (?, ?, ?)")
                        ->execute([$dupId, $masterId, $productCode]);
                    $log[] = "  🔀 Yönlendirme kaydedildi: $dupId → $masterId";
                } catch (\Exception $e) {
                    // Table exists with different schema — recreate it
                    if (strpos($e->getMessage(), 'Unknown column') !== false || strpos($e->getMessage(), 'Column not found') !== false) {
                        try {
                            $this->pdo->exec("DROP TABLE IF EXISTS product_redirects");
                            $this->pdo->exec("CREATE TABLE product_redirects (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                old_zoho_id VARCHAR(50) NOT NULL,
                                new_zoho_id VARCHAR(50) NOT NULL,
                                product_code VARCHAR(100),
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE KEY uq_old_zoho (old_zoho_id)
                            )");
                            $this->pdo->prepare("INSERT IGNORE INTO product_redirects (old_zoho_id, new_zoho_id, product_code) VALUES (?, ?, ?)")
                                ->execute([$dupId, $masterId, $productCode]);
                            $log[] = "  🔀 Redirect tablosu yeniden oluşturuldu, yönlendirme kaydedildi";
                        } catch (\Exception $e2) {
                            $log[] = "  ⚠ Redirect kaydedilemedi: " . $e2->getMessage();
                        }
                    } else {
                        $log[] = "  ⚠ Redirect kaydedilemedi: " . $e->getMessage();
                    }
                }

                usleep(300000); // Rate limit
            }

            // 2. Rename master if new name given
            if (!empty($newName)) {
                try {
                    $zoho->request('PUT', '/Products', [
                        'data' => [['id' => $masterId, 'Product_Name' => $newName]]
                    ]);
                    $this->pdo->prepare("UPDATE zoho_products SET product_name = ? WHERE zoho_id = ?")
                        ->execute([$newName, $masterId]);
                    $log[] = "✏️ Master ürün yeniden adlandırıldı: $newName";
                } catch (\Exception $e) {
                    $log[] = "⚠ İsim güncellenemedi: " . $e->getMessage();
                }
            }

            // 3. Update Paraşüt if requested
            if (!empty($parasutId)) {
                try {
                    $this->pdo->prepare("UPDATE parasut_products SET product_name = ? WHERE parasut_id = ?")
                        ->execute([$newName ?: $productCode, $parasutId]);
                    $log[] = "✅ Paraşüt kaydı güncellendi";
                } catch (\Exception $e) {
                    $log[] = "⚠ Paraşüt güncellenemedi: " . $e->getMessage();
                }
            }

            // 4. Update merge log
            if ($mergeLogId) {
                $this->updateMergeLog($mergeLogId, 'completed', $affectedRecords);
            }

            $log[] = "✅ Birleştirme tamamlandı!";
            writeLog("Product merge completed: master=$masterId, deleted=" . implode(',', $duplicateIds) . ", code=$productCode");

            jsonResponse([
                'success' => true,
                'message' => 'Birleştirme başarılı!',
                'details' => [
                    'invoices_moved' => $invoicesMoved,
                    'pos_moved' => $posMoved,
                    'duplicates_deleted' => $duplicatesDeleted,
                    'log' => $log
                ]
            ]);

        } catch (\Exception $e) {
            if ($mergeLogId ?? null) {
                $this->updateMergeLog($mergeLogId, 'failed', $affectedRecords, $e->getMessage());
            }
            writeLog("Product merge FAILED: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Birleştirme hatası: ' . $e->getMessage()], 500);
        }
    }

    // ==================== FAZ 3: INVOICE/PO MERGE ====================

    /**
     * Merge duplicate invoices: delete source from Zoho, update local DB
     */
    public function merge_invoices(): void
    {
        $sourceZohoId = $this->requireInput('source_zoho_id');
        $targetZohoId = $this->requireInput('target_zoho_id');

        if ($sourceZohoId === $targetZohoId) {
            jsonResponse(['success' => false, 'message' => 'Kaynak ve hedef aynı olamaz.'], 400);
            return;
        }

        try {
            $stmtSrc = $this->pdo->prepare("SELECT * FROM zoho_invoices WHERE zoho_id = ?");
            $stmtSrc->execute([$sourceZohoId]);
            $sourceBackup = $stmtSrc->fetch(\PDO::FETCH_ASSOC);

            $mergeLogId = $this->logMerge('invoice', [
                'source_zoho_id' => $sourceZohoId,
                'target_zoho_id' => $targetZohoId,
                'backup_data' => $sourceBackup ?: []
            ]);

            $zoho = $this->zoho();
            $affectedRecords = [];

            try {
                $zoho->request('DELETE', "/Invoices?ids=$sourceZohoId");
                $affectedRecords[] = ['action' => 'zoho_delete', 'zoho_id' => $sourceZohoId, 'status' => 'ok'];
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
                if ($mergeLogId)
                    $this->updateMergeLog($mergeLogId, 'failed', null, $errMsg);
                jsonResponse(['success' => false, 'message' => "Zoho fatura silinemedi: $errMsg"], 400);
                return;
            }

            $this->pdo->beginTransaction();
            $this->pdo->prepare("DELETE FROM zoho_invoices WHERE zoho_id = ?")->execute([$sourceZohoId]);
            $affectedRecords[] = ['action' => 'db_delete', 'table' => 'zoho_invoices'];

            $updateStmt = $this->pdo->prepare("UPDATE parasut_invoices SET zoho_invoice_id = ? WHERE zoho_invoice_id = ?");
            $updateStmt->execute([$targetZohoId, $sourceZohoId]);
            if ($updateStmt->rowCount() > 0) {
                $affectedRecords[] = ['action' => 'db_update', 'table' => 'parasut_invoices', 'rows' => $updateStmt->rowCount()];
            }
            $this->pdo->commit();

            if ($mergeLogId)
                $this->updateMergeLog($mergeLogId, 'completed', $affectedRecords);

            jsonResponse(['success' => true, 'message' => "Fatura birleştirme tamamlandı.", 'affected' => $affectedRecords]);

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction())
                $this->pdo->rollBack();
            if ($mergeLogId ?? null)
                $this->updateMergeLog($mergeLogId, 'failed', null, $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Merge duplicate purchase orders
     */
    public function merge_purchase_orders(): void
    {
        $sourceZohoId = $this->requireInput('source_zoho_id');
        $targetZohoId = $this->requireInput('target_zoho_id');

        if ($sourceZohoId === $targetZohoId) {
            jsonResponse(['success' => false, 'message' => 'Kaynak ve hedef aynı olamaz.'], 400);
            return;
        }

        try {
            $stmtSrc = $this->pdo->prepare("SELECT * FROM zoho_purchase_orders WHERE id = ?");
            $stmtSrc->execute([$sourceZohoId]);
            $sourceBackup = $stmtSrc->fetch(\PDO::FETCH_ASSOC);

            $mergeLogId = $this->logMerge('purchase_order', [
                'source_zoho_id' => $sourceZohoId,
                'target_zoho_id' => $targetZohoId,
                'backup_data' => $sourceBackup ?: []
            ]);

            $zoho = $this->zoho();
            $affectedRecords = [];

            $zoho->request('DELETE', "/Purchase_Orders?ids=$sourceZohoId");
            $affectedRecords[] = ['action' => 'zoho_delete', 'zoho_id' => $sourceZohoId, 'status' => 'ok'];

            $this->pdo->beginTransaction();
            $this->pdo->prepare("DELETE FROM zoho_purchase_orders WHERE id = ?")->execute([$sourceZohoId]);
            $affectedRecords[] = ['action' => 'db_delete', 'table' => 'zoho_purchase_orders'];

            $updateStmt = $this->pdo->prepare("UPDATE parasut_purchase_orders SET zoho_po_id = ? WHERE zoho_po_id = ?");
            $updateStmt->execute([$targetZohoId, $sourceZohoId]);
            if ($updateStmt->rowCount() > 0) {
                $affectedRecords[] = ['action' => 'db_update', 'table' => 'parasut_purchase_orders', 'rows' => $updateStmt->rowCount()];
            }
            $this->pdo->commit();

            if ($mergeLogId)
                $this->updateMergeLog($mergeLogId, 'completed', $affectedRecords);

            jsonResponse(['success' => true, 'message' => "PO birleştirme tamamlandı.", 'affected' => $affectedRecords]);

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction())
                $this->pdo->rollBack();
            if ($mergeLogId ?? null)
                $this->updateMergeLog($mergeLogId, 'failed', null, $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==================== HELPER: MOVE PRODUCT REFERENCES ====================

    /**
     * Move all Zoho CRM references from old product to new master product.
     * Handles: Invoices, Purchase_Orders, Quotes, Sales_Orders
     * Uses Related Records API to discover references.
     */
    private function moveProductReferences($zoho, string $oldId, string $masterId): array
    {
        $log = [];
        $affected = [];
        $invoicesMoved = 0;
        $posMoved = 0;

        // Module config: [module API name, line items field, display name]
        $modules = [
            ['Invoices',        'Invoiced_Items',  'Fatura'],
            ['Purchase_Orders', 'Purchased_Items', 'PO'],
            ['Quotes',          'Quoted_Items',    'Teklif'],
            ['Sales_Orders',    'Ordered_Items',   'Satış Siparişi'],
        ];

        foreach ($modules as [$module, $lineItemField, $displayName]) {
            try {
                // Use COQL to find records that reference this product
                $query = "select id from $module where Product_Name = '$oldId' limit 200";
                $searchResult = $zoho->request('POST', '/coql', [
                    'select_query' => $query
                ]);

                $records = $searchResult['data'] ?? [];
                if (empty($records)) continue;

                writeLog("moveProductReferences: Found " . count($records) . " $module records for product $oldId");

                foreach ($records as $record) {
                    try {
                        // Get full record with line items
                        $full = $zoho->request('GET', "/$module/{$record['id']}");
                        $data = $full['data'][0] ?? null;
                        if (!$data) continue;

                        $lineItems = $data[$lineItemField] ?? [];
                        $updated = false;
                        foreach ($lineItems as &$item) {
                            if (isset($item['product']['id']) && (string)$item['product']['id'] === (string)$oldId) {
                                $item['product']['id'] = $masterId;
                                $updated = true;
                            }
                        }
                        unset($item);

                        if ($updated) {
                            $zoho->request('PUT', "/$module", [
                                'data' => [['id' => $record['id'], $lineItemField => $lineItems]]
                            ]);

                            if ($module === 'Invoices') $invoicesMoved++;
                            elseif ($module === 'Purchase_Orders') $posMoved++;

                            $affected[] = ['type' => strtolower($module), 'id' => $record['id'], 'action' => 'product_ref_moved'];
                            $log[] = "  ↪ $displayName {$record['id']}: ürün referansı master'a taşındı";
                            usleep(250000); // Rate limit
                        }
                    } catch (\Exception $recErr) {
                        $log[] = "  ⚠ $displayName {$record['id']} güncellenemedi: " . $recErr->getMessage();
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                // COQL may not support all modules — skip gracefully
                if (strpos($msg, '204') === false && strpos($msg, 'No Content') === false
                    && strpos($msg, 'INVALID_QUERY') === false && strpos($msg, 'no data') === false) {
                    $log[] = "  ⚠ $displayName tarama atlandı: " . $msg;
                }
            }
        }

        return ['invoices' => $invoicesMoved, 'pos' => $posMoved, 'affected' => $affected, 'log' => $log];
    }

    // ==================== FIX OLD MERGES ====================

    /**
     * Retroactively fix references for previously merged products.
     * Uses the product_redirects table to find old→new mappings.
     */
    public function fix_old_merges(): void
    {
        enableLongRunningMode(256, 300);

        $limit = $this->inputInt('limit', 10);

        try {
            // Check if product_redirects table exists
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'product_redirects'")->rowCount();
            if (!$tableCheck) {
                jsonResponse(['success' => true, 'message' => 'Henüz birleştirme kaydı yok.', 'data' => []]);
                return;
            }

            $stmt = $this->pdo->prepare("SELECT old_zoho_id, new_zoho_id, product_code FROM product_redirects ORDER BY created_at DESC LIMIT ?");
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $redirects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($redirects)) {
                jsonResponse(['success' => true, 'message' => 'Düzeltilecek eski birleştirme kaydı yok.', 'data' => []]);
                return;
            }

            $zoho = $this->zoho();
            $totalFixed = 0;
            $log = [];

            foreach ($redirects as $redirect) {
                $oldId = $redirect['old_zoho_id'];
                $masterId = $redirect['new_zoho_id'];
                $code = $redirect['product_code'] ?? '?';
                $log[] = "🔄 İşleniyor: $oldId → $masterId ($code)";

                // Check if old product still exists in Zoho
                try {
                    $zoho->request('GET', "/Products/$oldId");
                } catch (\Exception $e) {
                    $log[] = "  ℹ Eski ürün Zoho'da mevcut değil (zaten silinmiş), atlanıyor.";
                    continue;
                }

                $result = $this->moveProductReferences($zoho, $oldId, $masterId);
                $fixed = count($result['affected']);
                $totalFixed += $fixed;
                $log = array_merge($log, $result['log']);

                if ($fixed === 0) {
                    $log[] = "  ℹ Bu ürün için taşınacak referans bulunamadı.";
                }

                usleep(500000); // Rate limit between redirects
            }

            jsonResponse([
                'success' => true,
                'message' => "$totalFixed kayıt güncellendi (" . count($redirects) . " eski birleştirme kontrol edildi).",
                'log' => $log,
                'total_fixed' => $totalFixed
            ]);
        } catch (\Exception $e) {
            writeLog("Fix old merges ERROR: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==================== MERGE LOG ====================

    /**
     * Get merge history (safe — returns empty if table doesn't exist)
     */
    public function get_merge_log(): void
    {
        if (!$this->mergeLogExists()) {
            jsonResponse(['success' => true, 'data' => [], 'message' => 'merge_log tablosu henüz oluşturulmamış. Migration çalıştırın.']);
            return;
        }

        $limit = $this->inputInt('limit', 50);
        $entityType = $this->input('entity_type');

        $where = '';
        $params = [];
        if ($entityType) {
            $where = 'WHERE entity_type = ?';
            $params[] = $entityType;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM merge_log $where ORDER BY created_at DESC LIMIT $limit");
        $stmt->execute($params);
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($logs as &$log) {
            $log['affected_records'] = json_decode($log['affected_records'] ?? '[]', true);
            $log['backup_data'] = json_decode($log['backup_data'] ?? '{}', true);
        }

        jsonResponse(['success' => true, 'data' => $logs]);
    }
}
