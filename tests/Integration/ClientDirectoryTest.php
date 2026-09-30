<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\CreatesRentalFixture;

/**
 * Admin client management (Phase 7, Category A): the directory lists every
 * client with lifetime totals, the detail screen resolves a profile, and an
 * unknown id resolves to null so the controller can answer 404.
 *
 * Skips when MySQL is unavailable.
 */
final class ClientDirectoryTest extends TestCase
{
    use CreatesRentalFixture;

    private static ?PDO $pdo = null;

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
        $this->createRentalFixture(self::$pdo);
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->destroyRentalFixture(self::$pdo);
        }
    }

    public function testDirectoryListsEveryClientWithTotals(): void
    {
        $rows = (new UserRepository(self::$pdo))->findAllClients();

        self::assertNotEmpty($rows, 'the seeded clients must be listed');
        $match = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $this->fixtureClientId()) {
                $match = $row;
            }
        }

        self::assertNotNull($match, 'the fixture client must appear in the directory');
        self::assertSame('Fixture Client', $match['full_name']);
        self::assertNotEmpty((string) $match['email'], 'contact details drive the directory');
        self::assertArrayHasKey('booking_count', $match);
        self::assertArrayHasKey('total_paid', $match);
        self::assertSame(0, (int) $match['booking_count'], 'the fixture client has no bookings yet');
        self::assertEquals(0.0, (float) $match['total_paid']);
    }

    public function testClientProfileResolvesContactDetails(): void
    {
        $client = (new UserRepository(self::$pdo))->findClient($this->fixtureClientId());

        self::assertNotNull($client);
        self::assertSame('Fixture Client', $client['full_name']);
        self::assertNotEmpty((string) $client['email'], 'the profile is joined to its users row');
        self::assertSame('active', $client['status'], 'the account state is part of the profile');
        self::assertNotEmpty((string) $client['created_at']);
    }

    public function testUnknownClientResolvesToNull(): void
    {
        self::assertNull((new UserRepository(self::$pdo))->findClient(99999999));
    }
}
