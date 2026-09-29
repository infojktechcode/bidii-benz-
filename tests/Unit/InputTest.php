<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testStringIsTrimmedAndLengthCapped(): void
    {
        $input = Input::fromArray(['name' => '  John Doe  ']);
        self::assertSame('John Doe', $input->string('name'));

        $long = Input::fromArray(['name' => str_repeat('a', 300)]);
        self::assertSame(255, strlen($long->string('name')));
    }

    public function testStringNeverReturnsArrays(): void
    {
        $input = Input::fromArray(['name' => ['injection']]);
        self::assertSame('', $input->string('name'));
    }

    public function testIntRejectsNonNumeric(): void
    {
        self::assertSame(42, Input::fromArray(['id' => '42'])->int('id'));
        self::assertSame(-7, Input::fromArray(['id' => '-7'])->int('id'));
        self::assertNull(Input::fromArray(['id' => '42abc'])->int('id'));
        self::assertNull(Input::fromArray(['id' => '1e3'])->int('id'));
        self::assertNull(Input::fromArray(['id' => ['1']])->int('id'));
        self::assertNull(Input::fromArray([])->int('id'));
    }

    public function testEmailValidation(): void
    {
        self::assertSame('a@b.co', Input::fromArray(['email' => 'a@b.co'])->email('email'));
        self::assertSame('', Input::fromArray(['email' => 'not-an-email'])->email('email'));
        self::assertSame('', Input::fromArray(['email' => 'a@b'])->email('email'));
    }

    public function testDateValidationRejectsCalendarInvalidDates(): void
    {
        self::assertSame('2026-10-31', Input::fromArray(['d' => '2026-10-31'])->date('d'));
        self::assertNull(Input::fromArray(['d' => '2026-02-31'])->date('d'), 'Feb 31 must be rejected');
        self::assertNull(Input::fromArray(['d' => '31/10/2026'])->date('d'));
        self::assertNull(Input::fromArray(['d' => '2026-1-1'])->date('d'));
    }

    public function testKenyanPhoneFormats(): void
    {
        foreach (['0712345678', '0110123456', '+254712345678', '+254110123456', '0712 345 678'] as $ok) {
            self::assertSame(
                str_replace([' ', '-'], '', $ok),
                Input::fromArray(['p' => $ok])->phone('p'),
                "phone $ok should be accepted"
            );
        }
        foreach (['12345', '0812345678', '+254712345', '07123456789', 'abc'] as $bad) {
            self::assertSame('', Input::fromArray(['p' => $bad])->phone('p'), "phone $bad should be rejected");
        }
    }

    public function testValidateRequiredMissingProducesError(): void
    {
        $errors = Input::fromArray([])->validate(['email' => 'required|email']);
        self::assertArrayHasKey('email', $errors);
    }

    public function testValidateOptionalEmptyIsAccepted(): void
    {
        $errors = Input::fromArray([])->validate(['phone' => 'optional|phone']);
        self::assertSame([], $errors);
    }

    public function testValidateTypeFailures(): void
    {
        $errors = Input::fromArray([
            'email' => 'nope',
            'pickup' => '2026-02-31',
            'seats' => 'five',
        ])->validate([
            'email' => 'required|email',
            'pickup' => 'required|date',
            'seats' => 'required|int',
        ]);
        self::assertCount(3, $errors);
    }

    public function testValidatePassesOnGoodInput(): void
    {
        $errors = Input::fromArray([
            'email' => 'client@example.com',
            'pickup' => '2026-10-01',
            'return' => '2026-10-05',
            'phone' => '0712345678',
        ])->validate([
            'email' => 'required|email',
            'pickup' => 'required|date',
            'return' => 'required|date',
            'phone' => 'required|phone',
        ]);
        self::assertSame([], $errors);
    }

    public function testOnlyRequestedKeysAreRead(): void
    {
        $input = Input::fromArray(['name' => 'John', 'admin' => '1']);
        self::assertTrue($input->has('admin'), 'raw access exists for explicit reads');
        self::assertNull($input->raw('role'), 'unrequested keys are null, not leaked');
    }
}
