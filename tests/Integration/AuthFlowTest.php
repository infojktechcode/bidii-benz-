<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Authentication flows against the real database.
 * Skips when MySQL is unavailable.
 */
final class AuthFlowTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AuthService $auth;
    private UserRepository $users;

    /** @var list<string> Emails of accounts created by the test. */
    private array $cleanupEmails = [];

    /**
     * Every identifier that may have produced a login_attempts row
     * (emails, phone numbers, unknown-account probes).
     *
     * @var list<string>
     */
    private array $cleanupIdentifiers = [];

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
        // Delete attempts by EVERY identifier used (not just account emails),
        // otherwise leftover failures accumulate toward the per-IP block and
        // break subsequent runs.
        if ($this->cleanupIdentifiers !== []) {
            $placeholders = implode(',', array_fill(0, count($this->cleanupIdentifiers), '?'));
            self::$pdo->prepare("DELETE FROM login_attempts WHERE identifier IN ($placeholders)")
                ->execute($this->cleanupIdentifiers);
        }
        foreach ($this->cleanupEmails as $email) {
            self::$pdo->prepare('DELETE FROM audit_logs WHERE user_id IN (SELECT id FROM users WHERE email = ?)')
                ->execute([$email]);
            self::$pdo->prepare('DELETE FROM clients WHERE user_id IN (SELECT id FROM users WHERE email = ?)')
                ->execute([$email]);
            self::$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
        }
        $this->cleanupEmails = [];
        $this->cleanupIdentifiers = [];
    }

    private function uniqueEmail(): string
    {
        $email = 'authtest-' . bin2hex(random_bytes(6)) . '@example.test';
        $this->cleanupEmails[] = $email;
        $this->cleanupIdentifiers[] = $email;
        return $email;
    }

    /** A probe address for an account that will never exist. */
    private function unknownIdentifier(): string
    {
        $id = 'nobody-' . bin2hex(random_bytes(5)) . '@example.test';
        $this->cleanupIdentifiers[] = $id;
        return $id;
    }

    /** @return array{email:string, phone:string, password_hash:string, full_name:string, id_number:string} */
    private function newRegistration(string $email): array
    {
        $phone = '07' . random_int(10000000, 99999999);
        $this->cleanupIdentifiers[] = $phone;
        return [
            'email' => $email,
            'phone' => $phone,
            'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'full_name' => 'Test Client',
            'id_number' => (string) random_int(10000000, 99999999),
        ];
    }

    public function testRegisterCreatesHashedPasswordAndProfile(): void
    {
        $email = $this->uniqueEmail();
        $data = $this->newRegistration($email);

        $result = $this->auth->register($data);
        self::assertTrue($result['ok'], 'registration should succeed');

        $user = $this->users->findByEmail($email);
        self::assertNotNull($user);
        self::assertSame('client', $user['role']);
        self::assertNotSame('Secret123', $user['password_hash'], 'password must never be stored in clear');
        self::assertTrue(password_verify('Secret123', $user['password_hash']));
        self::assertNotFalse(str_starts_with($user['password_hash'], '$2y$'), 'bcrypt prefix expected');

        $stmt = self::$pdo->prepare('SELECT full_name FROM clients WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        self::assertSame('Test Client', $stmt->fetchColumn());
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);

        $again = $this->auth->register($this->newRegistration($email));
        self::assertFalse($again['ok']);
        self::assertArrayHasKey('email', $again['errors']);
    }

    public function testDuplicateIdNumberIsRejected(): void
    {
        $email1 = $this->uniqueEmail();
        $email2 = $this->uniqueEmail();
        $first = $this->newRegistration($email1);
        $idNumber = $first['id_number'];

        self::assertTrue($this->auth->register($first)['ok']);

        $second = $this->newRegistration($email2);
        $second['id_number'] = $idNumber;
        $result = $this->auth->register($second);
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('id_number', $result['errors']);
    }

    public function testLoginSucceedsWithCorrectPassword(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);

        $result = $this->auth->login($email, 'Secret123', '127.0.0.1');
        self::assertTrue($result['ok']);
        self::assertSame($email, $result['user']['email']);
        self::assertSame('client', $result['user']['role']);
        self::assertNotNull($result['user']['last_login_at'], 'last_login_at must be stamped');
        // Assert against the DATABASE, not just the returned array.
        $stmt = self::$pdo->prepare('SELECT last_login_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        self::assertNotNull($stmt->fetchColumn(), 'users.last_login_at must be persisted');
    }

    public function testLoginAcceptsPhoneIdentifier(): void
    {
        $email = $this->uniqueEmail();
        $data = $this->newRegistration($email);
        self::assertTrue($this->auth->register($data)['ok']);

        $result = $this->auth->login($data['phone'], 'Secret123', '127.0.0.1');
        self::assertTrue($result['ok'], 'phone number must work as login identifier');
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);

        $result = $this->auth->login($email, 'WrongPassword99', '127.0.0.1');
        self::assertFalse($result['ok']);
        self::assertArrayNotHasKey('user', $result, 'no user data may leak on failure');
        self::assertStringContainsString('Invalid', $result['error']);
    }

    public function testLoginFailsForUnknownAccount(): void
    {
        $result = $this->auth->login($this->unknownIdentifier(), 'whatever', '127.0.0.1');
        self::assertFalse($result['ok']);
        // Same message as wrong-password: no account enumeration.
        self::assertStringContainsString('Invalid', $result['error']);
    }

    public function testRateLimitBlocksAfterFiveFailures(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);

        for ($i = 0; $i < 5; $i++) {
            $result = $this->auth->login($email, 'WrongPassword99', '10.0.0.1');
            self::assertFalse($result['ok']);
        }

        // 6th attempt must be blocked BEFORE password verification —
        // even the CORRECT password now fails.
        $result = $this->auth->login($email, 'Secret123', '10.0.0.1');
        self::assertFalse($result['ok']);
        self::assertArrayHasKey('blocked_for', $result, 'blocked response must carry retry info');
        self::assertStringContainsString('Too many', $result['error']);
    }

    public function testSuccessfulLoginClearsPriorFailures(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);

        // 3 wrong attempts (below threshold)
        for ($i = 0; $i < 3; $i++) {
            $this->auth->login($email, 'WrongPassword99', '10.0.0.2');
        }
        // correct password succeeds and must reset the counter
        self::assertTrue($this->auth->login($email, 'Secret123', '10.0.0.2')['ok']);

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND successful = 0'
        );
        $stmt->execute([$email]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'failures must be cleared after success');
    }

    public function testSuspendedAccountCannotLogin(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);
        self::$pdo->prepare('UPDATE users SET status = "suspended" WHERE email = ?')->execute([$email]);

        $result = $this->auth->login($email, 'Secret123', '127.0.0.1');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('suspended', $result['error']);
    }

    public function testPasswordIsRehashedWhenCostIsOutdated(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);

        // Downgrade the stored hash to a weak cost, then log in.
        self::$pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
            ->execute([password_hash('Secret123', PASSWORD_BCRYPT, ['cost' => 4]), $email]);

        self::assertTrue($this->auth->login($email, 'Secret123', '127.0.0.1')['ok']);

        $stmt = self::$pdo->prepare('SELECT password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $hash = (string) $stmt->fetchColumn();

        self::assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT), 'hash must be upgraded on login');
        self::assertTrue(password_verify('Secret123', $hash), 'upgraded hash must still verify');
        self::assertStringNotContainsString('$2y$04$', $hash, 'weak cost 4 must be replaced');
    }

    public function testFailedLoginsAreRecordedForAudit(): void
    {
        $email = $this->uniqueEmail();
        self::assertTrue($this->auth->register($this->newRegistration($email))['ok']);
        $this->auth->login($email, 'WrongPassword99', '10.9.9.9');

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND ip_address = "10.9.9.9" AND successful = 0'
        );
        $stmt->execute([$email]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testLoginPrunesAttemptRowsOlderThanTheWindow(): void
    {
        $stale = $this->unknownIdentifier();
        $fresh = $this->unknownIdentifier();

        self::$pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, successful, attempted_at)
             VALUES (?, "10.9.9.8", 0, DATE_SUB(NOW(), INTERVAL 2 DAY))'
        )->execute([$stale]);
        self::$pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, successful, attempted_at)
             VALUES (?, "10.9.9.8", 0, NOW())'
        )->execute([$fresh]);

        // Any sign-in attempt performs the housekeeping.
        $this->auth->login($this->unknownIdentifier(), 'Whatever123', '127.0.0.1');

        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ?');
        $stmt->execute([$stale]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'rows past the window are dropped');

        $stmt->execute([$fresh]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'in-window rows still count toward the limiter');
    }
}
