<?php

declare(strict_types=1);

/**
 * Audit-trail retention pruner.
 *
 * Owner policy: audit_logs rows are kept for AUDIT_RETENTION_MONTHS
 * (default 12) and then removed — Kenya DPA 2019 storage limitation.
 *
 * Usage:
 *   php scripts/prune.php              delete audit rows older than the window
 *   php scripts/prune.php --dry-run    report what would be deleted, delete nothing
 *   php scripts/prune.php --months=N   override the retention window for this run
 *
 * Schedule it (deploy.md §12); a run with nothing to delete is a no-op.
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/autoload.php';

use App\Core\Bootstrap;
use App\Core\Database;
use App\Repositories\AuditRepository;

[$config, $pdo] = Bootstrap::boot($projectRoot, false);

$dryRun = false;
$months = (int) $config->get('audit.retention_months', 12);
$usage = "Usage: php scripts/prune.php [--dry-run] [--months=N]\n";

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--months=(\d+)$/', $arg, $m) === 1) {
        $months = (int) $m[1];
    } else {
        fwrite(STDERR, "ERROR: unknown argument '{$arg}'" . PHP_EOL);
        fwrite(STDERR, $usage);
        exit(1);
    }
}

if ($months < 1) {
    fwrite(STDERR, "ERROR: retention must be at least 1 month" . PHP_EOL);
    fwrite(STDERR, $usage);
    exit(1);
}

try {
    $pdo = Database::connect($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: cannot connect to database: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Start MySQL (XAMPP) and check .env, then retry.' . PHP_EOL);
    exit(1);
}

$cutoff = (new DateTimeImmutable("-{$months} months"))->format('Y-m-d H:i:s');
$audit = new AuditRepository($pdo);

if ($dryRun) {
    $n = $audit->countBefore($cutoff);
    echo "Dry run: {$n} audit row(s) older than {$cutoff} would be deleted (retention {$months} months).\n";
    exit(0);
}

$deleted = $audit->pruneBefore($cutoff);
echo "Deleted {$deleted} audit row(s) older than {$cutoff} (retention {$months} months).\n";
