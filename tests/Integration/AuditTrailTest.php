<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 audit trail (§10): privileged actions are recorded, the payload is
 * bounded, an audit failure can never break the business action, and the table
 * has nowhere to leak a password, token or API credential into.
 *
 * Skips when MySQL is unavailable.
 */
final class AuditTrailTest extends TestCase
{
    private const ACTION = 'phase6.audit.test';

    private static ?PDO $pdo = null;
    private static int $actorUserId = 0;

    /** @var array<string, mixed> */
    private array $savedSession = [];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = Database::connect(Config::load(dirname(__DIR__, 2)));
        } catch (\Throwable) {
            self::$pdo = null;
        }
        if (self::$pdo !== null) {
            // A real account: audit_logs.user_id is a foreign key, so a made-up
            // id would fail for the wrong reason.
            self::$actorUserId = (int) self::$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('MySQL not available.');
        }
        $this->savedSession = $_SESSION;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        if (self::$pdo === null) {
            return;
        }
        self::$pdo->prepare('DELETE FROM audit_logs WHERE action LIKE ?')
            ->execute([self::ACTION . '%']);
    }

    public function testLogWritesTheActorActionAndEntity(): void
    {
        $ok = Audit::log(self::$actorUserId, 'staff', self::ACTION, 'bookings', 42, 'BB-TEST-1', '127.0.0.1');

        self::assertTrue($ok);
        $row = $this->onlyRow();
        self::assertSame(self::$actorUserId, (int) $row['user_id']);
        self::assertSame('staff', (string) $row['role']);
        self::assertSame(self::ACTION, (string) $row['action']);
        self::assertSame('bookings', (string) $row['entity']);
        self::assertSame(42, (int) $row['entity_id']);
        self::assertSame('BB-TEST-1', (string) $row['detail']);
        self::assertSame('127.0.0.1', (string) $row['ip_address']);
        self::assertNotNull($row['created_at']);
    }

    public function testLongDetailsAndActionsAreClipped(): void
    {
        Audit::log(self::$actorUserId, 'staff', self::ACTION . '.long', 'bookings', 1, str_repeat('x', 900), '127.0.0.1');

        $row = $this->onlyRow();
        self::assertLessThanOrEqual(500, strlen((string) $row['detail']), 'detail is capped at 500 chars');
        self::assertLessThanOrEqual(60, strlen((string) $row['action']), 'action is capped at 60 chars');
    }

    public function testAnAuditFailureNeverBreaksTheBusinessAction(): void
    {
        // user_id 99999999 does not exist -> FK 1452. The caller must not see
        // an exception: the audited action has already happened.
        $ok = Audit::log(99999999, 'staff', self::ACTION . '.broken', 'bookings', 1, 'x', '127.0.0.1');

        self::assertFalse($ok, 'the write must be reported as failed');
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ?');
        $stmt->execute([self::ACTION . '.broken']);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAsCurrentActorRefusesWhenNobodyIsSignedIn(): void
    {
        $ok = Audit::asCurrentActor(self::ACTION . '.anon', 'bookings', 1, 'x');

        self::assertFalse($ok, 'an anonymous request must never be recorded as a real actor');
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ?');
        $stmt->execute([self::ACTION . '.anon']);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAsCurrentActorRecordsTheSessionActor(): void
    {
        Session::set('user_id', self::$actorUserId);
        Session::set('role', 'owner');

        self::assertTrue(Audit::asCurrentActor(self::ACTION . '.session', 'payments', 7, 'receipt=ABC'));

        $row = $this->onlyRow();
        self::assertSame(self::$actorUserId, (int) $row['user_id']);
        self::assertSame('owner', (string) $row['role']);
        self::assertSame('payments', (string) $row['entity']);
        self::assertSame(7, (int) $row['entity_id']);
        self::assertNotEmpty((string) $row['ip_address']);
    }

    public function testTheAuditTableHasNowhereToStoreASecret(): void
    {
        $fields = array_map(
            static fn (array $row): string => (string) $row['Field'],
            self::$pdo->query('DESCRIBE audit_logs')->fetchAll()
        );

        self::assertSame(
            ['id', 'user_id', 'role', 'action', 'entity', 'entity_id', 'ip_address', 'detail', 'created_at'],
            $fields,
            'the audit schema is fixed: adding a secret-bearing column requires a schema change review'
        );

        foreach ($fields as $field) {
            self::assertDoesNotMatchRegularExpression(
                '/pass|token|secret|key|credential|pin/i',
                $field,
                'audit_logs must never gain a credential column'
            );
        }
    }

    public function testFailedLoginAuditsCarryNoPasswordMaterial(): void
    {
        // Real rows written by the login flow: assert what is stored is only
        // the identifier, never the submitted password.
        $stmt = self::$pdo->query(
            "SELECT action, detail FROM audit_logs WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 5"
        );
        $rows = $stmt->fetchAll();
        self::assertIsArray($rows, 'the login-failure audit rows must be readable');
        foreach ($rows as $row) {
            self::assertStringNotContainsString('password=', (string) $row['detail']);
            self::assertStringNotContainsString('password:', (string) $row['detail']);
            self::assertLessThanOrEqual(500, strlen((string) $row['detail']));
        }
    }

    /** @return array<string, mixed> */
    private function onlyRow(): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM audit_logs WHERE action LIKE ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::ACTION . '%']);
        $row = $stmt->fetch();
        self::assertNotFalse($row, 'an audit row must have been written');
        return $row;
    }
}
