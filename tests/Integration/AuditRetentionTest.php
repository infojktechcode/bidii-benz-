<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AuditRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12 audit retention (owner policy: 12 months, Kenya DPA storage
 * limitation): pruneBefore removes only rows older than the cutoff,
 * countBefore reports the same set without deleting (the --dry-run path),
 * and a table with only young rows is a no-op.
 *
 * Skips when MySQL is unavailable.
 */
final class AuditRetentionTest extends TestCase
{
    private const ACTION = 'phase12.retention.test';

    private static ?PDO $pdo = null;
    private static ?AuditRepository $audit = null;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::connect(Config::load(dirname(__DIR__, 2)));
        } catch (\Throwable) {
            self::$pdo = null;
        }
        if (self::$pdo !== null) {
            self::$audit = new AuditRepository(self::$pdo);
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null || self::$audit === null) {
            $this->markTestSkipped('MySQL not available.');
        }
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->clear();
        }
    }

    public function testPruneDeletesOnlyRowsOlderThanTheCutoff(): void
    {
        $this->insert((new \DateTimeImmutable('-13 months'))->format('Y-m-d H:i:s'));
        $this->insert((new \DateTimeImmutable('-6 months'))->format('Y-m-d H:i:s'));
        $this->insert(date('Y-m-d H:i:s'));

        $deleted = self::$audit->pruneBefore($this->cutoff());

        self::assertSame(1, $deleted, 'only the 13-month-old row is past the window');
        self::assertSame(2, $this->remaining(), 'the young rows survive');
    }

    public function testCountBeforeReportsTheSameSetWithoutDeleting(): void
    {
        $this->insert((new \DateTimeImmutable('-14 months'))->format('Y-m-d H:i:s'));
        $this->insert(date('Y-m-d H:i:s'));

        self::assertSame(1, self::$audit->countBefore($this->cutoff()), 'dry run reports the old row');
        self::assertSame(2, $this->remaining(), 'dry run deletes nothing');

        self::assertSame(1, self::$audit->pruneBefore($this->cutoff()));
        self::assertSame(1, $this->remaining());
    }

    public function testPruneIsANoOpWhenNothingIsOlder(): void
    {
        $this->insert(date('Y-m-d H:i:s'));

        self::assertSame(0, self::$audit->countBefore($this->cutoff()));
        self::assertSame(0, self::$audit->pruneBefore($this->cutoff()));
        self::assertSame(1, $this->remaining());
    }

    private function cutoff(): string
    {
        return (new \DateTimeImmutable('-12 months'))->format('Y-m-d H:i:s');
    }

    private function insert(string $createdAt): void
    {
        self::$pdo->prepare(
            'INSERT INTO audit_logs (user_id, role, action, entity, entity_id, ip_address, detail, created_at)
             VALUES (NULL, "system", ?, "bookings", 1, "127.0.0.1", "retention fixture", ?)'
        )->execute([self::ACTION, $createdAt]);
    }

    private function remaining(): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ?');
        $stmt->execute([self::ACTION]);
        return (int) $stmt->fetchColumn();
    }

    private function clear(): void
    {
        self::$pdo->prepare('DELETE FROM audit_logs WHERE action = ?')
            ->execute([self::ACTION]);
    }
}
