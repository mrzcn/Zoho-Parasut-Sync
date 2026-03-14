<?php
// controllers/SystemController.php

class SystemController extends BaseController
{
    public function get_logs(): void
    {
        $limit = $this->inputInt('limit', 200);
        $level = $this->input('level'); // optional filter: ERROR, WARNING, etc.
        $module = $this->input('module'); // optional filter: zoho, parasut, etc.

        // Primary: Read from system_logs database table
        try {
            $where = ["1=1"];
            $params = [];

            if ($level) {
                $where[] = "level = ?";
                $params[] = strtoupper($level);
            }
            if ($module) {
                $where[] = "module = ?";
                $params[] = $module;
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->pdo->prepare("SELECT level, module, message, context, ip_address, created_at FROM system_logs WHERE $whereSql ORDER BY id DESC LIMIT :log_limit");
            foreach ($params as $i => $val) {
                $stmt->bindValue($i + 1, $val);
            }
            $stmt->bindValue(':log_limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Reverse so oldest first (chronological order for display)
            $logs = array_reverse($logs);

            jsonResponse(['success' => true, 'data' => $logs, 'source' => 'database', 'count' => count($logs)]);
            return;
        } catch (\Exception $e) {
            // Table might not exist — fall through to file fallback
        }

        // Fallback: Read from log file
        $logFile = __DIR__ . '/../logs/debug_log.txt';
        if (!file_exists($logFile)) {
            jsonResponse(['success' => true, 'data' => 'Log dosyası henüz oluşturulmamış.']);
            return;
        }

        $content = file_get_contents($logFile);
        $lines = explode("\n", $content);
        $lastLines = array_slice($lines, -150);
        jsonResponse(['success' => true, 'data' => implode("\n", $lastLines), 'source' => 'file']);
    }

    public function clear_logs(): void
    {
        // Clear database logs
        try {
            $this->pdo->exec("TRUNCATE TABLE system_logs");
        } catch (\Exception $e) {
            // Table might not exist
        }

        // Clear file logs
        $logFile = __DIR__ . '/../logs/debug_log.txt';
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }

        jsonResponse(['success' => true, 'message' => 'Loglar temizlendi.']);
    }

    public function get_webhooks(): void
    {
        $query = trim($this->input('query', ''));
        $minChars = 3;

        if (mb_strlen($query) < $minChars) {
            jsonResponse(['success' => false, 'message' => "En az $minChars karakter giriniz."]);
        }

        $results = [];

        // 1. Parasut Invoices
        $stmt = $this->pdo->prepare("SELECT id, invoice_number, description, issue_date, net_total, currency, 'parasut_invoice' as type 
                               FROM parasut_invoices 
                               WHERE invoice_number LIKE ? OR description LIKE ? 
                               ORDER BY issue_date DESC LIMIT 5");
        $stmt->execute(["%$query%", "%$query%"]);
        $results['parasut_invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Parasut Invoice Items
        $stmt = $this->pdo->prepare("SELECT ii.invoice_id, ii.product_name, ii.quantity, ii.net_total, i.invoice_number, i.issue_date, i.currency, 'parasut_item' as type 
                               FROM parasut_invoice_items ii
                               JOIN parasut_invoices i ON ii.invoice_id = i.id
                               WHERE ii.product_name LIKE ?
                               ORDER BY i.issue_date DESC LIMIT 5");
        $stmt->execute(["%$query%"]);
        $results['parasut_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Parasut Products
        $stmt = $this->pdo->prepare("SELECT parasut_id, product_code, product_name, list_price, currency, stock_quantity, 'parasut_product' as type 
                               FROM parasut_products 
                               WHERE product_code LIKE ? OR product_name LIKE ? 
                               LIMIT 5");
        $stmt->execute(["%$query%", "%$query%"]);
        $results['parasut_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Zoho Invoices
        $stmt = $this->pdo->prepare("SELECT id, invoice_number, customer_name, description, invoice_date, total, currency, 'zoho_invoice' as type 
                               FROM zoho_invoices 
                               WHERE customer_name LIKE ? OR description LIKE ? OR raw_data LIKE ? 
                               ORDER BY invoice_date DESC LIMIT 5");
        $stmt->execute(["%$query%", "%$query%", "%$query%"]);
        $results['zoho_invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Zoho Products
        $stmt = $this->pdo->prepare("SELECT zoho_id, product_code, product_name, unit_price, currency, stock_quantity, 'zoho_product' as type 
                               FROM zoho_products 
                               WHERE product_code LIKE ? OR product_name LIKE ? 
                               LIMIT 5");
        $stmt->execute(["%$query%", "%$query%"]);
        $results['zoho_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(['success' => true, 'data' => $results]);
    }

    public function zoho_cleanup_stage(): void
    {
        $stage = $this->input('stage', '');
        $validStages = ['Invoices', 'Credit_Notes', 'Sales_Orders', 'Purchase_Orders', 'Quotes', 'Inventory_Adjustments', 'Price_Books', 'CustomModule5001', 'CustomModule5002', 'CustomModule5003', 'CustomModule5004', 'Products'];

        if (!in_array($stage, $validStages)) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz aşama: ' . $stage], 400);
        }

        try {
            $ids = $this->zoho()->getRecordIds($stage, 50);

            if (empty($ids)) {
                jsonResponse(['success' => true, 'finished' => true, 'stage' => $stage]);
            }

            $result = $this->zoho()->massDeleteRecords($stage, $ids);

            if ($result['success']) {
                $response = ['success' => true, 'finished' => false, 'count' => $result['count'] ?? 0, 'stage' => $stage];
                if (isset($result['partial_errors'])) {
                    $response['partial_errors'] = $result['partial_errors'];
                }
                jsonResponse($response);
            } else {
                jsonResponse(['success' => false, 'message' => $result['error'] ?? 'Delete failed']);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    public function zoho_fetch_approval_batch(): void
    {
        $stage = $this->input('stage', '');
        $validStages = ['Invoices', 'Credit_Notes', 'Sales_Orders', 'Purchase_Orders', 'Quotes', 'Inventory_Adjustments', 'Price_Books', 'CustomModule5001', 'CustomModule5002', 'CustomModule5003', 'CustomModule5004', 'Products'];

        if (!in_array($stage, $validStages)) {
            jsonResponse(['success' => false, 'message' => 'Geçersiz aşama: ' . $stage], 400);
        }

        try {
            $records = $this->zoho()->getRecordsForApproval($stage, 10);

            if (empty($records)) {
                jsonResponse(['success' => true, 'finished' => true]);
            } else {
                jsonResponse(['success' => true, 'finished' => false, 'records' => $records]);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    public function zoho_delete_single(): void
    {
        $stage = $this->input('stage', '');
        $id = $this->input('id', '');

        if (empty($stage) || empty($id)) {
            jsonResponse(['success' => false, 'message' => 'Eksik parametreler.'], 400);
        }

        try {
            $result = $this->zoho()->massDeleteRecords($stage, [$id]);

            if ($result['success']) {
                jsonResponse(['success' => true]);
            } else {
                jsonResponse(['success' => false, 'message' => $result['error'] ?? 'Silme başarısız']);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * Re-queue all failed jobs (admin action)
     * Resets failed jobs back to 'pending' so they are retried on next cron run.
     */
    public function retry_failed_jobs(): void
    {
        $jobType = $this->input('job_type', ''); // optional filter

        $sql = "UPDATE jobs SET status = 'pending', error_message = NULL, started_at = NULL, completed_at = NULL
                WHERE status = 'failed' AND attempts < max_attempts";
        $params = [];

        if (!empty($jobType)) {
            $sql .= " AND job_type = ?";
            $params[] = $jobType;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();

        writeLog("Admin: retry_failed_jobs — $count jobs reset to pending" . ($jobType ? " (type: $jobType)" : ''), 'INFO', 'system');
        jsonResponse(['success' => true, 'reset_count' => $count, 'message' => "$count job yeniden kuyruğa alındı."]);
    }
}
