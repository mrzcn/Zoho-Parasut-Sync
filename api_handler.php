<?php
// api_handler.php
// Thin entry point — delegates all actions to controllers via Router

// Global fatal error handler — catches memory exhaustion, syntax errors etc.
// Returns JSON instead of hosting provider's generic HTML 500 page
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        $msg = basename($error['file']) . ':' . $error['line'] . ' - ' . $error['message'];
        echo json_encode(['success' => false, 'message' => 'PHP Fatal: ' . $msg]);
    }
});

// Increase memory for heavy operations (product listing, comparison etc.)
@ini_set('memory_limit', '128M');

require_once __DIR__ . '/bootstrap.php';

// CSRF Protection for state-changing requests
$action = $_POST['action'] ?? '';

// These read-only actions are exempted from CSRF (GET-equivalent semantics)
// They do NOT modify any state — only return data for live checking
$csrfExemptActions = [
    // Dashboard & system monitoring (read-only)
    'get_dashboard_stats',
    'get_queue_stats',
    'get_sync_health',
    // Logs (read-only)
    'get_logs',
    // Product checks (read-only)
    'check_product_from_parasut',
    'check_product_from_zoho',
    'get_merge_candidates',
    // Duplicate detection & merge log (read-only)
    'detect_duplicate_products',
    'detect_duplicate_invoices',
    'detect_duplicate_purchase_orders',
    'get_merge_log',
    // Data listing (read-only)
    'get_parasut_invoices',
    'get_parasut_products',
    'get_zoho_products',
    'get_zoho_products_db',
    'get_parasut_products_list',
    'get_parasut_invoices_list',
    'get_combined_products',
    'get_products_comparison',
    'get_code_mismatches',
    'get_sync_queue',
    'get_parasut_purchase_orders',
    'get_invoices_comparison',
    'get_invoice_comparison',
    'get_unsynced_invoices_ids',
    'get_unsynced_purchase_orders_ids',
    'get_invoice_sync_status',
    'get_status_update_candidates',
    'get_parasut_invoice_details',
    'get_parasut_purchase_order_details',
    'get_all_zoho_products',
    'get_api_metrics',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExemptActions, true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $sessionToken = $_SESSION['csrf_token'] ?? 'EMPTY_SESSION';
        $receivedToken = $csrfToken ?: 'EMPTY_INPUT';
        writeLog("CSRF FAIL [$action]. Session: $sessionToken | Received: $receivedToken | Method: {$_SERVER['REQUEST_METHOD']}");
        jsonResponse(['success' => false, 'message' => "Güvenlik Doğrulama Hatası (CSRF). Lütfen sayfayı yenileyip tekrar deneyin."], 403);
    }
}

// Rate Limiting
$heavyActions = [
    'sync_all_products',
    'fetch_parasut_products',
    'fetch_zoho_products',
    'fetch_parasut_invoices',
    'fetch_zoho_invoices',
    'merge_products',
    'sync_all_missing_products',
    'bulk_update_product_codes',
    'get_merge_candidates',
    'fetch_parasut_purchase_orders',
    'fetch_zoho_purchase_orders'
];

if (in_array($action, $heavyActions, true)) {
    // Heavy actions: 10 requests per minute
    if (isRateLimited(10, 60, $action)) {
        writeLog("RATE LIMITED (heavy): $action from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        jsonResponse(['success' => false, 'message' => 'Çok fazla istek gönderildi. Lütfen bir dakika bekleyin.'], 429);
    }
} else {
    // Normal actions: 120 requests per minute
    if (isRateLimited(120, 60)) {
        writeLog("RATE LIMITED (global): $action from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        jsonResponse(['success' => false, 'message' => 'Çok fazla istek gönderildi. Lütfen bir dakika bekleyin.'], 429);
    }
}

// Authentication
checkAuthentication();

// Initialize ServiceFactory for controllers
ServiceFactory::init($pdo);

// Handle GET requests for brand filter
if (isset($_GET['action']) && $_GET['action'] === 'set_brand_filter') {
    $brand = substr(trim($_GET['brand'] ?? ''), 0, 100); // sanitize + length limit
    $_SESSION['brand_filter'] = $brand;
    jsonResponse(['success' => true, 'brand' => $_SESSION['brand_filter']]);
}

// Action is already set above
// Skip logging for high-frequency polling actions (reduce log noise)
$quietActions = ['get_logs', 'get_dashboard_stats', 'get_queue_stats', 'get_sync_health'];
if (!in_array($action, $quietActions, true)) {
    writeLog("API Handler called with action: $action");
}

// Router Dispatch: All actions routed to controllers
$router = new Router($pdo);

if ($router->hasRoute($action)) {
    $router->dispatch($action);
    exit;
}

// Unknown Action
jsonResponse(['success' => false, 'message' => 'Geçersiz işlem: ' . htmlspecialchars($action)], 400);

