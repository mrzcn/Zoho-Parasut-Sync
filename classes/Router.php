<?php
// classes/Router.php
// Lightweight action router — maps API actions to controller methods
// Uses Composer autoloading — no require_once needed for classes

class Router
{
    private PDO $pdo;
    private array $routes = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->registerRoutes();
    }

    /**
     * Register action → controller mappings
     */
    private function registerRoutes(): void
    {
        // Settings & Auth
        $this->group('SettingsController', [
            'save_settings',
            'test_parasut',
            'test_zoho',
            'exchange_zoho_code',
        ]);

        // Dashboard
        $this->group('DashboardController', [
            'get_dashboard_stats',
            'get_queue_stats',
            'get_sync_health',
            'get_api_metrics',
        ]);

        // Sync Operations
        $this->group('SyncController', [
            'sync_zoho_to_parasut_stock',
            'sync_invoice_statuses',
            'sync_zoho_to_parasut_invoices',
        ]);

        // System (logs, search, cleanup)
        $this->group('SystemController', [
            'get_logs',
            'clear_logs',
            'get_webhooks',
            'zoho_cleanup_stage',
            'zoho_fetch_approval_batch',
            'zoho_delete_single',
            'retry_failed_jobs',
        ]);

        // Products (CRUD, sync, comparison, bulk)
        $this->group('ProductController', [
            'get_combined_products',
            'get_products_comparison',
            'get_code_mismatches',
            'sync_all_products',
            'fetch_parasut_products',
            'fetch_zoho_products',
            'sync_single_product_to_zoho',
            'sync_all_missing_products',
            'get_parasut_products',
            'get_zoho_products',
            'get_zoho_products_db',
            'get_parasut_products_list',
            'get_sync_queue',
            'update_product_unified',
            'update_parasut_product',
            'update_parasut_product_lite',
            'update_zoho_product',
            'update_zoho_product_lite',
            'update_product_in_both_systems',
            'update_price_in_zoho',
            'sync_price',
            'check_product_from_parasut',
            'check_product_from_zoho',
            'create_zoho_product',
            'create_product_in_zoho_from_parasut',
            'create_product_in_parasut_from_zoho',
            'zoho_delete_batch',
            'zoho_delete_products_batch',
            'bulk_update_product_codes',
            'get_merge_candidates',

            'update_product_name',
            'global_search',
        ]);

        // Invoices (fetch, get, details, contacts, export)
        $this->group('InvoiceController', [
            'fetch_parasut_invoices',
            'get_parasut_invoices',
            'fetch_parasut_invoice_details',
            'get_parasut_invoice_details',
            'get_invoice_sync_status',
            'get_parasut_invoices_list',
            'get_unsynced_invoices_ids',
            'fetch_zoho_invoices',
            'fetch_contacts',
            'create_zoho_account',
            'export_invoice_to_zoho',
            'update_zoho_taxes',
            'get_invoices_comparison',
            'get_invoice_comparison',
            'get_status_update_candidates',
            'update_invoice_status_in_zoho',
        ]);

        // Purchase Orders (fetch, get, details, export)
        $this->group('PurchaseOrderController', [
            'fetch_parasut_purchase_orders',
            'get_parasut_purchase_orders',
            'get_parasut_purchase_order_details',
            'fetch_parasut_purchase_order_details',
            'fetch_zoho_purchase_orders',
            'export_purchase_order_to_zoho',
            'get_unsynced_purchase_orders_ids',
        ]);

        // Merge & Duplicate Management
        $this->group('MergeController', [
            'get_all_zoho_products',
            'detect_duplicate_products',
            'detect_duplicate_invoices',
            'detect_duplicate_purchase_orders',
            'merge_products',
            'merge_invoices',
            'merge_purchase_orders',
            'get_merge_log',
            'fix_old_merges',
        ]);
    }

    /**
     * Register a group of actions to a controller class
     */
    private function group(string $class, array $actions): void
    {
        foreach ($actions as $action) {
            $this->routes[$action] = $class;
        }
    }

    /**
     * Check if an action has a registered route
     */
    public function hasRoute(string $action): bool
    {
        return isset($this->routes[$action]);
    }

    /**
     * Dispatch an action to its controller (class loaded via autoloader)
     */
    public function dispatch(string $action): void
    {
        if (!isset($this->routes[$action]))
            return;

        $className = $this->routes[$action];
        $controller = new $className($this->pdo);
        $method = str_replace(['.', '-'], '_', $action);

        if (!method_exists($controller, $method)) {
            jsonResponse(['success' => false, 'message' => "Action method not found: {$className}::$method"], 500);
            return;
        }

        try {
            $controller->$method();
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            $errorFile = basename($e->getFile()) . ':' . $e->getLine();
            writeLog("DISPATCH ERROR [$action → {$className}::$method]: $errorMsg ($errorFile)", 'ERROR');
            jsonResponse([
                'success' => false,
                'message' => 'Sunucu hatası: ' . $errorMsg,
            ], 500);
        }
    }

    /**
     * Get all registered action names
     */
    public function getRegisteredActions(): array
    {
        return array_keys($this->routes);
    }
}
