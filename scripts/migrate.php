<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 * Usage:
 *   php scripts/migrate.php            apply all pending migrations
 *   php scripts/migrate.php status     show applied/pending
 *
 * Files: database/migrations/NNNN_name.sql (applied in filename order,
 * tracked in schema_migrations, each applied inside one transaction).
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/autoload.php';

use App\Core\Bootstrap;
use App\Core\Database;

[$config, $pdo] = Bootstrap::boot($projectRoot, false);

$command = $argv[1] ?? 'up';

try {
    $pdo = Database::connect($config);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: cannot connect to database: " . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Start MySQL (XAMPP) and check .env, then retry." . PHP_EOL);
    exit(1);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dir = $projectRoot . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    if (!isset($appliedSet[$name])) {
        $pending[] = $file;
    }
}

if ($command === 'status') {
    echo "Applied (" . count($applied) . "):\n";
    foreach ($applied as $m) {
        echo "  [x] $m\n";
    }
    echo "Pending (" . count($pending) . "):\n";
    foreach ($pending as $f) {
        echo "  [ ] " . basename($f) . "\n";
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    echo "Applying $name ... ";
    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $stmt->execute([$name]);
        $pdo->commit();
        echo "OK\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "FAILED\n";
        fwrite(STDERR, "  " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo "Done.\n";
