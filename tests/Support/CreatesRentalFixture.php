<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\CarRepository;
use App\Repositories\UserRepository;
use PDO;

/**
 * Creates and tears down a throw-away vehicle + client pair so booking and
 * payment tests never touch seeded rows.
 *
 * Usage in a TestCase:
 *   setUp()    -> $this->createRentalFixture($pdo);
 *   tearDown() -> $this->destroyRentalFixture($pdo);
 */
trait CreatesRentalFixture
{
    private int $fixtureCarId = 0;
    private int $fixtureUserId = 0;
    private int $fixtureClientId = 0;
    private string $fixtureEmail = '';

    private function createRentalFixture(PDO $pdo): void
    {
        $this->fixtureEmail = 'fixture-' . bin2hex(random_bytes(6)) . '@example.test';
        $phone = '07' . random_int(10000000, 99999999);

        $this->fixtureUserId = (new UserRepository($pdo))->createClient([
            'email' => $this->fixtureEmail,
            'phone' => $phone,
            'password_hash' => password_hash('Fixture123', PASSWORD_DEFAULT),
            'full_name' => 'Fixture Client',
            'id_number' => (string) random_int(10000000, 99999999),
            'city' => 'Kitengela',
        ]);

        $stmt = $pdo->prepare('SELECT id FROM clients WHERE user_id = ?');
        $stmt->execute([$this->fixtureUserId]);
        $this->fixtureClientId = (int) $stmt->fetchColumn();

        $this->fixtureCarId = (new CarRepository($pdo))->create([
            'make' => 'Mercedes-Benz',
            'model' => 'Fixture C-Class',
            'year' => 2024,
            'body_type' => 'Sedan',
            'seats' => 5,
            'transmission' => 'automatic',
            'fuel_type' => 'petrol',
            'registration_plate' => 'FIX' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
            'daily_price' => 5000.00,
            'status' => 'active',
            'description' => 'Throw-away vehicle created by an automated test.',
        ]);
    }

    private function destroyRentalFixture(PDO $pdo): void
    {
        if ($this->fixtureCarId === 0 && $this->fixtureUserId === 0) {
            return;
        }

        if ($this->fixtureClientId > 0) {
            $pdo->prepare('DELETE FROM payments WHERE client_id = ?')
                ->execute([$this->fixtureClientId]);
        }
        if ($this->fixtureCarId > 0 || $this->fixtureClientId > 0) {
            $pdo->prepare('DELETE FROM bookings WHERE car_id = ? OR client_id = ?')
                ->execute([$this->fixtureCarId, $this->fixtureClientId]);
        }
        if ($this->fixtureUserId > 0) {
            $pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')
                ->execute([$this->fixtureUserId]);
        }
        if ($this->fixtureClientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')
                ->execute([$this->fixtureClientId]);
        }
        if ($this->fixtureUserId > 0) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')
                ->execute([$this->fixtureUserId]);
        }
        if ($this->fixtureCarId > 0) {
            $pdo->prepare('DELETE FROM cars WHERE id = ?')
                ->execute([$this->fixtureCarId]);
        }

        $this->fixtureCarId = 0;
        $this->fixtureUserId = 0;
        $this->fixtureClientId = 0;
        $this->fixtureEmail = '';
    }

    protected function fixtureCarId(): int
    {
        return $this->fixtureCarId;
    }

    protected function fixtureClientId(): int
    {
        return $this->fixtureClientId;
    }

    protected function fixtureUserId(): int
    {
        return $this->fixtureUserId;
    }
}
