<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The production CSP is `style-src 'self'; script-src 'self'` with no
 * unsafe-inline (public/index.php). That only holds if no view emits
 * inline event handlers, style="" attributes or <style> blocks — guards
 * for SEC-13 (style-src tightening) and SEC-16 (inline handlers blocked
 * in production).
 */
final class CspPolicyTest extends TestCase
{
    private const CSP_PATTERN = '/Content-Security-Policy:\s*([^"]+)"/';

    public function testCspHeaderDeclaresNoUnsafeInlineSources(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertMatchesRegularExpression(self::CSP_PATTERN, $source, 'a CSP header must exist');

        preg_match(self::CSP_PATTERN, $source, $m);
        $policy = (string) ($m[1] ?? '');

        self::assertStringContainsString("style-src 'self'", $policy);
        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringNotContainsString("'unsafe-inline'", $policy);
        self::assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function testViewsEmitNoInlineHandlersOrStyles(): void
    {
        $root = dirname(__DIR__, 2) . '/app/views';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        $violations = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            $where = substr($file->getPathname(), strlen($root) + 1);

            if (preg_match('/\son(?:click|submit|change|load|error|focus|blur|mouseover)\s*=/i', $src)) {
                $violations[] = $where . ': inline event handler';
            }
            if (preg_match('/<style\b/i', $src)) {
                $violations[] = $where . ': <style> block';
            }
            if (preg_match('/\sstyle\s*=\s*[\'"]/i', $src)) {
                $violations[] = $where . ': inline style attribute';
            }
            if (preg_match('/<script(?![^>]*\bsrc\s*=)/i', $src)) {
                $violations[] = $where . ': inline <script> without src';
            }
        }

        self::assertSame(
            [],
            $violations,
            "views must stay compatible with script-src/style-src 'self':\n" . implode("\n", $violations)
        );
    }
}
