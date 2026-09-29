<?php

declare(strict_types=1);

/**
 * Seed synthetic demo data.
 *
 * Usage: php scripts/seed.php [--force]
 *
 * SAFETY: generates fake names, fake IDs and fake phone numbers.
 * NEVER import real client data (Kenya DPA 2019).
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/autoload.php';

use App\Core\Bootstrap;
use App\Core\Database;

[$config, $pdo] = Bootstrap::boot($projectRoot, false);

try {
    $pdo = Database::connect($config);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: cannot connect to database: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$force = in_array('--force', $argv, true);

$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'cars' => (int) $pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn(),
];
if (($counts['users'] > 0 || $counts['cars'] > 0) && !$force) {
    fwrite(STDERR, "Database already contains data. Re-run with --force to wipe and reseed.\n");
    exit(1);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['booking_days', 'payments', 'bookings', 'audit_logs', 'login_attempts', 'clients', 'cars', 'users'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$hash = static fn (string $pw): string => password_hash($pw, PASSWORD_DEFAULT);

// --- Users (fake credentials, dev only) -------------------------------------
$insertUser = $pdo->prepare(
    'INSERT INTO users (role, email, phone, password_hash, status) VALUES (?, ?, ?, ?, "active")'
);
$users = [
    // role, email, phone, password, [client extras]
    ['owner', 'owner@bidii.test', '0710000001', 'OwnerPass123!', 'Naomi Wanjiku', '29010001', 'Kitengela'],
    ['staff', 'staff1@bidii.test', '0710000002', 'StaffPass123!', 'Brian Otieno', '29010002', 'Kitengela'],
    ['staff', 'staff2@bidii.test', '0710000003', 'StaffPass123!', 'Grace Achieng', '29010003', 'Athi River'],
    ['client', 'client1@bidii.test', '0720000001', 'ClientPass123!', 'John Kamau', '29020001', 'Kitengela'],
    ['client', 'client2@bidii.test', '0720000002', 'ClientPass123!', 'Faith Mutiso', '29020002', 'Nairobi'],
    ['client', 'client3@bidii.test', '0720000003', 'ClientPass123!', 'Peter Njoroge', '29020003', 'Athi River'],
];

$userIdByEmail = [];
foreach ($users as [$role, $email, $phone, $password, $fullName, $idNumber, $city]) {
    $insertUser->execute([$role, $email, $phone, $hash($password)]);
    $userIdByEmail[$email] = (int) $pdo->lastInsertId();
}

// --- Client profiles ---------------------------------------------------------
$insertClient = $pdo->prepare(
    'INSERT INTO clients (user_id, full_name, id_type, id_number, city) VALUES (?, ?, "national_id", ?, ?)'
);
$clientByEmail = [];
foreach ($users as [$role, $email, $phone, $password, $fullName, $idNumber, $city]) {
    if ($role !== 'client') {
        continue;
    }
    $insertClient->execute([$userIdByEmail[$email], $fullName, $idNumber, $city]);
    $clientByEmail[$email] = (int) $pdo->lastInsertId();
}

// --- Cars (Mercedes-Benz fleet, fake plates) ---------------------------------
$insertCar = $pdo->prepare(
    'INSERT INTO cars (make, model, year, body_type, seats, transmission, fuel_type, registration_plate, daily_price, status, description)
     VALUES ("Mercedes-Benz", ?, ?, ?, ?, "automatic", ?, ?, ?, ?, ?)'
);
$cars = [
    // model, year, body, seats, fuel, plate, price, status, description
    ['C180', 2021, 'Sedan', 5, 'petrol', 'KDG123A', 4500.00, 'active', 'Executive sedan, ideal for business trips and airport transfers.'],
    ['C200', 2022, 'Sedan', 5, 'petrol', 'KDG124B', 5000.00, 'active', 'Comfortable saloon with automatic transmission and climate control.'],
    ['E300', 2023, 'Sedan', 5, 'petrol', 'KDG125C', 8000.00, 'active', 'Premium executive sedan for corporate travel and VIP clients.'],
    ['GLC300', 2022, 'SUV', 5, 'petrol', 'KDG126D', 9500.00, 'active', 'Compact luxury SUV, perfect for families and rough roads.'],
    ['G63 AMG', 2023, 'SUV', 5, 'petrol', 'KDG127E', 15000.00, 'active', 'High-performance SUV for special events and off-road trips.'],
    ['V220', 2021, 'Van', 8, 'diesel', 'KDG128F', 11000.00, 'active', '8-seater van for groups, events and family travel.'],
    ['CLA250', 2020, 'Coupe', 5, 'petrol', 'KDG129G', 6000.00, 'maintenance', 'Sporty coupe currently under service.'],
];
$carIdByPlate = [];
$carIdByModel = [];
foreach ($cars as [$model, $year, $body, $seats, $fuel, $plate, $price, $status, $desc]) {
    $insertCar->execute([$model, $year, $body, $seats, $fuel, $plate, $price, $status, $desc]);
    $carIdByPlate[$plate] = (int) $pdo->lastInsertId();
    $carIdByModel[$model] = (int) $pdo->lastInsertId();
}

// --- Bookings + booking_days (synthetic history) -----------------------------
// Helper: occupy [pickup..return] inclusive in booking_days (same rule as
// the availability query will use).
$occupy = static function (int $carId, string $pickup, string $return, int $bookingId) use ($pdo): void {
    $ins = $pdo->prepare('INSERT INTO booking_days (car_id, day, booking_id) VALUES (?, ?, ?)');
    $day = new DateTimeImmutable($pickup);
    $end = new DateTimeImmutable($return);
    while ($day <= $end) {
        $ins->execute([$carId, $day->format('Y-m-d'), $bookingId]);
        $day = $day->modify('+1 day');
    }
};

$ownerId = $userIdByEmail['owner@bidii.test'];
$staffId = $userIdByEmail['staff1@bidii.test'];
$client1 = $clientByEmail['client1@bidii.test'];
$client2 = $clientByEmail['client2@bidii.test'];
$client3 = $clientByEmail['client3@bidii.test'];

$insertBooking = $pdo->prepare(
    'INSERT INTO bookings (booking_ref, client_id, car_id, pickup_date, return_date, daily_rate, total_amount, status, pickup_location, notes, created_by, actual_return_at, cancelled_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertPayment = $pdo->prepare(
    'INSERT INTO payments (booking_id, client_id, amount, method, status, mpesa_receipt, mpesa_phone, confirmed_by, notes, paid_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$mkRef = static fn (): string => 'BB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

/**
 * @param array{ref:string,client:int,car:string,pickup:string,ret:string,rate:float,status:string,
 *   loc:string,notes:string,createdBy:?int,actualReturn:?string,cancelled:?string,
 *   payment?:array{amount:float,method:string,status:string,receipt:?string,phone:?string,confirmedBy:?int,paidAt:?string}} $b
 */
