<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsApplyWithoutEnvFile(): void
    {
        putenv('APP_ENV');
        $config = Config::load(__DIR__ . '/no-such-dir');
        self::assertSame('development', $config->get('app_env'));
        self::assertSame('bidii_benz', $config->get('db.name'));
        self::assertSame(1800, $config->get('session.idle_timeout'));
        self::assertFalse($config->isProduction());
    }

    public function testRealEnvironmentVariableWinsOverDefault(): void
    {
        putenv('APP_ENV=production');
        $config = Config::load(__DIR__ . '/no-such-dir');
        self::assertSame('production', $config->get('app_env'));
        putenv('APP_ENV=testing');
    }

    public function testDotNotationGet(): void
    {
        $config = Config::load(__DIR__ . '/no-such-dir');
        self::assertSame('127.0.0.1', $config->get('db.host'));
        self::assertNull($config->get('db.missing'));
        self::assertSame('fallback', $config->get('db.missing', 'fallback'));
    }

    public function testOverridesWinOverDefaults(): void
    {
        $config = Config::load(__DIR__ . '/no-such-dir', [
            'app_env' => 'production',
            'db' => ['name' => 'prod_db'],
        ]);
        self::assertSame('production', $config->get('app_env'));
        self::assertSame('prod_db', $config->get('db.name'));
        self::assertSame('127.0.0.1', $config->get('db.host'), 'unrelated keys must survive merge');
        self::assertTrue($config->isProduction());
    }

    public function testNeverExposesPasswordThroughAccidentalKey(): void
    {
        $config = Config::load(__DIR__ . '/no-such-dir');
        self::assertIsString($config->get('db.pass'));
    }

    public function testAuditRetentionDefaultsToTwelveMonths(): void
    {
        $config = Config::load(__DIR__ . '/no-such-dir');
        self::assertSame(12, $config->get('audit.retention_months'));

        $config = Config::load(__DIR__ . '/no-such-dir', [
            'audit' => ['retention_months' => 3],
        ]);
        self::assertSame(3, $config->get('audit.retention_months'));
    }
}
