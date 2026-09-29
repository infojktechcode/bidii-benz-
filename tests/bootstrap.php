<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/app/autoload.php';

// PSR-4 autoloader for test support classes.
spl_autoload_register(static function (string $class) use ($projectRoot): void {
    $prefix = 'Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $projectRoot . '/tests/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Tests run in CLI: make sure a session array exists for session-dependent units.
if (!isset($_SESSION)) {
    $_SESSION = [];
}

// Fresh environment for each run.
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
