<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Database schema contract tests.
 *
 * These run against the real MySQL database (bidii_benz) and SKIP when it is
 * unavailable, so the unit suite still passes on machines without MySQL.
 */
final class SchemaTest extends TestCase
{
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
    }

    public function testAllExpectedTablesExist(): void
    {
        $expected = [
            'users', 'clients', 'cars', 'bookings',
            'booking_days', 'payments', 'audit_logs', 'login_attempts',
        ];
        $actual = self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expected as $table) {
            $this->assertContains($table, $actual, "Missing table: {$table}");
        }
    }

    public function testAllMigrationsAreRecorded(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []);
        $applied = self::$pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($files as $file) {
            $this->assertContains($file, $applied, "Unapplied migration: {$file}");
        }
    }

    public function testDoubleBookingIsRejectedByPrimaryKey(): void
    {
        // Occupy one day for a car, then try to claim the same car+day again.
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        self::$pdo->exec('DELETE FROM booking_days WHERE car_id = 99991');
        self::$pdo->exec('INSERT INTO booking_days (car_id, day, booking_id) VALUES (99991, "2099-01-01", 99991)');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry/');
        try {
            self::$pdo->exec('INSERT INTO booking_days (car_id, day, booking_id) VALUES (99991, "2099-01-01", 99991)');
        } finally {
            self::$pdo->exec('DELETE FROM booking_days WHERE car_id = 99991');
            self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function testBookingDateCheckConstraintRejectsInvalidRange(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/chk_bookings_dates/');
        try {
            self::$pdo->exec(
                'INSERT INTO bookings (booking_ref, client_id, car_id, pickup_date, return_date, daily_rate, total_amount)
                 VALUES ("BB-CHK-TEST", 99991, 99991, "2099-02-01", "2099-01-01", 1, 1)'
            );
        } finally {
            self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function testDuplicateRegistrationPlateIsRejected(): void
    {
        self::$pdo->exec('DELETE FROM cars WHERE registration_plate = "ZZZ-UNIQUE-PLATE"');
        self::$pdo->exec(
            'INSERT INTO cars (model, registration_plate, daily_price, seats)
             VALUES ("Fake", "ZZZ-UNIQUE-PLATE", 1, 5)'
        );
        try {
            $this->expectException(\PDOException::class);
            $this->expectExceptionMessageMatches('/uq_cars_plate/');
            self::$pdo->exec(
                'INSERT INTO cars (model, registration_plate, daily_price, seats)
                 VALUES ("Fake", "ZZZ-UNIQUE-PLATE", 1, 5)'
            );
        } finally {
            self::$pdo->exec('DELETE FROM cars WHERE registration_plate = "ZZZ-UNIQUE-PLATE"');
        }
    }

    public function testSeedDataCountsArePlausible(): void
    {
        $cars = (int) self::$pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn();
        $users = (int) self::$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        // Seeded fixture: at least the demo fleet and demo accounts exist.
        $this->assertGreaterThanOrEqual(7, $cars, 'Seed cars missing — run: php scripts/seed.php');
        $this->assertGreaterThanOrEqual(6, $users, 'Seed users missing — run: php scripts/seed.php');
    }

    public function testNoOverlappingBookingDaysExist(): void
    {
        $overlaps = (int) self::$pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT car_id, day FROM booking_days
                GROUP BY car_id, day HAVING COUNT(DISTINCT booking_id) > 1
             ) t'
        )->fetchColumn();
        $this->assertSame(0, $overlaps, 'Two bookings occupy the same car day.');
    }
}