$addBooking = static function (array $b) use ($insertBooking, $insertPayment, $occupy, $carIdByModel, $mkRef, $pdo): int {
    $carId = $carIdByModel[$b['car']];
    $days = (new DateTimeImmutable($b['pickup']))->diff(new DateTimeImmutable($b['ret']))->days + 1;
    $total = round($b['rate'] * $days, 2);
    $insertBooking->execute([
        $b['ref'], $b['client'], $carId, $b['pickup'], $b['ret'], $b['rate'], $total,
        $b['status'], $b['loc'], $b['notes'], $b['createdBy'], $b['actualReturn'], $b['cancelled'],
    ]);
    $bookingId = (int) $pdo->lastInsertId();
    if ($b['status'] !== 'cancelled') {
        $occupy($carId, $b['pickup'], $b['ret'], $bookingId);
    }
    if (isset($b['payment'])) {
        $p = $b['payment'];
        $insertPayment->execute([
            $bookingId, $b['client'], $p['amount'], $p['method'], $p['status'],
            $p['receipt'], $p['phone'], $p['confirmedBy'], $p['notes'] ?? null, $p['paidAt'],
        ]);
    }
    return $bookingId;
};

// Past completed booking, fully paid
$addBooking([
    'ref' => 'BB-' . date('Ymd', strtotime('-30 days')) . '-A1B2',
    'client' => $client1, 'car' => 'C200',
    'pickup' => date('Y-m-d', strtotime('-30 days')),
    'ret' => date('Y-m-d', strtotime('-27 days')),
    'rate' => 5000.00, 'status' => 'completed',
    'loc' => 'Kitengela office', 'notes' => 'Airport transfer, synthetic record.',
    'createdBy' => $staffId, 'actualReturn' => date('Y-m-d 17:00:00', strtotime('-27 days')), 'cancelled' => null,
    'payment' => [
        'amount' => 20000.00, 'method' => 'mpesa', 'status' => 'confirmed',
        'receipt' => 'SGH' . random_int(100000, 999999), 'phone' => '0720000001',
        'confirmedBy' => $staffId, 'paidAt' => date('Y-m-d 10:00:00', strtotime('-31 days')),
    ],
]);

