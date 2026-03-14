<?php
/**
 * Queue Service
 * Database-backed job queue for shared hosting environments
 */

class Queue
{
    /**
     * Add a job to the queue
     */
    public static function push(PDO $pdo, string $jobType, array $payload = [], ?string $scheduledAt = null): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO jobs (job_type, payload, status, scheduled_at, created_at)
            VALUES (?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $jobType,
            json_encode($payload),
            $scheduledAt
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Get next pending job (oldest first)
     */
    public static function getNextJob(PDO $pdo): ?array
    {
        // Use FOR UPDATE SKIP LOCKED to prevent race conditions
        $stmt = $pdo->prepare("
            SELECT * FROM jobs 
            WHERE status = 'pending' 
              AND (scheduled_at IS NULL OR scheduled_at <= NOW())
              AND attempts < max_attempts
            ORDER BY created_at ASC 
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        return $job ?: null;
    }

    /**
     * Mark job as processing
     */
    public static function markProcessing(PDO $pdo, int $jobId): void
    {
        $stmt = $pdo->prepare("
            UPDATE jobs SET status = 'processing', started_at = NOW(), attempts = attempts + 1 
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
    }

    /**
     * Mark job as completed
     */
    public static function markCompleted(PDO $pdo, int $jobId): void
    {
        $stmt = $pdo->prepare("
            UPDATE jobs SET status = 'completed', completed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
    }

    /**
     * Mark job as failed
     */
    public static function markFailed(PDO $pdo, int $jobId, string $error): void
    {
        $stmt = $pdo->prepare("
            UPDATE jobs SET status = 'failed', error_message = ?, completed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$error, $jobId]);
    }

    /**
     * Retry failed job (reset to pending)
     */
    public static function retry(PDO $pdo, int $jobId): void
    {
        $stmt = $pdo->prepare("
            UPDATE jobs SET status = 'pending', error_message = NULL, started_at = NULL, completed_at = NULL 
            WHERE id = ? AND attempts < max_attempts
        ");
        $stmt->execute([$jobId]);
    }

    /**
     * Process a single job
     */
    public static function processJob(PDO $pdo, array $job): bool
    {
        $jobId = $job['id'];
        $jobType = $job['job_type'];
        $payload = json_decode($job['payload'], true) ?? [];

        self::markProcessing($pdo, $jobId);

        // Initialize ServiceFactory for consistent singleton usage
        ServiceFactory::init($pdo);

        try {
            // Job handlers
            switch ($jobType) {
                case 'sync_all_products':
                    ServiceFactory::getParasutService()->syncProducts();
                    ServiceFactory::getZohoService()->syncProducts();
                    break;

                case 'sync_parasut_products':
                    ServiceFactory::getParasutService()->syncProducts();
                    break;

                case 'sync_zoho_products':
                    ServiceFactory::getZohoService()->syncProducts();
                    break;

                case 'cleanup_old_logs':
                    $pdo->exec("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                    break;

                case 'export_invoice_batch':
                    // Export a batch of Paraşüt invoices → Zoho
                    $sync = ServiceFactory::getSyncService();
                    $invoiceIds = $payload['invoice_ids'] ?? [];
                    if (empty($invoiceIds)) {
                        throw new Exception("export_invoice_batch: invoice_ids boş.");
                    }
                    $exportCount = 0;
                    $failCount = 0;
                    foreach ($invoiceIds as $invId) {
                        $result = $sync->exportInvoiceToZoho((string) $invId);
                        if ($result['success']) {
                            $exportCount++;
                        } else {
                            $failCount++;
                            writeLog("export_invoice_batch: Failed for $invId — " . $result['message'], 'ERROR', 'queue');
                        }
                    }
                    writeLog("export_invoice_batch: $exportCount OK / $failCount FAIL of " . count($invoiceIds), 'INFO', 'queue');
                    break;

                case 'retry_webhook':
                    $sync = ServiceFactory::getSyncService();
                    $source = $payload['source'] ?? '';
                    $whData = $payload['payload'] ?? null;
                    if (!$whData) {
                        throw new Exception("retry_webhook: payload boş.");
                    }
                    if ($source === 'parasut') {
                        $sync->handleParasutWebhook($whData);
                    } elseif ($source === 'zoho') {
                        $sync->handleZohoWebhook($whData);
                    } else {
                        throw new Exception("retry_webhook: unknown source '$source'");
                    }
                    writeLog("retry_webhook: Successfully re-processed $source webhook.", 'INFO', 'queue');
                    break;

                default:
                    throw new Exception("Unknown job type: $jobType");
            }

            self::markCompleted($pdo, $jobId);
            return true;

        } catch (Exception $e) {
            self::markFailed($pdo, $jobId, $e->getMessage());
            return false;
        }
    }

    /**
     * Get queue statistics
     */
    public static function getStats(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM jobs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cleanup old completed jobs
     */
    public static function cleanup(PDO $pdo, int $daysToKeep = 7): int
    {
        $stmt = $pdo->prepare("
            DELETE FROM jobs 
            WHERE status IN ('completed', 'failed') 
              AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        return $stmt->rowCount();
    }
}
