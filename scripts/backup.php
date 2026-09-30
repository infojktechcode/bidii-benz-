<?php

declare(strict_types=1);

/**
 * Database backup (mysqldump wrapper).
 *
 * Usage:
 *   php scripts/backup.php              dump into storage/backups/
 *   php scripts/backup.php --list       list existing backups (newest first)
 *   php scripts/backup.php --out=FILE   write to an explicit path
 *
 * The dump is plain SQL (schema + data, with DROP TABLE) so a restore is a
 * straight `mysql < backup.sql` — see docs/deploy.md for the full runbook.
 * The password reaches mysqldump through the MYSQL_PWD environment variable,
 * never on the command line. Backups contain every client PII row:
 * storage/backups/ is git-ignored and web-denied via .htaccess.
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/autoload.php';

use App\Core\Bootstrap;

[$config] = Bootstrap::boot($projectRoot, false);

$backupDir = $projectRoot . '/storage/backups';

/** @param list<string> $args */
function usageError(array $args): never
{
    fwrite(STDERR, 'Unknown argument(s): ' . implode(' ', $args) . PHP_EOL);
    fwrite(STDERR, 'Usage: php scripts/backup.php [--list] [--out=FILE]' . PHP_EOL);
    exit(1);
}

$args = array_slice($argv, 1);
$listOnly = false;
$outPath = null;
foreach ($args as $arg) {
    if ($arg === '--list') {
        $listOnly = true;
    } elseif (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, strlen('--out='));
        if ($outPath === '') {
            usageError([$arg]);
        }
    } else {
        usageError([$arg]);
    }
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "ERROR: cannot create $backupDir" . PHP_EOL);
    exit(1);
}

if ($listOnly) {
    $files = glob($backupDir . '/*.sql') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    if ($files === []) {
        echo "No backups in storage/backups/ yet.\n";
        exit(0);
    }
    foreach ($files as $file) {
        printf(
            "%s  %8.1f KB  %s\n",
            date('Y-m-d H:i:s', (int) filemtime($file)),
            filesize($file) / 1024,
            basename($file)
        );
    }
    exit(0);
}

// --- locate mysqldump -------------------------------------------------------

function mysqldumpBinary(): string
{
    $candidates = [];
    $override = getenv('MYSQLDUMP_BIN');
    if (is_string($override) && $override !== '') {
        $candidates[] = $override;
    }
    $path = getenv('PATH');
    if (is_string($path) && $path !== '') {
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'mysqldump';
            if (DIRECTORY_SEPARATOR === '\\') {
                $candidates[] = $dir . DIRECTORY_SEPARATOR . 'mysqldump.exe';
            }
        }
    }
    // Stock XAMPP locations (Windows and Linux).
    $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    $candidates[] = '/opt/lampp/bin/mysqldump';
    $candidates[] = '/usr/bin/mysqldump';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return 'mysqldump'; // last resort: relies on PATH when executed
}

$binary = mysqldumpBinary();

// MySQL 8 clients ship --column-statistics (needed against MySQL 8 servers,
// rejected by MariaDB); MariaDB clients reject the flag outright. Ask the
// binary what it is before choosing.
$versionProbe = [];
@exec($binary . ' --version 2>&1', $versionProbe);
$isMariaDb = stripos(implode(' ', $versionProbe), 'mariadb') !== false;

// --- build the dump ---------------------------------------------------------

$dbHost = (string) $config->get('db.host', '127.0.0.1');
$dbPort = (string) $config->get('db.port', '3306');
$dbName = (string) $config->get('db.name', 'bidii_benz');
$dbUser = (string) $config->get('db.user', 'root');
$dbPass = (string) $config->get('db.pass', '');

if ($outPath === null) {
    $safeName = preg_replace('/[^A-Za-z0-9_]/', '', $dbName) ?: 'backup';
    $outPath = $backupDir . '/' . $safeName . '-' . date('Ymd-His') . '.sql';
}

if (file_exists($outPath)) {
    fwrite(STDERR, "ERROR: $outPath already exists; use --out= to pick another path." . PHP_EOL);
    exit(1);
}

$command = [
    $binary,
    '--host=' . $dbHost,
    '--port=' . $dbPort,
    '--user=' . $dbUser,
    '--single-transaction',   // consistent InnoDB snapshot without locking writers
    '--quick',
    '--no-tablespaces',       // no PROCESS privilege needed (least-privilege user)
    '--default-character-set=utf8mb4',
    '--result-file=' . $outPath,
];
if (!$isMariaDb) {
    $command[] = '--column-statistics=0';
}
$command[] = $dbName;

$env = getenv();
if (is_array($env)) {
    if ($dbPass !== '') {
        $env['MYSQL_PWD'] = $dbPass; // child process only; never argv, never printed
    } else {
        unset($env['MYSQL_PWD']);
    }
} else {
    $env = $dbPass !== '' ? ['MYSQL_PWD' => $dbPass] : [];
}

$spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($command, $spec, $pipes, null, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "ERROR: could not start mysqldump ($binary)." . PHP_EOL);
    exit(1);
}
fclose($pipes[0]);
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($exitCode !== 0) {
    if (file_exists($outPath)) {
        unlink($outPath); // never leave a truncated dump behind
    }
    fwrite(STDERR, "ERROR: mysqldump exited with code $exitCode" . PHP_EOL);
    if (trim($stderr) !== '') {
        fwrite(STDERR, trim($stderr) . PHP_EOL);
    }
    fwrite(STDERR, "Check MySQL is running and DB_* in .env are correct." . PHP_EOL);
    exit(1);
}
if (trim($stdout) !== '') {
    fwrite(STDERR, trim($stdout) . PHP_EOL);
}

$size = (int) filesize($outPath);
if ($size <= 0) {
    fwrite(STDERR, "ERROR: $outPath is empty — treat this run as failed." . PHP_EOL);
    exit(1);
}

printf("Backup written: %s (%.1f KB)\n", $outPath, $size / 1024);
echo "Restore procedure: docs/deploy.md\n";
