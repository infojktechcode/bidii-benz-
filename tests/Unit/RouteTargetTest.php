<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Structural regression tests for the two failure classes that produced the
 * pre-Phase-4 HTTP 500s:
 *
 *  1. routes.php referencing a controller class that does not exist, and
 *  2. a controller rendering a view template that does not exist.
 *
 * They are static checks on purpose — they must fail even when the web server
 * is not running.
 */
final class RouteTargetTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function routesSource(): string
    {
        $path = self::ROOT . '/app/routes.php';
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testEveryReferencedControllerClassExists(): void
    {
        $source = self::routesSource();
        preg_match_all('/Controllers\\\\(\w+)Controller::class/', $source, $matches);

        self::assertNotEmpty($matches[1], 'routes.php must register at least one controller');

        $seen = [];
        foreach (array_unique($matches[1]) as $short) {
            $seen[] = $short;
            $file = self::ROOT . '/app/Controllers/' . $short . 'Controller.php';
            self::assertFileExists(
                $file,
                "routes.php references App\\Controllers\\{$short}Controller but {$file} does not exist"
            );
        }

        // Guard against a silent regression where the regex stops matching.
        self::assertContains('Admin', $seen, 'admin routes must still be registered');
        self::assertContains('Booking', $seen);
        self::assertContains('Payment', $seen);
        self::assertContains('Car', $seen);
    }

    public function testEveryRenderedViewTemplateExists(): void
    {
        $root = self::ROOT . '/app';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/View::render\\(\\s*'([^']+)'/", $source, $matches);
            foreach ($matches[1] as $template) {
                $checked++;
                $path = $root . '/views/' . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.php';
                self::assertFileExists(
                    $path,
                    $file->getFilename() . " renders '{$template}' but {$path} does not exist"
                );
            }
        }

        self::assertGreaterThan(
            10,
            $checked,
            'expected the scan to find many View::render() calls — the regex may have broken'
        );
    }
}
