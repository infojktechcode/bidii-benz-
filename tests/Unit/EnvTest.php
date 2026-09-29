<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testParsesSimpleKeyValuePairs(): void
    {
        $vars = Env::parse("APP_ENV=development\nDB_NAME=bidii_benz\n");
        self::assertSame('development', $vars['APP_ENV']);
        self::assertSame('bidii_benz', $vars['DB_NAME']);
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $vars = Env::parse("# comment\n\nAPP_ENV=production\n");
        self::assertSame(['APP_ENV' => 'production'], $vars);
    }

    public function testHandlesQuotedValuesAndWhitespace(): void
    {
        $vars = Env::parse('APP_NAME="Bidii Benz Rentals"');
        self::assertSame('Bidii Benz Rentals', $vars['APP_NAME']);
    }

    public function testRejectsInvalidKeysAndLinesWithoutEquals(): void
    {
        $vars = Env::parse("NOT A PAIR\n1BAD=value\nGOOD=1\n");
        self::assertSame(['GOOD' => '1'], $vars);
    }

    public function testLoadReturnsEmptyArrayForMissingFile(): void
    {
        self::assertSame([], Env::load(__DIR__ . '/does-not-exist.env'));
    }
}
