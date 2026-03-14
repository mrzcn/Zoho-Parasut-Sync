<?php
// controllers/DashboardController.php

class DashboardController extends BaseController
{
    public function get_dashboard_stats(): void
    {
        $stats = [];

        // 1. Total counts by sync status
        $statusQuery = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN synced_to_zoho = 1 THEN 1 ELSE 0 END) as synced,
            SUM(CASE WHEN synced_to_zoho = 0 OR synced_to_zoho IS NULL THEN 1 ELSE 0 END) as unsynced,
            SUM(CASE WHEN synced_to_zoho = 2 THEN 1 ELSE 0 END) as errors
            FROM parasut_invoices";
        $statusResult = $this->pdo->query($statusQuery)->fetch(PDO::FETCH_ASSOC);
        $stats['status'] = $statusResult;

        // 2. Invoices by year
        $yearQuery = "SELECT 
            YEAR(issue_date) as year,
            COUNT(*) as count,
            SUM(net_total) as total_amount,
            SUM(CASE WHEN synced_to_zoho = 1 THEN 1 ELSE 0 END) as synced_count
            FROM parasut_invoices
            WHERE YEAR(issue_date) BETWEEN 2019 AND YEAR(CURDATE())
            GROUP BY YEAR(issue_date)
            ORDER BY year DESC";
        $stats['by_year'] = $this->pdo->query($yearQuery)->fetchAll(PDO::FETCH_ASSOC);

        // 3. Monthly trend for current year
        $currentYear = (int) date('Y');
        $monthlyStmt = $this->pdo->prepare("SELECT 
            MONTH(issue_date) as month,
            COUNT(*) as count,
            SUM(net_total) as total_amount
            FROM parasut_invoices
            WHERE YEAR(issue_date) = ?
            GROUP BY MONTH(issue_date)
            ORDER BY month ASC");
        $monthlyStmt->execute([$currentYear]);
        $stats['monthly_trend'] = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Recent sync activity (last 10)
        $recentQuery = "SELECT 
            invoice_number,
            issue_date,
            net_total,
            currency,
            synced_at,
            synced_to_zoho
            FROM parasut_invoices
            WHERE synced_to_zoho IN (1, 2)
            ORDER BY synced_at DESC
            LIMIT 10";
        $stats['recent_activity'] = $this->pdo->query($recentQuery)->fetchAll(PDO::FETCH_ASSOC);

        // 5. Success rate
        $total = (int) $statusResult['total'];
        $synced = (int) $statusResult['synced'];
        $stats['success_rate'] = $total > 0 ? round(($synced / $total) * 100, 1) : 0;

        // 6. Product Statistics
        $prodStats = [];
        $prodStats['parasut_total'] = $this->pdo->query("SELECT COUNT(*) FROM parasut_products")->fetchColumn();
        $prodStats['zoho_total'] = $this->pdo->query("SELECT COUNT(*) FROM zoho_products")->fetchColumn();

        $missingZohoQuery = "SELECT COUNT(*) FROM parasut_products p 
                             LEFT JOIN zoho_products z ON p.product_code = z.product_code 
                             WHERE z.id IS NULL AND p.product_code IS NOT NULL AND p.product_code != ''";
        $prodStats['missing_in_zoho'] = $this->pdo->query($missingZohoQuery)->fetchColumn();

        $missingParasutQuery = "SELECT COUNT(*) FROM zoho_products z 
                                LEFT JOIN parasut_products p ON z.product_code = p.product_code 
                                WHERE p.id IS NULL AND z.product_code IS NOT NULL AND z.product_code != ''";
        $prodStats['missing_in_parasut'] = $this->pdo->query($missingParasutQuery)->fetchColumn();

        $stats['product_stats'] = $prodStats;

        // 7. Purchase Orders Statistics
        $poStats = [];
        $poStatusQuery = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN synced_to_zoho = 1 THEN 1 ELSE 0 END) as synced,
            SUM(CASE WHEN synced_to_zoho = 0 OR synced_to_zoho IS NULL THEN 1 ELSE 0 END) as unsynced,
            SUM(CASE WHEN synced_to_zoho = 2 THEN 1 ELSE 0 END) as errors
            FROM parasut_purchase_orders";
        $poStatusResult = $this->pdo->query($poStatusQuery)->fetch(PDO::FETCH_ASSOC);
        $poStats['status'] = $poStatusResult;

        $poTotal = (int) $poStatusResult['total'];
        $poSynced = (int) $poStatusResult['synced'];
        $poStats['success_rate'] = $poTotal > 0 ? round(($poSynced / $poTotal) * 100, 1) : 0;

        $poRecentQuery = "SELECT 
            invoice_number,
            issue_date,
            net_total,
            currency,
            synced_at,
            synced_to_zoho
            FROM parasut_purchase_orders
            WHERE synced_to_zoho IN (1, 2)
            ORDER BY synced_at DESC
            LIMIT 10";
        $poStats['recent_activity'] = $this->pdo->query($poRecentQuery)->fetchAll(PDO::FETCH_ASSOC);

        $stats['purchase_order_stats'] = $poStats;

