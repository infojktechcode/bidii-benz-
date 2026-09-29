<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Shared bootstrap: loads config, starts hardened session, wires logger.
 */
final class Bootstrap
{
    /**
     * @return array{0: Config, 1: \PDO|null}
     */
    public static function boot(string $projectRoot, bool $withDatabase = true): array
    {
        $config = Config::load($projectRoot);
        Log::init((string) $config->get('logs_dir'));
        Session::start($config);

        $pdo = null;
        if ($withDatabase) {
            $pdo = Database::connect($config);
        }

        return [$config, $pdo];
    }
}
