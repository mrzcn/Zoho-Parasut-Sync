<?php
/**
 * Master Cron Runner
 * Single entry point for all scheduled tasks - runs every minute via cPanel cron
 * 
 * Cron Setup (cPanel):
 * * * * * * /usr/bin/php /home/user/public_html/nolto.sync/cron/cron_runner.php >> /dev/null 2>&1
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

// Configuration
define('MAX_RUN_TIME', 50); // Max seconds before stopping (leave 10s buffer for 60s cron)
define('MAX_JOBS_PER_RUN', 10); // Max jobs to process per run

$startTime = time();

// Bootstrap
require_once __DIR__ . '/../bootstrap.php';

// Lock file to prevent overlapping runs
$lockFile = __DIR__ . '/../logs/cron.lock';
$lockFp = fopen($lockFile, 'w');

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    writeLog("Cron Runner: Another instance is running. Exiting.", 'WARNING', 'cron');
    exit(0);
}

// Write PID
fwrite($lockFp, getmypid());

try {
    writeLog("Cron Runner: Started", 'INFO', 'cron');

    // ==================== 1. PROCESS QUEUE ====================
    $jobsProcessed = 0;

    while ((time() - $startTime) < MAX_RUN_TIME && $jobsProcessed < MAX_JOBS_PER_RUN) {
        $pdo->beginTransaction();

        try {
            $job = Queue::getNextJob($pdo);

            if (!$job) {
                $pdo->rollBack();
                break; // No more pending jobs
            }

            writeLog("Processing job #{$job['id']}: {$job['job_type']}", 'INFO', 'queue');

            $success = Queue::processJob($pdo, $job);

            if ($success) {
                writeLog("Job #{$job['id']} completed successfully", 'INFO', 'queue');
            } else {
                writeLog("Job #{$job['id']} failed", 'ERROR', 'queue');
            }

            $pdo->commit();
            $jobsProcessed++;

        } catch (Exception $e) {
            $pdo->rollBack();
            writeLog("Queue error: " . $e->getMessage(), 'ERROR', 'queue');
            break;
        }
    }

    // ==================== 2. SCHEDULED TASKS ====================
    $hour = (int) date('H');
    $minute = (int) date('i');
    $dayOfWeek = (int) date('w'); // 0 = Sunday

    // Every day at 03:00 - Cleanup old logs and jobs
    if ($hour === 3 && $minute === 0) {
        try {
            $pdo->exec("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            Queue::cleanup($pdo, 7);
            cleanRateLimitFiles(); // Clean up old rate limit tracking files
            writeLog("Scheduled cleanup completed", 'INFO', 'cron');
        } catch (Exception $e) {
            writeLog("Cleanup failed: " . $e->getMessage(), 'ERROR', 'cron');
        }
    }

    // Every day at 09:00 - Auto-sync products (optional, can be enabled)
    // if ($hour === 9 && $minute === 0) {
    //     Queue::push($pdo, 'sync_all_products');
    //     writeLog("Scheduled: sync_all_products queued", 'INFO', 'cron');
    // }

    // Every Monday at 08:00 - Weekly report (example)
    // if ($dayOfWeek === 1 && $hour === 8 && $minute === 0) {
    //     Queue::push($pdo, 'send_weekly_report');
    // }

    $elapsed = time() - $startTime;
    writeLog("Cron Runner: Completed. Jobs processed: $jobsProcessed. Time: {$elapsed}s", 'INFO', 'cron');

} catch (Exception $e) {
    writeLog("Cron Runner: Fatal error - " . $e->getMessage(), 'CRITICAL', 'cron');
} finally {
    // Release lock
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