// Past completed booking, deposit only (balance owed)
$addBooking([
    'ref' => 'BB-' . date('Ymd', strtotime('-14 days')) . '-C3D4',
    'client' => $client2, 'car' => 'E300',
    'pickup' => date('Y-m-d', strtotime('-14 days')),
    'ret' => date('Y-m-d', strtotime('-12 days')),
    'rate' => 8000.00, 'status' => 'completed',
    'loc' => 'Athi River', 'notes' => 'Balance outstanding, synthetic record.',
    'createdBy' => $staffId, 'actualReturn' => date('Y-m-d 18:30:00', strtotime('-12 days')), 'cancelled' => null,
    'payment' => [
        'amount' => 10000.00, 'method' => 'mpesa', 'status' => 'confirmed',
        'receipt' => 'SGH' . random_int(100000, 999999), 'phone' => '0720000002',
        'confirmedBy' => $staffId, 'paidAt' => date('Y-m-d 09:15:00', strtotime('-15 days')),
    ],
]);

// Upcoming confirmed booking, paid in full
$addBooking([
    'ref' => 'BB-' . date('Ymd', strtotime('+7 days')) . '-E5F6',
    'client' => $client3, 'car' => 'GLC300',
    'pickup' => date('Y-m-d', strtotime('+7 days')),
    'ret' => date('Y-m-d', strtotime('+10 days')),
    'rate' => 9500.00, 'status' => 'confirmed',
    'loc' => 'Kitengela office', 'notes' => 'Family trip, synthetic record.',
    'createdBy' => $ownerId, 'actualReturn' => null, 'cancelled' => null,
    'payment' => [
        'amount' => 38000.00, 'method' => 'mpesa', 'status' => 'confirmed',
        'receipt' => 'SGH' . random_int(100000, 999999), 'phone' => '0720000003',
        'confirmedBy' => $ownerId, 'paidAt' => date('Y-m-d 12:00:00', strtotime('-1 day')),
    ],
]);

// Upcoming pending payment (no payment yet)
$addBooking([
    'ref' => 'BB-' . date('Ymd', strtotime('+14 days')) . '-G7H8',
    'client' => $client1, 'car' => 'V220',
    'pickup' => date('Y-m-d', strtotime('+14 days')),
    'ret' => date('Y-m-d', strtotime('+16 days')),
    'rate' => 11000.00, 'status' => 'pending_payment',
    'loc' => 'Kitengela office', 'notes' => 'Awaiting M-Pesa payment, synthetic record.',
    'createdBy' => null, 'actualReturn' => null, 'cancelled' => null,
]);

// Cancelled booking (days NOT occupied)
$addBooking([
    'ref' => 'BB-' . date('Ymd', strtotime('+3 days')) . '-I9J0',
    'client' => $client2, 'car' => 'C180',
    'pickup' => date('Y-m-d', strtotime('+3 days')),
    'ret' => date('Y-m-d', strtotime('+5 days')),
    'rate' => 4500.00, 'status' => 'cancelled',
    'loc' => 'Kitengela office', 'notes' => 'Client cancelled, synthetic record.',
    'createdBy' => $staffId, 'actualReturn' => null, 'cancelled' => date('Y-m-d 11:00:00', strtotime('-2 days')),
]);

// --- Audit log sample --------------------------------------------------------
$pdo->prepare('INSERT INTO audit_logs (user_id, role, action, entity, entity_id, ip_address, detail) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$ownerId, 'owner', 'seed.run', 'system', null, '127.0.0.1', 'Synthetic demo data seeded']);

// --- Summary -----------------------------------------------------------------
$tables = ['users', 'clients', 'cars', 'bookings', 'booking_days', 'payments', 'audit_logs'];
foreach ($tables as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    echo sprintf("%-15s %d\n", $t, $n);
}
echo "Seed complete (synthetic data only).\n";