        jsonResponse(['success' => true, 'data' => $stats]);
    }

    /**
     * Queue Health Metrics
     * Returns pending/failed job counts and details for the last 24 hours.
     */
    public function get_queue_stats(): void
    {
        // Overall counters from Queue class
        $counters = Queue::getStats($this->pdo);

        // Failed jobs from last 24h (details)
        $failedStmt = $this->pdo->query("
            SELECT id, job_type, error_message, attempts, max_attempts, created_at, completed_at
            FROM jobs
            WHERE status = 'failed'
              AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY completed_at DESC
            LIMIT 20
        ");
        $failedJobs = $failedStmt->fetchAll(PDO::FETCH_ASSOC);

        // Pending jobs older than 10 minutes (stuck)
        $stuckStmt = $this->pdo->query("
            SELECT id, job_type, attempts, max_attempts, created_at
            FROM jobs
            WHERE status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ORDER BY created_at ASC
            LIMIT 10
        ");
        $stuckJobs = $stuckStmt->fetchAll(PDO::FETCH_ASSOC);

        // Retry webhook jobs (for visibility)
        $retryCountStmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM jobs
            WHERE job_type = 'retry_webhook' AND status IN ('pending', 'failed')
        ");
        $retryCountStmt->execute();
        $pendingWebhookRetries = (int) $retryCountStmt->fetchColumn();

        jsonResponse([
            'success' => true,
            'data' => [
                'counters' => $counters,
                'failed_jobs' => $failedJobs,
                'stuck_jobs' => $stuckJobs,
                'pending_webhook_retries' => $pendingWebhookRetries,
                'generated_at' => date('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Sync Health Metrics (last 24 hours)
     * Returns invoice sync success rate, avg duration, and recent errors.
     */
    public function get_sync_health(): void
    {
        // Invoice sync rates & timing for last 24h
        $invoiceHealthStmt = $this->pdo->query("
            SELECT
                COUNT(*) as total_synced,
                SUM(CASE WHEN synced_to_zoho = 1 THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN synced_to_zoho = 2 THEN 1 ELSE 0 END) as fail_count,
                AVG(TIMESTAMPDIFF(SECOND, created_at, synced_at)) as avg_sync_seconds,
                MIN(synced_at) as first_sync,
                MAX(synced_at) as last_sync
            FROM parasut_invoices
            WHERE synced_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $invoiceHealth = $invoiceHealthStmt->fetch(PDO::FETCH_ASSOC);

        // Recent sync errors with error messages
        $errorsStmt = $this->pdo->query("
            SELECT
                parasut_id,
                invoice_number,
                sync_error,
                synced_at
            FROM parasut_invoices
            WHERE synced_to_zoho = 2
              AND synced_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY synced_at DESC
            LIMIT 10
        ");
        $syncErrors = $errorsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Cron last run
        $lastCronRun = getSetting($this->pdo, 'cron_last_run');

        // System logs for ERROR/CRITICAL in last 24h (if system_logs table exists)
        $recentErrors = [];
        try {
            $logStmt = $this->pdo->query("
                SELECT level, message, created_at
                FROM system_logs
                WHERE level IN ('ERROR', 'CRITICAL')
                  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $recentErrors = $logStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // system_logs table may not exist in all environments
        }

        $successRate = 0;
        if (!empty($invoiceHealth['total_synced']) && $invoiceHealth['total_synced'] > 0) {
            $successRate = round(($invoiceHealth['success_count'] / $invoiceHealth['total_synced']) * 100, 1);
        }

        jsonResponse([
            'success' => true,
            'data' => [
                'invoice_health' => array_merge($invoiceHealth, ['success_rate_24h' => $successRate]),
                'sync_errors' => $syncErrors,
                'recent_log_errors' => $recentErrors,
                'cron_last_run' => $lastCronRun ? date('Y-m-d H:i:s', (int) $lastCronRun) : null,
                'generated_at' => date('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * API Metrics dashboard: call counts, avg duration, error rates by service
     */
    public function get_api_metrics(): void
    {
        try {
            // Summary: last 24h by service
            $summaryStmt = $this->pdo->query("
                SELECT 
                    service,
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN http_code >= 200 AND http_code < 300 THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN http_code >= 400 THEN 1 ELSE 0 END) as error_count,
                    SUM(CASE WHEN http_code = 429 THEN 1 ELSE 0 END) as rate_limit_count,
                    ROUND(AVG(duration_ms)) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    SUM(is_retry) as retry_count
                FROM api_metrics
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY service
            ");
            $summary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

            // Hourly trend (last 24h)
            $trendStmt = $this->pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour,
                    service,
                    COUNT(*) as calls,
                    SUM(CASE WHEN http_code >= 400 THEN 1 ELSE 0 END) as errors
                FROM api_metrics
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY hour, service
                ORDER BY hour
            ");
            $trend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

            // Top errors (last 24h)
            $errorStmt = $this->pdo->query("
                SELECT 
                    service, endpoint, http_code, error_message,
                    COUNT(*) as occurrences,
                    MAX(created_at) as last_seen
                FROM api_metrics
                WHERE http_code >= 400
                  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY service, endpoint, http_code, error_message
                ORDER BY occurrences DESC
                LIMIT 10
            ");
            $topErrors = $errorStmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'hourly_trend' => $trend,
                    'top_errors' => $topErrors,
                ]
            ]);
        } catch (Exception $e) {
            jsonResponse([
                'success' => true,
                'data' => ['summary' => [], 'hourly_trend' => [], 'top_errors' => []],
                'message' => 'API metrics tablosu henüz oluşturulmamış olabilir.'
            ]);
        }
    }
}
