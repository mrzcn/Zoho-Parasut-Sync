<?php
// cron.php
// Public URL for triggering background sync manually or via system cron.
// Example: https://site.com/cron.php?key=YOUR_SECRET_KEY

// Basic Setup first — needed to read secret from DB
@set_time_limit(300);
@ini_set('memory_limit', '256M');
require_once __DIR__ . '/bootstrap.php';

// Security: Cron secret key loaded from DB (never hardcoded in source)
$cronSecret = getSetting($pdo, 'cron_secret_key');

if (!$cronSecret) {
    header('HTTP/1.1 503 Service Unavailable');
    die('Cron secret key tanımlanmamış. Lütfen ayarlar sayfasından tanımlayın.');
}

if (!isset($_GET['key']) || !hash_equals($cronSecret, $_GET['key'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Erişim engellendi. Geçersiz anahtar.');
}


// Rate Limiting (Prevent running too often)
$lastRun = getSetting($pdo, 'cron_last_run');
$now = time();
$interval = 300; // 5 minutes in seconds

if ($lastRun && ($now - $lastRun) < $interval) {
    $wait = $interval - ($now - $lastRun);
    die("Çok sık istek gönderildi. Lütfen $wait saniye bekleyin.");
}

// Update Last Run Time BEFORE processing to prevent race conditions
updateSetting($pdo, 'cron_last_run', $now);

writeLog("CRON: Job Started.");

try {
    // 1. Sync Products (Parasut -> DB)
    // Only fetches products used in invoices to keep it fast
    $parasut = new ParasutService($pdo);
    writeLog("CRON: Syncing Parasut Products...");
    $pCount = $parasut->syncProducts();
    writeLog("CRON: Parasut Products synced: $pCount");

    // 2. Sync Products (Zoho -> DB)
    $zoho = new ZohoService($pdo);
    writeLog("CRON: Syncing Zoho Products...");
    $zCount = $zoho->syncProducts();
    writeLog("CRON: Zoho Products synced: $zCount");

    // 3. Sync Invoices (Parasut -> DB) - Uses the same method as the admin panel
    writeLog("CRON: Syncing Parasut Invoices...");
    $syncResult = $parasut->syncInvoices(100, false); // incremental sync, last 100
    $insertedCount = $syncResult['invoices'] ?? 0;
    writeLog("CRON: Invoices synced: $insertedCount");

    // ====================================================================
    // 4. Process Queue Jobs (up to 10 per cron run)
    // ====================================================================
    writeLog("CRON: Processing queue jobs...");
    $jobsProcessed = 0;
    $jobsFailed = 0;
    $maxJobsPerRun = 10;

    for ($j = 0; $j < $maxJobsPerRun; $j++) {
        $pdo->beginTransaction();
        $job = Queue::getNextJob($pdo);
        if (!$job) {
            $pdo->commit();
            break; // No more pending jobs
        }
        $success = Queue::processJob($pdo, $job);
        $pdo->commit();
        if ($success) {
            $jobsProcessed++;
        } else {
            $jobsFailed++;
        }
    }

    writeLog("CRON: Queue: $jobsProcessed OK, $jobsFailed FAIL.");

    // ====================================================================
    // 5. Cleanup (jobs > 7 days, expired locks)
    // ====================================================================
    Queue::cleanup($pdo, 7);

    header('Content-Type: text/plain');
    echo "OK\n";
    echo "Paraşüt Ürün Upsert: $pCount\n";
    echo "Zoho Ürün Upsert: $zCount\n";
    echo "Yeni Fatura: $insertedCount\n";
    echo "Queue Jobs OK: $jobsProcessed, FAIL: $jobsFailed\n";

} catch (Exception $e) {
    writeLog("CRON ERROR: " . $e->getMessage());
    die("Hata oluştu: " . $e->getMessage());
}
