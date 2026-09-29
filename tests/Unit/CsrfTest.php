<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsGeneratedOnceAndStable(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();
        self::assertSame($first, $second);
        self::assertSame(64, strlen($first));
    }

    public function testValidTokenPassesValidation(): void
    {
        $value = Csrf::value('booking');
        self::assertTrue(Csrf::validate('booking', $value));
    }

    public function testTokenForDifferentFormFailsValidation(): void
    {
        $value = Csrf::value('booking');
        self::assertFalse(Csrf::validate('login', $value), 'form-bound token must not cross forms');
    }

    public function testForgedOrEmptyTokenFails(): void
    {
        Csrf::token();
        self::assertFalse(Csrf::validate('booking', ''));
        self::assertFalse(Csrf::validate('booking', str_repeat('a', 64)));
        self::assertFalse(Csrf::validate('booking', null));
        self::assertFalse(Csrf::validate('booking', ['array']));
    }

    public function testTokenRotatesAfterRotateCall(): void
    {
        $before = Csrf::value('form');
        Csrf::rotate();
        $after = Csrf::value('form');
        self::assertNotSame($before, $after);
        self::assertFalse(Csrf::validate('form', $before), 'old token must die after rotation');
        self::assertTrue(Csrf::validate('form', $after));
    }

    public function testFieldOutputIsHtmlEscaped(): void
    {
        Session::set('__csrf_token', '"><script>alert(1)</script>');
        $html = Csrf::field('f');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('"><', $html);
        self::assertMatchesRegularExpression('/value="[0-9a-f]{64}"/', $html, 'output must be a hex HMAC, never the raw token');
    }
}
