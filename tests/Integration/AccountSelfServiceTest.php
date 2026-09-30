<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Account self-service against the real database: profile edits and the
 * password change. Skips when MySQL is unavailable.
 */
final class AccountSelfServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AuthService $auth;
    private UserRepository $users;

    /** @var list<string> Emails of accounts created by the test. */
    private array $cleanupEmails = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::connect(Config::load(dirname(__DIR__, 2)));
        } catch (\Throwable) {
            self::$pdo = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MySQL not available.');
        }
        $this->users = new UserRepository(self::$pdo);
        $this->auth = new AuthService($this->users);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupEmails as $email) {
            self::$pdo->prepare('DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE email = ?)')
                ->execute([$email]);
            self::$pdo->prepare('DELETE FROM clients WHERE user_id IN (SELECT id FROM users WHERE email = ?)')
                ->execute([$email]);
            self::$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
        }
        $this->cleanupEmails = [];
    }

    /**
     * @param array<string, string> $overrides
     * @return array{user_id: int, email: string, phone: string}
     */
    private function createClient(array $overrides = []): array
    {
        $email = 'accttest-' . bin2hex(random_bytes(6)) . '@example.test';
        $phone = '07' . random_int(10000000, 99999999);
        $this->cleanupEmails[] = $email;

        $result = $this->auth->register([
            'email' => $overrides['email'] ?? $email,
            'phone' => $overrides['phone'] ?? $phone,
            'password_hash' => password_hash('AccountOld123!', PASSWORD_DEFAULT),
            'full_name' => $overrides['full_name'] ?? 'Account Test Client',
            'id_number' => $overrides['id_number'] ?? (string) random_int(100000000, 999999999),
            'city' => $overrides['city'] ?? 'Nairobi',
        ]);
        self::assertTrue($result['ok'], 'fixture registration must succeed: ' . json_encode($result['errors'] ?? []));

        return [
            'user_id' => (int) $result['user_id'],
            'email' => $overrides['email'] ?? $email,
            'phone' => $overrides['phone'] ?? $phone,
        ];
    }

    // --- profile -------------------------------------------------------------

    public function testProfileValidationAcceptsTypicalKenyanData(): void
    {
        self::assertSame(
            [],
            AuthService::validateProfile('Jane Wanjiku', '0712345678', 'Nairobi')
        );
        self::assertSame(
            [],
            AuthService::validateProfile('Jane Wanjiku', '+254712345678', ''),
            'an empty city is allowed'
        );
    }

    public function testProfileValidationRejectsBadInput(): void
    {
        $errors = AuthService::validateProfile('J', '0712345678', 'Nairobi');
        self::assertArrayHasKey('full_name', $errors);

        $errors = AuthService::validateProfile('Jane Wanjiku', '12345', 'Nairobi');
        self::assertArrayHasKey('phone', $errors);

        $errors = AuthService::validateProfile(
            'Jane Wanjiku',
            '0712345678',
            str_repeat('X', 81)
        );
        self::assertArrayHasKey('city', $errors);
    }

    public function testProfileUpdatePersistsNamePhoneAndCity(): void
    {
        $client = $this->createClient();
        $newPhone = '07' . random_int(10000000, 99999999);

        $result = $this->auth->updateProfile($client['user_id'], 'Jane Wanjiku', $newPhone, 'Thika');
        self::assertTrue($result['ok'], json_encode($result['errors']));

        $profile = $this->users->findProfileByUserId($client['user_id']);
        self::assertNotNull($profile);
        self::assertSame('Jane Wanjiku', $profile['full_name']);
        self::assertSame($newPhone, $profile['phone']);
        self::assertSame('Thika', $profile['city']);
    }

    public function testProfileUpdateRefusesAPhoneOwnedByAnotherClient(): void
    {
        $a = $this->createClient();
        $b = $this->createClient();

        $result = $this->auth->updateProfile($a['user_id'], 'Account Test Client', $b['phone'], 'Nairobi');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('phone', $result['errors']);

        $profile = $this->users->findProfileByUserId($a['user_id']);
        self::assertNotNull($profile);
        self::assertNotSame(
            $b['phone'],
            (string) $profile['phone'],
            'the other client\'s phone must never be adopted'
        );
        self::assertSame($a['phone'], (string) $profile['phone'], 'the original phone stays in place');
    }

    public function testProfileUpdateRefusesValidationErrorsWithoutWriting(): void
    {
        $client = $this->createClient();
        $before = $this->users->findProfileByUserId($client['user_id']);

        $result = $this->auth->updateProfile($client['user_id'], 'X', 'not-a-phone', 'Nairobi');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('full_name', $result['errors']);
        self::assertArrayHasKey('phone', $result['errors']);

        $after = $this->users->findProfileByUserId($client['user_id']);
        self::assertSame($before, $after, 'a refused update must not write anything');
    }

    public function testProfileUpdateAllowsKeepingYourOwnPhone(): void
    {
        $client = $this->createClient();
        $result = $this->auth->updateProfile($client['user_id'], 'Same Phone Person', $client['phone'], 'Nairobi');
        self::assertTrue($result['ok'], json_encode($result['errors']));
    }

    public function testProfileUpdateMapsAUniquePhoneCollisionToAFieldError(): void
    {
        // A soft-deleted row still owns the phone under uq_users_phone, but
        // findByPhone() filters deleted_at — the visibility gap the pre-check
        // cannot see (the same shape as a genuine concurrent update). This
        // used to escape as an uncaught PDOException (500 page); it must
        // come back as a field error instead.
        $client = $this->createClient();
        do {
            $taken = '0712' . random_int(100000, 999999);
        } while ($this->users->findByPhone($taken) !== null);

        $ghostEmail = 'acctghost-' . bin2hex(random_bytes(6)) . '@example.test';
        $this->cleanupEmails[] = $ghostEmail;
        self::$pdo->prepare(
            'INSERT INTO users (role, email, phone, password_hash, status, deleted_at)
             VALUES ("client", ?, ?, "unused", "active", NOW())'
        )->execute([$ghostEmail, $taken]);

        self::assertNull(
            $this->users->findByPhone($taken),
            'the holder must be invisible to the pre-check, or this tests nothing'
        );

        $result = $this->auth->updateProfile($client['user_id'], 'Account Test Client', $taken, 'Nairobi');
        self::assertFalse($result['ok']);
        self::assertSame('That phone number is already in use.', $result['errors']['phone'] ?? '');

        $profile = $this->users->findProfileByUserId($client['user_id']);
        self::assertNotNull($profile);
        self::assertSame(
            $client['phone'],
            (string) $profile['phone'],
            'the collision must leave the client\'s phone untouched'
        );
    }

    // --- password change -----------------------------------------------------

    public function testPasswordChangeRejectsWrongCurrentPassword(): void
    {
        $client = $this->createClient();

        $result = $this->auth->changePassword($client['user_id'], 'TotallyWrong1!', 'BrandNew123!', 'BrandNew123!');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('current_password', $result['errors']);

        $user = $this->users->findById($client['user_id']);
        self::assertNotNull($user);
        self::assertTrue(
            password_verify('AccountOld123!', (string) $user['password_hash']),
            'a refused change must leave the stored hash untouched'
        );
    }

    public function testPasswordChangeRejectsShortOrMismatchedNewPassword(): void
    {
        $client = $this->createClient();

        $result = $this->auth->changePassword($client['user_id'], 'AccountOld123!', 'short', 'short');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('password', $result['errors']);

        $result = $this->auth->changePassword($client['user_id'], 'AccountOld123!', 'BrandNew123!', 'Different123!');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('confirm_password', $result['errors']);
    }

    public function testPasswordChangeRejectsReusingTheCurrentPassword(): void
    {
        $client = $this->createClient();

        $result = $this->auth->changePassword($client['user_id'], 'AccountOld123!', 'AccountOld123!', 'AccountOld123!');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('password', $result['errors']);
    }

    public function testPasswordChangePersistsAndInvalidatesTheOldPassword(): void
    {
        $client = $this->createClient();

        $result = $this->auth->changePassword($client['user_id'], 'AccountOld123!', 'BrandNew123!', 'BrandNew123!');
        self::assertTrue($result['ok'], json_encode($result['errors']));

        $user = $this->users->findById($client['user_id']);
        self::assertNotNull($user);
        self::assertTrue(password_verify('BrandNew123!', (string) $user['password_hash']));
        self::assertFalse(password_verify('AccountOld123!', (string) $user['password_hash']));

        // The old password must also stop working through the real login path.
        $login = $this->auth->login($client['email'], 'AccountOld123!', '127.0.0.1');
        self::assertFalse($login['ok']);
        $login = $this->auth->login($client['email'], 'BrandNew123!', '127.0.0.1');
        self::assertTrue($login['ok'], (string) ($login['error'] ?? ''));

        // Failed login attempts recorded by the check above must not linger.
        self::$pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$client['email']]);
    }

    // --- throttle ------------------------------------------------------------

    public function testPasswordChangeThrottleFiresAtTheConfiguredLimit(): void
    {
        $now = 10000;
        $max = RateLimiter::MAX_PASSWORD_CHANGES_PER_WINDOW;

        $under = array_fill(0, $max - 1, $now - 10);
        self::assertFalse(RateLimiter::overLimit($under, $max, $now));

        $atLimit = array_fill(0, $max, $now - 10);
        self::assertTrue(RateLimiter::overLimit($atLimit, $max, $now));

        // Attempts that aged out of the 900s window stop counting.
        $stale = array_fill(0, $max, $now - 901);
        self::assertFalse(RateLimiter::overLimit($stale, $max, $now));
    }
}
