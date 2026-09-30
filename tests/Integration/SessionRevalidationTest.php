<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Guard;
use App\Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * SEC-05: the session only remembers what was true at login time, so every
 * request re-checks the account against the users table. A suspended, deleted
 * or role-changed account must lose its session immediately instead of when
 * the cookie expires.
 *
 * Skips when MySQL is unavailable.
 */
final class SessionRevalidationTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private array $savedSession = [];

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
        $this->savedSession = $_SESSION;
        $_SESSION = [];
        $this->createRentalFixture(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
        $_SESSION = $this->savedSession;
    }

    public function testAnonymousSessionIsLeftAlone(): void
    {
        self::assertFalse(Guard::revalidateSession(self::$pdo));
        self::assertNull(Guard::userId());
        self::assertNull(Session::flash('error'), 'visitors must not be told about a session they never had');
    }

    public function testActiveUserWithMatchingRoleKeepsTheSession(): void
    {
        Session::set('user_id', $this->fixtureUserId());
        Session::set('role', 'client');
        Session::set('user_name', 'Fixture Client');

        self::assertFalse(Guard::revalidateSession(self::$pdo));
        self::assertSame($this->fixtureUserId(), Guard::userId());
        self::assertSame('client', Guard::role());
        self::assertSame('Fixture Client', Session::get('user_name'), 'an untouched session keeps its data');
    }

    public function testSuspendedUserIsSignedOutOnTheNextRequest(): void
    {
        Session::set('user_id', $this->fixtureUserId());
        Session::set('role', 'client');

        self::$pdo->prepare('UPDATE users SET status = "suspended" WHERE id = ?')
            ->execute([$this->fixtureUserId()]);

        self::assertTrue(Guard::revalidateSession(self::$pdo));
        self::assertNull(Guard::userId(), 'a suspended account must lose access immediately');
        self::assertNull(Guard::role());
        self::assertNotNull(Session::flash('error'), 'the visitor must be told why they were signed out');
    }

    public function testRoleNoLongerMatchingTheDatabaseIsSignedOut(): void
    {
        // The account in the database is a client; the session claims owner.
        Session::set('user_id', $this->fixtureUserId());
        Session::set('role', 'owner');

        self::assertTrue(Guard::revalidateSession(self::$pdo));
        self::assertNull(Guard::userId(), 'a stale role must never be trusted');
    }

    public function testUserDeletedFromTheDatabaseIsSignedOut(): void
    {
        Session::set('user_id', 99999999);
        Session::set('role', 'client');

        self::assertTrue(Guard::revalidateSession(self::$pdo));
        self::assertNull(Guard::userId());
    }
}
