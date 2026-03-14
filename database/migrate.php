<?php
// database/migrate.php
// Simple file-based migration runner
// Usage: php database/migrate.php (or called from bootstrap)

/**
 * Run pending database migrations.
 * Each migration file is run once and tracked in a `migrations` table.
 */
function runMigrations(PDO $pdo): array
{
    $results = ['applied' => [], 'skipped' => [], 'errors' => []];

    // Ensure migrations tracking table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `migration` varchar(255) NOT NULL,
        `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Get already-applied migrations
    $stmt = $pdo->query("SELECT migration FROM migrations ORDER BY id ASC");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $appliedMap = array_flip($applied);

    // Scan migration files
    $migrationDir = __DIR__ . '/migrations';
    if (!is_dir($migrationDir)) {
        @mkdir($migrationDir, 0755, true);
        return $results;
    }

    $files = glob($migrationDir . '/*.sql');
    sort($files); // Ensure order by filename (001_, 002_, etc.)

    foreach ($files as $file) {
        $name = basename($file);

        if (isset($appliedMap[$name])) {
            $results['skipped'][] = $name;
            continue;
        }

        try {
            $sql = file_get_contents($file);
            if (empty(trim($sql))) {
                continue;
            }

            // Execute migration (may contain multiple statements)
            $pdo->exec($sql);

            // Record as applied
            $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
            $stmt->execute([$name]);

            $results['applied'][] = $name;
        } catch (Exception $e) {
            $results['errors'][] = "$name: " . $e->getMessage();
        }
    }

    return $results;
}
