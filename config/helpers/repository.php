<?php
// config/helpers/repository.php
// Database query functions for products and invoices

/**
 * Get Parasut products from MySQL database with filtering, sorting and pagination
 */
function getParasutProductsFromDB(PDO $pdo, string $brandFilter = '', bool $showArchived = false, int $page = 1, int $limit = 50, string $search = '', string $sortBy = 'name', string $sortOrder = 'ASC'): array
{
    if ($page < 1)
        $page = 1;
    $offset = ($page - 1) * $limit;

    $whereClause = "WHERE 1=1";
    $params = [];

    if (!empty($brandFilter)) {
        $whereClause .= " AND product_name LIKE :brand";
        $params[':brand'] = $brandFilter . '%';
    }

    if ($showArchived) {
        $whereClause .= " AND is_archived = 1";
    } else {
        $whereClause .= " AND is_archived = 0";
    }

    if (!empty($search)) {
        $whereClause .= " AND (product_code LIKE :search OR product_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $allowedSorts = ['code' => 'product_code', 'name' => 'product_name', 'price' => 'list_price', 'stock' => 'stock_quantity', 'invoice_count' => 'invoice_count'];
    $sortColumn = $allowedSorts[$sortBy] ?? 'product_name';
    $order = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

    $countSql = "SELECT COUNT(*) FROM parasut_products $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $sql = "SELECT parasut_id as id, product_code as code, product_name as name, 
            list_price as price, currency, is_archived as archived, stock_quantity as stock, invoice_count
            FROM parasut_products 
            $whereClause 
            ORDER BY $sortColumn $order 
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => [
            'total_count' => $total,
            'current_page' => $page,
            'per_page' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

/**
 * Get Zoho products from MySQL database with filtering, sorting and pagination
 */
function getZohoProductsFromDB(PDO $pdo, ?string $brandFilter = null, int $page = 1, int $limit = 50, string $search = '', string $sortBy = 'name', string $sortOrder = 'ASC'): array
{
    if ($brandFilter === null)
        $brandFilter = '';

    if ($page < 1)
        $page = 1;
    $offset = ($page - 1) * $limit;

    $whereClause = "WHERE 1=1";
    $params = [];

    if (!empty($brandFilter)) {
        $whereClause .= " AND product_name LIKE :brand";
        $params[':brand'] = $brandFilter . '%';
    }

    if (!empty($search)) {
        $whereClause .= " AND (product_code LIKE :search OR product_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $countSql = "SELECT COUNT(*) FROM zoho_products $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $allowedSorts = ['code' => 'product_code', 'name' => 'product_name', 'price' => 'unit_price'];
    $sortColumn = $allowedSorts[$sortBy] ?? 'product_name';
    $order = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';

    $sql = "SELECT zoho_id as id, product_code as code, product_name as name, 
            unit_price as price, currency
            FROM zoho_products
            $whereClause
            ORDER BY $sortColumn $order
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => [
            'total_count' => $total,
            'current_page' => $page,
            'per_page' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

/**
 * Get invoices from MySQL database with filtering and pagination
 */
function getInvoicesFromDB(PDO $pdo, int $page = 1, int $limit = 50, ?string $syncStatus = null, ?int $year = null, array $filterIds = []): array
{
    if ($page < 1)
        $page = 1;
    $offset = ($page - 1) * $limit;

    $whereConditions = [];
    $params = [];

    if (!empty($filterIds)) {
        $placeholders = implode(',', array_fill(0, count($filterIds), '?'));
        $whereConditions[] = "i.id IN ($placeholders)";
        foreach (array_map('intval', $filterIds) as $fid) {
            $params[] = $fid;
        }
    }

    $orderBy = "issue_date DESC";

    if ($year !== null && $year !== '') {
        $whereConditions[] = "YEAR(issue_date) = ?";
        $params[] = intval($year);
    }

    if ($syncStatus !== null) {
        if ($syncStatus === 'error') {
            $whereConditions[] = "synced_to_zoho = 2";
        } elseif ($syncStatus === 'true' || $syncStatus === true || $syncStatus === 1 || $syncStatus === '1') {
            $whereConditions[] = "(synced_to_zoho = 1 AND zoho_invoice_id IS NOT NULL)";
            $orderBy = "synced_at DESC";
        } else {
            $whereConditions[] = "(synced_to_zoho = 0 OR synced_to_zoho IS NULL)";
        }
    } else {
        $whereConditions[] = "(synced_to_zoho = 0 OR synced_to_zoho IS NULL)";
    }

    $whereClause = !empty($whereConditions) ? implode(' AND ', $whereConditions) : '1=1';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM parasut_invoices i WHERE $whereClause");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $sql = "SELECT parasut_id as id, invoice_number, issue_date as date, 
            net_total as total, gross_total, (net_total - gross_total) as total_vat, currency, description,
            zoho_invoice_id, synced_to_zoho, synced_at, sync_error, zoho_total, net_total,
            payment_status, remaining_payment
            FROM parasut_invoices i
            WHERE $whereClause
            ORDER BY $orderBy
            LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$limit, $offset]));

    return [
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'meta' => [
            'total_count' => $total,
            'current_page' => $page,
            'per_page' => $limit,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

/**
 * Get invoice line items from MySQL database
 */
function getInvoiceItemsFromDB(PDO $pdo, int $invoiceId): array
{
    $sql = "SELECT 
            COALESCE(pp.product_code, '-') as product_code, 
            ii.product_name, 
            ii.quantity, 
            ii.unit_price as price, 
            ii.net_total as total 
            FROM parasut_invoice_items ii
            LEFT JOIN parasut_products pp ON ii.parasut_product_id = pp.id
            WHERE ii.invoice_id = :invoiceId
            ORDER BY ii.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':invoiceId' => $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
