<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * Registration validation — pure logic, no database.
 */
final class AuthServiceTest extends TestCase
{
    /** @param array<string, string> $overrides */
    private function validate(array $overrides = []): array
    {
        $data = array_merge([
            'full_name' => 'John Kamau',
            'email' => 'john@example.com',
            'phone' => '0712345678',
            'id_number' => '12345678',
            'password' => 'Secret123',
            'confirm' => 'Secret123',
        ], $overrides);

        return AuthService::validateRegistration(
            $data['full_name'],
            $data['email'],
            $data['phone'],
            $data['id_number'],
            $data['password'],
            $data['confirm']
        );
    }

    public function testValidRegistrationPasses(): void
    {
        self::assertSame([], $this->validate());
    }

    public function testValidPhoneWithCountryCodePasses(): void
    {
        self::assertSame([], $this->validate(['phone' => '+254712345678']));
    }

    public function testTooShortNameFails(): void
    {
        $errors = $this->validate(['full_name' => 'Jo']);
        self::assertArrayHasKey('full_name', $errors);
    }

    public function testInvalidEmailFails(): void
    {
        $errors = $this->validate(['email' => 'not-an-email']);
        self::assertArrayHasKey('email', $errors);
    }

    /**
     * @dataProvider invalidPhones
     */
    public function testInvalidPhoneFails(string $phone): void
    {
        $errors = $this->validate(['phone' => $phone]);
        self::assertArrayHasKey('phone', $errors, "phone {$phone} must be rejected");
    }

    /** @return array<string, array{0: string}> */
    public static function invalidPhones(): array
    {
        return [
            'too short' => ['0712345'],
            'wrong prefix' => ['0812345678'],
            'landline' => ['020123456'],
            'empty' => [''],
            'letters' => ['07abc34567'],
            'bad country code' => ['+255712345678'],
        ];
    }

    public function testNonNumericIdFails(): void
    {
        $errors = $this->validate(['id_number' => 'ABC12345']);
        self::assertArrayHasKey('id_number', $errors);
    }

    public function testTooShortIdFails(): void
    {
        $errors = $this->validate(['id_number' => '123456']);
        self::assertArrayHasKey('id_number', $errors);
    }

    public function testShortPasswordFails(): void
    {
        $errors = $this->validate(['password' => 'Ab1!', 'confirm' => 'Ab1!']);
        self::assertArrayHasKey('password', $errors);
    }

    public function testOverlongPasswordFails(): void
    {
        // bcrypt truncates at 72 bytes — must be rejected, not silently truncated.
        $long = str_repeat('a', 73);
        $errors = $this->validate(['password' => $long, 'confirm' => $long]);
        self::assertArrayHasKey('password', $errors);
    }

    public function testMismatchedConfirmationFails(): void
    {
        $errors = $this->validate(['confirm' => 'Different1']);
        self::assertArrayHasKey('confirm_password', $errors);
    }
}
