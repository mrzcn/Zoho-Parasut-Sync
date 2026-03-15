<?php
// controllers/ProductController.php

class ProductController extends BaseController
{
    // ==================== COMBINED / COMPARISON ====================

    public function get_combined_products(): void
    {
        try {
            $query = "SELECT 
                        p.product_code,
                        p.parasut_id as p_id,
                        p.product_name as p_name,
                        IFNULL(p.list_price, 0) as p_price,
                        IFNULL(p.buying_price, 0) as p_buy,
                        IFNULL(p.vat_rate, 0) as p_vat,
                        IFNULL(p.stock_quantity, 0) as p_stock,
                        p.currency as p_currency,
                        z.zoho_id as z_id,
                        z.product_name as z_name,
                        IFNULL(z.unit_price, 0) as z_price,
                        IFNULL(z.buying_price, 0) as z_buy,
                        IFNULL(z.vat_rate, 0) as z_vat,
                        IFNULL(z.stock_quantity, 0) as z_stock,
                        z.currency as z_currency
                      FROM parasut_products p
                      LEFT JOIN zoho_products z ON p.product_code = z.product_code AND p.product_code != ''
                      UNION ALL
                      SELECT 
                        z.product_code,
                        NULL as p_id, NULL as p_name, 0 as p_price, 0 as p_buy, 0 as p_vat, 0 as p_stock, NULL as p_currency,
                        z.zoho_id as z_id, z.product_name as z_name,
                        IFNULL(z.unit_price, 0) as z_price, IFNULL(z.buying_price, 0) as z_buy,
                        IFNULL(z.vat_rate, 0) as z_vat, IFNULL(z.stock_quantity, 0) as z_stock,
                        z.currency as z_currency
                      FROM zoho_products z
                      LEFT JOIN parasut_products p ON z.product_code = p.product_code AND z.product_code != ''
                      WHERE p.id IS NULL
                      ORDER BY product_code ASC";

            $stmt = $this->pdo->query($query);
            jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            writeLog("SQL Error in get_combined_products: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Veritabanı hatası oluştu'], 500);
        }
    }

    public function get_products_comparison(): void
    {
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 50);
        $search = $this->input('search', '');
        $statusFilter = $this->input('status', '');
        $sortField = $this->input('sort_field', 'product_code');
        $sortOrder = strtoupper($this->input('sort_order', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $query = "
            SELECT 
                p.product_code, p.product_name, p.parasut_id,
                p.list_price as parasut_price, p.currency as parasut_currency,
                p.is_archived as parasut_is_archived,
                z.zoho_id, z.unit_price as zoho_price, z.currency as zoho_currency,
                z.is_active as zoho_is_active,
                CASE 
                    WHEN z.product_code IS NULL THEN 'only_parasut'
                    WHEN ABS(COALESCE(p.list_price, 0) - COALESCE(z.unit_price, 0)) < 0.01 THEN 'matched'
                    ELSE 'price_diff'
                END as status
            FROM parasut_products p
            LEFT JOIN zoho_products z ON p.product_code = z.product_code AND p.product_code != ''
            UNION
            SELECT 
                z.product_code, z.product_name, NULL as parasut_id,
                NULL as parasut_price, NULL as parasut_currency, NULL as parasut_is_archived,
                z.zoho_id, z.unit_price as zoho_price, z.currency as zoho_currency,
                z.is_active as zoho_is_active, 'only_zoho' as status
            FROM zoho_products z
            LEFT JOIN parasut_products p ON z.product_code = p.product_code AND z.product_code != ''
            WHERE p.id IS NULL
        ";

        $where = [];
        $params = [];
        if (!empty($search)) {
            $where[] = "(product_code LIKE :search OR product_name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if (!empty($statusFilter)) {
            $where[] = "status = :status";
            $params[':status'] = $statusFilter;
        }

        $baseQuery = "SELECT * FROM ($query) as combined";
        $filteredQuery = !empty($where)
            ? "SELECT * FROM ($query) as combined WHERE " . implode(' AND ', $where)
            : $baseQuery;

        // Count
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM ($filteredQuery) as cnt");
        foreach ($params as $key => $val)
            $countStmt->bindValue($key, $val);
        $countStmt->execute();
        $totalRecords = $countStmt->fetchColumn();

        // Sort
        $validSortFields = ['product_code', 'product_name', 'zoho_price', 'parasut_price', 'status'];
        if (!in_array($sortField, $validSortFields))
            $sortField = 'product_code';
        $archivedSort = "CAST(COALESCE(parasut_is_archived, 0) AS UNSIGNED)";

        if ($sortField === 'product_code') {
            $filteredQuery .= " ORDER BY $archivedSort ASC, CASE WHEN product_code REGEXP '^[0-9]' THEN 1 ELSE 0 END ASC, product_code $sortOrder";
        } else {
            $filteredQuery .= " ORDER BY $archivedSort ASC, $sortField $sortOrder";
        }

        // Pagination
        $offset = ($page - 1) * $limit;
        $filteredQuery .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($filteredQuery);
        foreach ($params as $key => $val)
            $stmt->bindValue($key, $val);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats (search only, no status filter)
        $statsParams = [];
        $statsBaseQuery = $baseQuery;
        if (!empty($search)) {
            $statsBaseQuery = "SELECT * FROM ($baseQuery) sub WHERE (product_code LIKE :search OR product_name LIKE :search)";
            $statsParams[':search'] = '%' . $search . '%';
        }

        $statsStmt = $this->pdo->prepare("SELECT COUNT(*) as total,
            SUM(CASE WHEN status = 'matched' THEN 1 ELSE 0 END) as matched,
            SUM(CASE WHEN status = 'price_diff' THEN 1 ELSE 0 END) as price_diff,
            SUM(CASE WHEN status = 'only_zoho' THEN 1 ELSE 0 END) as only_zoho,
            SUM(CASE WHEN status = 'only_parasut' THEN 1 ELSE 0 END) as only_parasut
            FROM ($statsBaseQuery) as stats");
        foreach ($statsParams as $key => $val)
            $statsStmt->bindValue($key, $val);
        $statsStmt->execute();
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        jsonResponse([
            'success' => true,
            'data' => $products,
            'stats' => $stats,
            'meta' => [
                'current_page' => $page,
                'total_pages' => ceil($totalRecords / $limit),
                'total_count' => $totalRecords,
                'limit' => $limit
            ]
        ]);
    }

    public function get_code_mismatches(): void
    {
        try {
            $query = "SELECT p.product_name, p.product_code AS parasut_code, z.product_code AS zoho_code, z.zoho_id 
                      FROM parasut_products p 
                      JOIN zoho_products z ON p.product_name = z.product_name 
                      WHERE p.product_code <> z.product_code AND p.product_code IS NOT NULL AND p.product_code != ''
                      ORDER BY p.product_name ASC";
            $stmt = $this->pdo->query($query);
            jsonResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    // ==================== SYNC ====================

    public function sync_all_products(): void
    {
        enableLongRunningMode();
        $pCount = $this->parasut()->syncProducts();
        $zCount = $this->zoho()->syncProducts();
        jsonResponse(['success' => true, 'message' => "Senkronizasyon tamamlandı. Paraşüt: $pCount, Zoho: $zCount ürün güncellendi."]);
    }

    public function fetch_parasut_products(): void
    {
        enableLongRunningMode();
        $upsertCount = $this->parasut()->syncProducts();

        $showArchived = filter_var($this->input('show_archived', 'false'), FILTER_VALIDATE_BOOLEAN);
        $search = $this->input('search', '');
        $page = $this->inputInt('page', 1);

        $productsData = getParasutProductsFromDB($this->pdo, '', $showArchived, $page, 50, $search);
        jsonResponse(['success' => true, 'data' => $productsData['data'], 'meta' => $productsData['meta'], 'upserted' => $upsertCount]);
    }

    public function fetch_zoho_products(): void
    {
        enableLongRunningMode();
        $upsertCount = $this->zoho()->syncProducts();
        writeLog("Zoho products sync completed. Total upserted: $upsertCount");

        $page = $this->inputInt('page', 1);
        $productsData = getZohoProductsFromDB($this->pdo, '', $page, 50);
        jsonResponse(['success' => true, 'data' => $productsData['data'], 'meta' => $productsData['meta'], 'upserted' => $upsertCount]);
    }

    public function sync_single_product_to_zoho(): void
    {
        $productId = $this->input('product_id');

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM parasut_products WHERE parasut_id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                $stmtFallback = $this->pdo->prepare("SELECT * FROM parasut_invoice_items WHERE parasut_product_id = ? ORDER BY id DESC LIMIT 1");
                $stmtFallback->execute([$productId]);
                $product = $stmtFallback->fetch(PDO::FETCH_ASSOC);

                if (!$product)
                    throw new Exception("Ürün veritabanında bulunamadı (ID: $productId)");
                $product['product_code'] = "TR-" . $product['parasut_product_id'];
                $product['list_price'] = $product['unit_price'];
            }

            $productName = $product['product_name'];
            $code = !empty($product['product_code']) ? $product['product_code'] : ("TR-" . $productId);
            $price = $product['list_price'] ?? 0;
            $vatRate = $product['vat_rate'] ?? 0;

            // Check local DB
            $stmtLocal = $this->pdo->prepare("SELECT zoho_id FROM zoho_products WHERE product_code = ? OR product_name = ?");
            $stmtLocal->execute([$code, $productName]);
            if ($stmtLocal->fetch()) {
                jsonResponse(['success' => true, 'message' => 'Ürün zaten Zoho\'da mevcut (Yerel Kayıt).']);
                return;
            }

            // Check Zoho remote
            $existing = $this->zoho()->searchProduct($code);
            if (!$existing)
                $existing = $this->zoho()->searchProductByName($productName);

            if ($existing) {
                try {
                    $this->pdo->prepare("INSERT IGNORE INTO zoho_products (zoho_id, product_name, product_code) VALUES (?, ?, ?)")
                        ->execute([$existing['id'], $existing['Product_Name'], $existing['Product_Code']]);
                } catch (Throwable $e) {
                }
                jsonResponse(['success' => true, 'message' => 'Ürün zaten Zoho\'da mevcut: ' . $existing['Product_Name']]);
                return;
            }

            $res = $this->zoho()->createProduct(['name' => $productName, 'code' => $code, 'price' => $price, 'vat_rate' => $vatRate]);
            jsonResponse(['success' => true, 'message' => 'Ürün Zoho\'ya eklendi.', 'details' => $res]);
        } catch (Throwable $e) {
            writeLog("Product Sync Error: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Hata: ' . $e->getMessage()], 500);
        }
    }

    public function sync_all_missing_products(): void
    {
        enableLongRunningMode();

        $syncedCount = 0;
        $skippedCount = 0;
        $checkedCount = 0;
        $errors = [];
        $details = [];

        try {
            $stmt = $this->pdo->query("SELECT parasut_product_id, product_name, vat_rate, unit_price 
                FROM parasut_invoice_items GROUP BY parasut_product_id ORDER BY MAX(created_at) DESC");
            $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalProducts = count($allProducts);

            foreach ($allProducts as $p) {
                $checkedCount++;
                $code = "TR-" . $p['parasut_product_id'];
                $productName = $p['product_name'];

                $existing = $this->zoho()->searchProduct($code);
                if (!$existing && !empty($productName))
                    $existing = $this->zoho()->searchProduct($productName);

                if (!$existing) {
                    try {
                        $this->zoho()->createProduct(['name' => $productName, 'code' => $code, 'price' => $p['unit_price'], 'vat_rate' => $p['vat_rate']]);
                        $syncedCount++;
                        $details[] = ['name' => $productName, 'code' => $code, 'action' => 'created'];
                        usleep(200000);
                    } catch (Exception $e) {
                        $errors[] = "$productName: " . $e->getMessage();
                        $details[] = ['name' => $productName, 'code' => $code, 'action' => 'error', 'error' => $e->getMessage()];
                    }
                } else {
                    $skippedCount++;
                    $details[] = ['name' => $productName, 'code' => $code, 'action' => 'skipped', 'zoho_id' => $existing['id']];
                }
            }

            jsonResponse([
                'success' => true,
                'total_count' => $totalProducts,
                'checked_count' => $checkedCount,
                'synced_count' => $syncedCount,
                'skipped_count' => $skippedCount,
                'error_count' => count($errors),
                'errors' => $errors,
                'details' => $details
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ==================== GET (DB ONLY) ====================

    public function get_parasut_products(): void
    {
        $showArchived = filter_var($this->input('show_archived', 'false'), FILTER_VALIDATE_BOOLEAN);
        $search = $this->input('search', '');
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 50);
        $sortBy = $this->input('sort_by', 'name');
        $sortOrder = $this->input('sort_order', 'ASC');

        $productsData = getParasutProductsFromDB($this->pdo, '', $showArchived, $page, $limit, $search, $sortBy, $sortOrder);
        jsonResponse(['success' => true, 'data' => $productsData['data'], 'meta' => $productsData['meta']]);
    }

    public function get_zoho_products(): void
    {
        $page = $this->inputInt('page', 1);
        $limit = $this->inputInt('limit', 50);
        $productsData = getZohoProductsFromDB($this->pdo, '', $page, $limit);
        jsonResponse(['success' => true, 'data' => $productsData['data'], 'meta' => $productsData['meta']]);
    }

    public function get_zoho_products_db(): void
    {
        $search = $this->input('search', '');
        $page = $this->inputInt('page', 1);
        $sortBy = $this->input('sort_by', 'name');
        $sortOrder = $this->input('sort_order', 'ASC');

        $productsData = getZohoProductsFromDB($this->pdo, '', $page, 50, $search, $sortBy, $sortOrder);

        $stmtMax = $this->pdo->query("SELECT MAX(updated_at) FROM zoho_products");
        $productsData['meta']['latest_updated_at'] = $stmtMax->fetchColumn();

        jsonResponse(['success' => true, 'data' => $productsData['data'], 'meta' => $productsData['meta']]);
    }

    public function get_parasut_products_list(): void
    {
        $page = $this->inputInt('page', 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $search = $this->input('search', '');

        $where = "";
        $params = [];
        if (!empty($search)) {
            $where = "WHERE product_name LIKE ? OR product_code LIKE ?";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM parasut_products $where");
        $countStmt->execute($params);
        $totalItems = $countStmt->fetchColumn();

        $sql = "SELECT parasut_id as parasut_product_id, product_name, vat_rate, list_price as unit_price, product_code as code
                FROM parasut_products $where ORDER BY product_name ASC LIMIT $limit OFFSET $offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        jsonResponse([
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => ['total_pages' => ceil($totalItems / $limit), 'current_page' => $page, 'total_count' => $totalItems]
        ]);
    }

    public function get_sync_queue(): void
    {
        try {
            $stmt = $this->pdo->query("SELECT parasut_id as parasut_product_id, product_name, vat_rate, list_price as unit_price 
                FROM parasut_products ORDER BY product_name ASC");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['success' => true, 'data' => $products, 'total' => count($products)]);
        } catch (Throwable $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ==================== UPDATE ====================

    public function update_product_unified(): void
    {
        $pId = $this->input('parasut_id');
        $zId = $this->input('zoho_id');
        $code = $this->input('product_code', '');
        $name = $this->input('name', '');
        $price = (float) $this->input('price', 0);
        $buyPrice = (float) $this->input('buying_price', 0);
        $vatRate = (float) $this->input('vat_rate', 0);
        $stock = isset($_POST['stock_quantity']) ? (float) $_POST['stock_quantity'] : null;
        $currency = $this->input('currency', 'TRY');

        if (empty($name))
            jsonResponse(['success' => false, 'message' => 'Ürün adı boş olamaz.'], 400);

        $results = [];
        $errors = [];

        if ($pId) {
            try {
                $pAttr = ['name' => $name, 'code' => $code, 'list_price' => $price, 'buying_price' => $buyPrice, 'vat_rate' => $vatRate, 'currency' => $currency];
                $res = $this->parasut()->updateProduct($pId, $pAttr);
                if (isset($res['data']['id'])) {
                    $this->pdo->prepare("UPDATE parasut_products SET product_name = ?, product_code = ?, list_price = ?, buying_price = ?, vat_rate = ?, currency = ? WHERE parasut_id = ?")
                        ->execute([$name, $code, $price, $buyPrice, $vatRate, $currency, $pId]);
                    if ($stock !== null) {
                        $this->parasut()->updateStock($pId, $stock);
                        $this->pdo->prepare("UPDATE parasut_products SET stock_quantity = ? WHERE parasut_id = ?")->execute([$stock, $pId]);
                    }
                    $results[] = "Paraşüt güncellendi.";
                } else {
                    $errors[] = "Paraşüt API hatası.";
                }
            } catch (Exception $e) {
                $errors[] = "Paraşüt Hatası: " . $e->getMessage();
            }
        }

        if ($zId) {
            try {
                $zData = ['Product_Name' => $name, 'Product_Code' => $code, 'Unit_Price' => $price, 'Purchase_Cost' => $buyPrice];
                if ($stock !== null)
                    $zData['Qty_in_Stock'] = $stock;
                $res = $this->zoho()->updateProduct($zId, $zData);

                $isSuccess = isset($res['data'][0]['status']) && ($res['data'][0]['status'] === 'success' || $res['data'][0]['code'] === 'SUCCESS');
                if ($isSuccess) {
                    $this->pdo->prepare("UPDATE zoho_products SET product_name = ?, product_code = ?, unit_price = ?, buying_price = ?, stock_quantity = ? WHERE zoho_id = ?")
                        ->execute([$name, $code, $price, $buyPrice, $stock, $zId]);
                    $results[] = "Zoho güncellendi.";
                } else {
                    $errors[] = "Zoho API hatası: " . ($res['data'][0]['message'] ?? 'Bilinmeyen hata');
                }
            } catch (Exception $e) {
                $errors[] = "Zoho Hatası: " . $e->getMessage();
            }
        }

        if (empty($errors)) {
            jsonResponse(['success' => true, 'message' => implode(" ", $results)]);
        } else {
            jsonResponse(['success' => false, 'message' => implode(" | ", $errors), 'success_parts' => $results]);
        }
    }

    public function update_parasut_product(): void
    {
        $id = $this->input('id');
        $code = $this->input('code');

        if (!$id || $code === null)
            jsonResponse(['success' => false, 'message' => 'Eksik parametre (id veya code).'], 400);

        try {
            $result = $this->parasut()->updateProduct($id, ['code' => $code]);
            if (isset($result['data']['id'])) {
                $stmt = $this->pdo->prepare("UPDATE parasut_products SET product_code = ?, updated_at = NOW() WHERE parasut_id = ?");
                $stmt->execute([$code, $id]);
                writeLog("Product Update: Code updated to '$code' for ID $id. Rows: " . $stmt->rowCount());
                jsonResponse(['success' => true, 'message' => 'Ürün güncellendi.', 'data' => $result['data']]);
            } else {
                writeLog("Product Update Error: " . json_encode($result));
                jsonResponse(['success' => false, 'message' => 'Paraşüt güncelleme başarısız.', 'details' => $result]);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @deprecated Use update_parasut_product() instead
     */
    public function update_parasut_product_lite(): void
    {
        // Backward compatible: map parameter names and delegate
        if (!isset($_POST['id']) && isset($_POST['parasut_id'])) {
            $_POST['id'] = $_POST['parasut_id'];
        }
        if (!isset($_POST['code']) && isset($_POST['product_code'])) {
            $_POST['code'] = $_POST['product_code'];
        }
        $this->update_parasut_product();
    }

    public function update_zoho_product(): void
    {
        $id = $this->input('id');
        $code = $this->input('code');

        if (!$id || $code === null)
            jsonResponse(['success' => false, 'message' => 'Eksik parametre.'], 400);

        try {
            $result = $this->zoho()->updateProduct($id, ['Product_Code' => $code]);

            $isSuccess = (isset($result['data'][0]['status']) && $result['data'][0]['status'] === 'success')
                || (isset($result['data'][0]['code']) && $result['data'][0]['code'] === 'SUCCESS');

            if ($isSuccess) {
                $this->pdo->prepare("UPDATE zoho_products SET product_code = ?, updated_at = NOW() WHERE zoho_id = ?")->execute([$code, $id]);
                jsonResponse(['success' => true, 'message' => 'Zoho ürünü güncellendi.', 'data' => $result]);
            } else {
                $msg = $result['data'][0]['message'] ?? 'Bilinmeyen Zoho hatası';
                writeLog("Zoho Update Failed: " . json_encode($result));
                jsonResponse(['success' => false, 'message' => 'Zoho güncelleme başarısız: ' . $msg], 400);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @deprecated Use update_zoho_product() instead
     */
    public function update_zoho_product_lite(): void
    {
        // Backward compatible: map parameter names and delegate
        if (!isset($_POST['id']) && isset($_POST['zoho_id'])) {
            $_POST['id'] = $_POST['zoho_id'];
        }
        if (!isset($_POST['code']) && isset($_POST['product_code'])) {
            $_POST['code'] = $_POST['product_code'];
        }
        $this->update_zoho_product();
    }

    /**
     * @deprecated Use update_product_unified() instead
     */
    public function update_product_in_both_systems(): void
    {
        // Map parameter names for backward compatibility
        $mappings = [
            'product_code' => 'product_code',
            'product_name' => 'name',
            'sale_price'   => 'price',
            'purchase_price' => 'buying_price',
            'tax_rate'     => 'vat_rate',
            'stock_quantity' => 'stock_quantity',
        ];
        foreach ($mappings as $old => $new) {
            if (isset($_POST[$old]) && !isset($_POST[$new])) {
                $_POST[$new] = $_POST[$old];
            }
        }
        $this->update_product_unified();
    }

    public function update_price_in_zoho(): void
    {
        $zohoId = $this->input('zoho_id');
        $newPrice = $this->input('new_price');

        if (!$zohoId || $newPrice === null)
            jsonResponse(['success' => false, 'message' => 'Zoho ID ve fiyat gerekli.'], 400);

        try {
            $result = $this->zoho()->updateProduct($zohoId, ['Unit_Price' => (float) $newPrice]);
            $isSuccess = isset($result['data'][0]['status']) && ($result['data'][0]['status'] === 'success' || $result['data'][0]['code'] === 'SUCCESS');

            if ($isSuccess) {
                $this->pdo->prepare("UPDATE zoho_products SET unit_price = ?, updated_at = NOW() WHERE zoho_id = ?")->execute([$newPrice, $zohoId]);
                jsonResponse(['success' => true, 'message' => 'Fiyat Zoho\'da başarıyla güncellendi!']);
            } else {
                jsonResponse(['success' => false, 'message' => 'Zoho hatası: ' . ($result['data'][0]['message'] ?? 'Bilinmeyen hata')], 400);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Sistem hatası: ' . $e->getMessage()], 500);
        }
    }

    public function sync_price(): void
    {
        $target = $this->input('target', '');
        $id = $this->input('id', '');
        $price = $this->input('price', '');

        if (empty($target) || empty($id) || $price === '')
            jsonResponse(['success' => false, 'message' => 'Eksik parametre.'], 400);
        if (!in_array($target, ['parasut', 'zoho']))
            jsonResponse(['success' => false, 'message' => 'Geçersiz hedef sistem.'], 400);

        if ($target === 'parasut') {
            $this->parasut()->updateProduct($id, ['list_price' => $price]);
        } else {
            $this->zoho()->updateProduct($id, ['Unit_Price' => (float) $price]);
        }
        jsonResponse(['success' => true, 'message' => 'Fiyat güncellendi.']);
    }

    // ==================== CHECK / SEARCH ====================

    public function check_product_from_parasut(): void
    {
        $query = $this->input('query', '');
        if (empty($query))
            jsonResponse(['success' => false, 'message' => 'Lütfen bir arama terimi girin.']);

        try {
            $results = $this->parasut()->searchProducts($query);
            if (empty($results))
                jsonResponse(['success' => false, 'message' => 'Paraşüt tarafında eşleşen ürün bulunamadı.']);
            jsonResponse(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
    }

    public function check_product_from_zoho(): void
    {
        $query = $this->input('query', '');
        if (empty($query))
            jsonResponse(['success' => false, 'message' => 'Lütfen bir arama terimi girin.']);

        try {
            $results = $this->zoho()->searchProducts($query);
            if (empty($results))
                jsonResponse(['success' => false, 'message' => 'Zoho tarafında eşleşen ürün bulunamadı.']);
            jsonResponse(['success' => true, 'data' => $results]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
    }

    // ==================== CREATE ====================

    public function create_zoho_product(): void
    {
        $name = $this->input('name', '');
        $code = $this->input('code', '');
        $price = $this->input('price', 0);
        $vat_rate = $this->input('vat_rate', 18);

        if (!$name)
            jsonResponse(['success' => false, 'message' => 'Ürün adı zorunludur.'], 400);

        $result = $this->zoho()->createProduct(['name' => $name, 'code' => $code, 'price' => $price, 'vat_rate' => $vat_rate]);

        if (isset($result['data'][0]['status']) && $result['data'][0]['status'] === 'success') {
            $zohoId = $result['data'][0]['details']['id'];
            try {
                $this->pdo->prepare("INSERT INTO zoho_products (zoho_id, product_code, product_name, unit_price, created_at, updated_at)
                    VALUES (:zoho_id, :code, :name, :price, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), unit_price = VALUES(unit_price), updated_at = NOW()")
                    ->execute([':zoho_id' => $zohoId, ':code' => $code, ':name' => $name, ':price' => $price]);
            } catch (Exception $e) {
                writeLog("Warning: Could not insert into zoho_products: " . $e->getMessage());
            }
            jsonResponse(['success' => true, 'message' => 'Ürün Zoho\'ya başarıyla eklendi.', 'zoho_id' => $zohoId]);
        } else {
            $err = $result['data'][0]['message'] ?? 'Bilinmeyen hata';
            jsonResponse(['success' => false, 'message' => 'Zoho Yanıtı: ' . $err], 400);
        }
    }

    public function create_product_in_zoho_from_parasut(): void
    {
        $parasutId = $this->input('parasut_id');
        if (!$parasutId)
            jsonResponse(['success' => false, 'message' => 'Parasut ID gerekli.'], 400);

        try {
            $stmt = $this->pdo->prepare("SELECT product_code, product_name, list_price, vat_rate FROM parasut_products WHERE parasut_id = ?");
            $stmt->execute([$parasutId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product)
                jsonResponse(['success' => false, 'message' => 'Ürün Paraşüt veritabanında bulunamadı.'], 404);

            $result = $this->zoho()->createProduct(['name' => $product['product_name'], 'code' => $product['product_code'], 'price' => $product['list_price'], 'vat_rate' => $product['vat_rate'] ?? 18]);

            if (isset($result['data'][0]['status']) && $result['data'][0]['status'] === 'success') {
                $zohoId = $result['data'][0]['details']['id'];
                $this->pdo->prepare("INSERT INTO zoho_products (zoho_id, product_code, product_name, unit_price, created_at, updated_at) VALUES (:zoho_id, :code, :name, :price, NOW(), NOW()) ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), unit_price = VALUES(unit_price), updated_at = NOW()")
                    ->execute([':zoho_id' => $zohoId, ':code' => $product['product_code'], ':name' => $product['product_name'], ':price' => $product['list_price']]);
                jsonResponse(['success' => true, 'message' => 'Ürün Zoho\'da başarıyla oluşturuldu!', 'zoho_id' => $zohoId]);
            } else {
                jsonResponse(['success' => false, 'message' => 'Zoho hatası: ' . ($result['data'][0]['message'] ?? 'Bilinmeyen hata')], 400);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Sistem hatası: ' . $e->getMessage()], 500);
        }
    }

    public function create_product_in_parasut_from_zoho(): void
    {
        $zohoId = $this->input('zoho_id');
        if (!$zohoId)
            jsonResponse(['success' => false, 'message' => 'Zoho ID gerekli.'], 400);

        try {
            $stmt = $this->pdo->prepare("SELECT product_code, product_name, unit_price FROM zoho_products WHERE zoho_id = ?");
            $stmt->execute([$zohoId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product)
                jsonResponse(['success' => false, 'message' => 'Ürün Zoho veritabanında bulunamadı.'], 404);

            $productData = [
                'data' => [
                    'type' => 'products',
                    'attributes' => [
                        'code' => $product['product_code'],
                        'name' => $product['product_name'],
                        'list_price' => $product['unit_price'],
                        'currency' => 'TRY',
                        'vat_rate' => 18
                    ]
                ]
            ];

            $result = $this->parasut()->request('POST', '/' . getSetting($this->pdo, 'parasut_company_id') . '/products', $productData);

            if (isset($result['data']['id'])) {
                $parasutId = $result['data']['id'];
                $this->pdo->prepare("INSERT INTO parasut_products (parasut_id, product_code, product_name, list_price, currency, created_at, updated_at)
                    VALUES (:parasut_id, :code, :name, :price, 'TRY', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), list_price = VALUES(list_price), updated_at = NOW()")
                    ->execute([':parasut_id' => $parasutId, ':code' => $product['product_code'], ':name' => $product['product_name'], ':price' => $product['unit_price']]);
                jsonResponse(['success' => true, 'message' => 'Ürün Paraşüt\'te başarıyla oluşturuldu!', 'parasut_id' => $parasutId]);
            } else {
                jsonResponse(['success' => false, 'message' => 'Paraşüt hatası: ' . ($result['errors'][0]['detail'] ?? 'Bilinmeyen hata')], 400);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Sistem hatası: ' . $e->getMessage()], 500);
        }
    }

    // ==================== DELETE ====================

    public function zoho_delete_batch(): void
    {
        try {
            $ids = $this->zoho()->getInvoiceIds(50);
            if (empty($ids))
                jsonResponse(['success' => true, 'finished' => true]);

            $result = $this->zoho()->massDeleteInvoices($ids);
            if ($result['success']) {
                jsonResponse(['success' => true, 'finished' => false, 'count' => $result['count']]);
            } else {
                jsonResponse(['success' => false, 'message' => $result['error'] ?? 'Delete failed']);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    public function zoho_delete_products_batch(): void
    {
        try {
            $ids = $this->zoho()->getProductIds(50);
            if (empty($ids))
                jsonResponse(['success' => true, 'finished' => true]);

            $result = $this->zoho()->massDeleteProducts($ids);
            if ($result['success']) {
                $response = ['success' => true, 'finished' => false, 'count' => $result['count'] ?? 0];
                if (isset($result['partial_errors']))
                    $response['partial_errors'] = $result['partial_errors'];
                jsonResponse($response);
            } else {
                jsonResponse(['success' => false, 'message' => $result['error'] ?? 'Delete failed']);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
    }


    // ==================== BULK ====================

    public function bulk_update_product_codes(): void
    {
        enableLongRunningMode(256);

        $testMode = isset($_POST['test_mode']) && $_POST['test_mode'] === true;
        $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === true;
        $limit = $this->inputInt('limit', 0);

        try {
            // Bridge to legacy script — $_GET is used because update_product_codes.php reads from it
            // TODO: Refactor when update_product_codes.php is modernized
            $_GET['json'] = 'true';
            $_GET['test_mode'] = $testMode ? 'true' : 'false';
            $_GET['dry_run'] = $dryRun ? 'true' : 'false';
            if ($limit > 0) {
                $_GET['limit'] = (string) $limit;
            }

            ob_start();
            include __DIR__ . '/../update_product_codes.php';
            $output = ob_get_clean();

            $result = json_decode($output, true);
            if ($result && isset($result['success'])) {
                jsonResponse($result);
            } else {
                jsonResponse(['success' => false, 'message' => 'Script output is not valid JSON', 'raw_output' => substr($output, 0, 500)]);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Hata: ' . $e->getMessage()], 500);
        }
    }
    // ==================== MERGE TOOL ====================

    /**
     * Get all Zoho products grouped by Product_Code, with duplicate detection
     * Returns all products + duplicate groups with invoice/PO counts
     */
    public function get_merge_candidates(): void
    {
        enableLongRunningMode();

        try {
            // Fetch ALL products from Zoho
            $allProducts = $this->zoho()->getAllProducts();

            // Group by Product_Code
            $codeGroups = [];
            $noCodeProducts = [];

            foreach ($allProducts as $product) {
                $code = trim($product['Product_Code'] ?? '');
                if (empty($code)) {
                    $noCodeProducts[] = [
                        'id' => $product['id'],
                        'name' => $product['Product_Name'] ?? '',
                        'code' => '',
                        'price' => $product['Unit_Price'] ?? 0,
                        'created' => $product['Created_Time'] ?? '',
                        'active' => $product['Product_Active'] ?? true,
                    ];
                    continue;
                }

                $codeGroups[$code][] = [
                    'id' => $product['id'],
                    'name' => $product['Product_Name'] ?? '',
                    'code' => $code,
                    'price' => $product['Unit_Price'] ?? 0,
                    'created' => $product['Created_Time'] ?? '',
                    'active' => $product['Product_Active'] ?? true,
                ];
            }

            // Separate unique vs duplicates
            $duplicateGroups = [];
            $uniqueProducts = [];

            foreach ($codeGroups as $code => $products) {
                if (count($products) > 1) {
                    $duplicateGroups[$code] = $products;
                } else {
                    $uniqueProducts[] = $products[0];
                }
            }

            // For each duplicate group, find invoice/PO counts per product
            // Pre-fetch: count how many invoices reference each zoho product
            $invoiceCounts = [];
            $poCounts = [];
            try {
                $invStmt = $this->pdo->query("
                    SELECT zoho_invoice_id as zoho_id, COUNT(*) as cnt
                    FROM parasut_invoices
                    WHERE zoho_invoice_id IS NOT NULL AND zoho_invoice_id != ''
                    GROUP BY zoho_invoice_id
                ");
                while ($row = $invStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $invoiceCounts[$row['zoho_id']] = (int) $row['cnt'];
                }
            } catch (\Exception $e) { /* table may not have the column */
            }

            try {
                $poStmt = $this->pdo->query("
                    SELECT zoho_po_id as zoho_id, COUNT(*) as cnt
                    FROM parasut_purchase_orders
                    WHERE zoho_po_id IS NOT NULL AND zoho_po_id != ''
                    GROUP BY zoho_po_id
                ");
                while ($row = $poStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $poCounts[$row['zoho_id']] = (int) $row['cnt'];
                }
            } catch (\Exception $e) { /* table may not have the column */
            }

            $enrichedGroups = [];
            foreach ($duplicateGroups as $code => $products) {
                $enriched = [];
                foreach ($products as $product) {
                    $product['invoice_count'] = $invoiceCounts[$product['id']] ?? 0;
                    $product['po_count'] = $poCounts[$product['id']] ?? 0;
                    $enriched[] = $product;
                }

                // Sort: most references first, then oldest
                usort($enriched, function ($a, $b) {
                    $refDiff = ($b['invoice_count'] + $b['po_count']) - ($a['invoice_count'] + $a['po_count']);
                    if ($refDiff !== 0)
                        return $refDiff;
                    return strcmp($a['created'], $b['created']);
                });

                $enrichedGroups[] = [
                    'code' => $code,
                    'products' => $enriched,
                    'suggested_master' => $enriched[0]['id'],
                ];
            }

            // Find Paraşüt match for each code
            $parasutMap = [];
            $stmt = $this->pdo->query("SELECT parasut_id, product_code, product_name FROM parasut_products WHERE product_code IS NOT NULL AND product_code != ''");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $parasutMap[$row['product_code']] = [
                    'parasut_id' => $row['parasut_id'],
                    'name' => $row['product_name'],
                ];
            }

            // Attach Paraşüt info to groups
            foreach ($enrichedGroups as &$group) {
                $group['parasut'] = $parasutMap[$group['code']] ?? null;
            }
            unset($group);

            jsonResponse([
                'success' => true,
                'data' => [
                    'duplicate_groups' => $enrichedGroups,
                    'unique_count' => count($uniqueProducts),
                    'no_code_count' => count($noCodeProducts),
                    'total_products' => count($allProducts),
                    'duplicate_group_count' => count($enrichedGroups),
                ]
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Hata: ' . $e->getMessage()], 500);
        }
    }

    // merge_products() — REMOVED: Moved to MergeController (single source of truth)
    // Route: 'merge_products' → MergeController::merge_products()

    /**
     * Update product name in both Zoho and Paraşüt
     * POST params: zoho_id, parasut_id (optional), new_name
     */
    public function update_product_name(): void
    {
        $zohoId = $this->requireInput('zoho_id');
        $newName = $this->requireInput('new_name');
        $parasutId = $this->input('parasut_id', '');

        try {
            $results = [];
            $errors = [];

            // Update Zoho
            try {
                $res = $this->zoho()->updateProduct($zohoId, ['Product_Name' => $newName]);
                $isSuccess = isset($res['data'][0]['status']) && ($res['data'][0]['status'] === 'success' || $res['data'][0]['code'] === 'SUCCESS');
                if ($isSuccess) {
                    $this->pdo->prepare("UPDATE zoho_products SET product_name = ? WHERE zoho_id = ?")->execute([$newName, $zohoId]);
                    $results[] = 'Zoho güncellendi';
                } else {
                    $errors[] = 'Zoho: ' . ($res['data'][0]['message'] ?? 'Bilinmeyen hata');
                }
            } catch (Exception $e) {
                $errors[] = 'Zoho hatası: ' . $e->getMessage();
            }

            // Update Paraşüt if ID provided
            if (!empty($parasutId)) {
                try {
                    $res = $this->parasut()->updateProduct($parasutId, ['name' => $newName]);
                    if ($res) {
                        $this->pdo->prepare("UPDATE parasut_products SET product_name = ? WHERE parasut_id = ?")->execute([$newName, $parasutId]);
                        $results[] = 'Paraşüt güncellendi';
                    } else {
                        $errors[] = 'Paraşüt güncellenemedi';
                    }
                } catch (Exception $e) {
                    $errors[] = 'Paraşüt hatası: ' . $e->getMessage();
                }
            }

            if (!empty($errors) && empty($results)) {
                jsonResponse(['success' => false, 'message' => implode(', ', $errors)], 400);
            } elseif (!empty($errors)) {
                jsonResponse(['success' => true, 'message' => implode(', ', $results) . ' (Uyarılar: ' . implode(', ', $errors) . ')']);
            } else {
                jsonResponse(['success' => true, 'message' => implode(', ', $results)]);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Sistem hatası: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Global search across all tables: invoices, products (both Parasut and Zoho)
     * Used by the header search bar
     */
    public function global_search(): void
    {
        $query = trim($this->input('query', ''));
        if (strlen($query) < 3) {
            jsonResponse(['success' => false, 'message' => 'En az 3 karakter giriniz.']);
            return;
        }

        $like = "%{$query}%";
        $limit = 5;

        try {
            // 1. Parasut Invoices
            $stmt = $this->pdo->prepare("
                SELECT parasut_id, invoice_number, description, net_total, currency, issue_date
                FROM parasut_invoices
                WHERE invoice_number LIKE ? OR description LIKE ?
                ORDER BY issue_date DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $parasutInvoices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 2. Parasut Invoice Items (product name in invoice line items)
            $stmt2 = $this->pdo->prepare("
                SELECT ii.product_name, ii.quantity, pi.invoice_number
                FROM parasut_invoice_items ii
                JOIN parasut_invoices pi ON ii.invoice_id = pi.id
                WHERE ii.product_name LIKE ?
                ORDER BY pi.issue_date DESC
                LIMIT ?
            ");
            $stmt2->bindValue(1, $like);
            $stmt2->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt2->execute();
            $parasutItems = $stmt2->fetchAll(\PDO::FETCH_ASSOC);

            // 3. Parasut Products
            $stmt3 = $this->pdo->prepare("
                SELECT parasut_id, product_code, product_name, list_price, currency
                FROM parasut_products
                WHERE product_name LIKE ? OR product_code LIKE ?
                ORDER BY product_name
                LIMIT ?
            ");
            $stmt3->bindValue(1, $like);
            $stmt3->bindValue(2, $like);
            $stmt3->bindValue(3, $limit, \PDO::PARAM_INT);
            $stmt3->execute();
            $parasutProducts = $stmt3->fetchAll(\PDO::FETCH_ASSOC);

            // 4. Zoho Invoices
            $zohoInvoices = [];
            try {
                $stmt4 = $this->pdo->prepare("
                    SELECT zoho_id as id, invoice_number, customer_name, total, currency, invoice_date
                    FROM zoho_invoices
                    WHERE invoice_number LIKE ? OR customer_name LIKE ?
                    ORDER BY invoice_date DESC
                    LIMIT ?
                ");
                $stmt4->bindValue(1, $like);
                $stmt4->bindValue(2, $like);
                $stmt4->bindValue(3, $limit, \PDO::PARAM_INT);
                $stmt4->execute();
                $zohoInvoices = $stmt4->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // zoho_invoices table may not exist
            }

            // 5. Zoho Products
            $stmt5 = $this->pdo->prepare("
                SELECT zoho_id as id, product_code, product_name, unit_price, currency
                FROM zoho_products
                WHERE product_name LIKE ? OR product_code LIKE ?
                ORDER BY product_name
                LIMIT ?
            ");
            $stmt5->bindValue(1, $like);
            $stmt5->bindValue(2, $like);
            $stmt5->bindValue(3, $limit, \PDO::PARAM_INT);
            $stmt5->execute();
            $zohoProducts = $stmt5->fetchAll(\PDO::FETCH_ASSOC);

            jsonResponse([
                'success' => true,
                'data' => [
                    'parasut_invoices' => $parasutInvoices,
                    'parasut_items' => $parasutItems,
                    'parasut_products' => $parasutProducts,
                    'zoho_invoices' => $zohoInvoices,
                    'zoho_products' => $zohoProducts,
                ]
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Arama hatası: ' . $e->getMessage()], 500);
        }
    }
}
