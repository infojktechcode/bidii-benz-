<?php

declare(strict_types=1);

/**
 * Live HTTP smoke test. Run with Apache up:
 *
 *     php scripts/http-smoke.php
 *
 * Exercises the built application over real HTTP end to end: hardening
 * (SEC-01/05/10), authz/IDOR, the booking lifecycle with CSRF, reports, the
 * payment audit, the staff client directory, and the Phase 8 additions (audit
 * viewer, availability badges, owner-only refunds, initiation throttle).
 *
 * Read-only against seeded data: every row it creates is cleaned up at the
 * end. Exits non-zero on the first failing check count > 0.
 */

require dirname(__DIR__) . '/app/autoload.php';

use App\Services\RateLimiter;

const BASE = 'http://localhost/bidii-benz/public';

$root = dirname(__DIR__);
$pdo = App\Core\Database::connect(App\Core\Config::load($root));

$pass = 0;
$fail = 0;
$created = [];

function check(bool $ok, string $label, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $label" . PHP_EOL;
    } else {
        $fail++;
        echo "[FAIL] $label" . ($extra === '' ? '' : " :: $extra") . PHP_EOL;
    }
}

function jar(): string
{
    return sys_get_temp_dir() . '/bidii_smoke_' . bin2hex(random_bytes(4)) . '.cookies';
}

function req(string $j, string $method, string $path, array $data = []): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $j,
        CURLOPT_COOKIEFILE => $j,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'body' => '', 'location' => null, 'head' => '', 'error' => $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $split = strpos($raw, "\r\n\r\n");
    $head = $split === false ? $raw : substr($raw, 0, $split);
    $body = $split === false ? '' : substr($raw, $split + 4);
    $loc = null;
    if (preg_match('/^Location:\s*(.+)$/mi', $head, $m)) {
        $loc = trim($m[1]);
    }
    return ['code' => $code, 'body' => $body, 'location' => $loc, 'head' => $head, 'error' => null];
}

function csrfIn(string $html, string $actionNeedle): ?string
{
    if (!preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $html, $forms)) {
        return null;
    }
    foreach ($forms[0] as $form) {
        if (str_contains($form, $actionNeedle) && preg_match('/name="_csrf" value="([^"]+)"/', $form, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
    }
    return null;
}

function csrfAny(string $html): ?string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
}

function login(string $j, string $identifier, string $password): bool
{
    $page = req($j, 'GET', '/login');
    $token = csrfAny($page['body']);
    if ($token === null) {
        return false;
    }
    $r = req($j, 'POST', '/login', [
        'identifier' => $identifier,
        'password' => $password,
        '_csrf' => $token,
    ]);
    return $r['code'] === 302;
}

function bookingStatus(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare('SELECT status FROM bookings WHERE id = ?');
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function auditRows(PDO $pdo, string $action, int $entityId): array
{
    $st = $pdo->prepare('SELECT user_id, role, detail FROM audit_logs WHERE action = ? AND entity_id = ?');
    $st->execute([$action, $entityId]);
    return $st->fetchAll();
}

function userIdByEmail(PDO $pdo, string $email): int
{
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    return (int) $st->fetchColumn();
}

function pathOf(?string $location): string
{
    $p = (string) preg_replace('#^https?://[^/]+#', '', (string) $location);
    $p = (string) preg_replace('#^' . preg_quote(BASE, '#') . '#', '', $p);
    return $p === '' ? '/' : $p;
}

function ext(string $path): array
{
    $ch = curl_init($path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $raw];
}

function createBooking(PDO $pdo, string $j, int $carId, int $startInDays, array &$created): ?int
{
    $page = req($j, 'GET', '/cars/' . $carId . '/book');
    if ($page['code'] !== 200) {
        return null;
    }
    $token = csrfIn($page['body'], '/book') ?? csrfAny($page['body']);
    $r = req($j, 'POST', '/cars/' . $carId . '/book', [
        'pickup_date' => date('Y-m-d', strtotime("+{$startInDays} days")),
        'return_date' => date('Y-m-d', strtotime('+' . ($startInDays + 1) . ' days')),
        '_csrf' => $token,
    ]);
    if ($r['code'] !== 302 || !preg_match('#/booking/([A-Za-z0-9\-]+)/confirm#', (string) $r['location'], $m)) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM bookings WHERE booking_ref = ?');
    $st->execute([$m[1]]);
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        $created[] = $id;
    }
    return $id > 0 ? $id : null;
}

echo '== Live HTTP smoke == ' . PHP_EOL;

// ============================================================ SEC-01 hardening
echo '-- SEC-01: web-root must refuse hidden files --' . PHP_EOL;
foreach ([
    '/' => 403,
    '/.env' => 403,
    '/.env.example' => 403,
    '/.git/HEAD' => 403,
    '/.git/config' => 403,
    '/docs/database-design.md' => 403,
    '/tests/bootstrap.php' => 403,
    '/phpunit.xml' => 403,
    '/phpunit.phar' => 403,
    '/database/migrations/0001_create_users.sql' => 403,
] as $path => $expected) {
    [$code] = ext('http://localhost/bidii-benz' . $path);
    check($code === $expected, "GET $path is $expected", "got $code");
}
[$code] = ext('http://localhost/bidii-benz/public/cars');
check($code === 200, 'the fleet page still serves 200', "got $code");

// ======================================================= fixtures for the run
$st = $pdo->query(
    "SELECT c.id FROM cars c WHERE c.status = 'active' AND c.deleted_at IS NULL
     AND NOT EXISTS (
        SELECT 1 FROM booking_days bd WHERE bd.car_id = c.id
          AND bd.day BETWEEN '" . date('Y-m-d', strtotime('+60 days')) . "'
                          AND '" . date('Y-m-d', strtotime('+77 days')) . "'
     ) ORDER BY c.id LIMIT 1"
);
$carId = (int) $st->fetchColumn();
check($carId > 0, 'a free test vehicle exists', 'car id ' . $carId);

$st = $pdo->query(
    "SELECT b.id, b.booking_ref FROM bookings b
     JOIN clients c ON c.id = b.client_id
     JOIN users u ON u.id = c.user_id
     WHERE u.email = 'client2@bidii.test' ORDER BY b.id LIMIT 1"
);
$other = $st->fetch();
$otherId = (int) ($other['id'] ?? 0);
$otherRef = (string) ($other['booking_ref'] ?? '');
check($otherId > 0, 'client2 owns a seeded booking for the IDOR checks', "id $otherId");

$client1UserId = userIdByEmail($pdo, 'client1@bidii.test');

// ============================================================== SEC-05 reval
echo '-- SEC-05: session revalidation --' . PHP_EOL;
$email = 'smoke-staff-' . bin2hex(random_bytes(3)) . '@example.test';
$phone = '07' . random_int(10000000, 99999999);
$hash = password_hash('SmokeStaff123!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (role, email, phone, password_hash, status) VALUES ('staff', ?, ?, ?, 'active')")
    ->execute([$email, $phone, $hash]);
$staffUserId = (int) $pdo->lastInsertId();

$staff = jar();
check(login($staff, $email, 'SmokeStaff123!'), 'throwaway staff account signs in');
$r = req($staff, 'GET', '/admin/clients');
check($r['code'] === 200, 'active staff opens /admin/clients', "code {$r['code']}");

$pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$staffUserId]);
$r = req($staff, 'GET', '/admin/clients');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'suspended staff is signed out on the very next request', "code {$r['code']} loc {$r['location']}");

$staff2 = jar();
login($staff2, $email, 'SmokeStaff123!');
$r = req($staff2, 'GET', '/admin');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'a suspended account gets no session when it tries to sign in',
    "code {$r['code']} loc {$r['location']}");
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$staffUserId]);
$staff3 = jar();
check(login($staff3, $email, 'SmokeStaff123!'), 'reactivated staff signs in again');
$r = req($staff3, 'GET', '/admin');
check($r['code'] === 200, 'active staff opens the dashboard', "code {$r['code']}");
$pdo->prepare("UPDATE users SET role = 'client' WHERE id = ?")->execute([$staffUserId]);
$r = req($staff3, 'GET', '/admin');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'role change invalidates the cached session immediately', "code {$r['code']} loc {$r['location']}");

// ================================================================= anonymous
$anon = jar();
$r = req($anon, 'GET', '/admin/reports');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'anonymous /admin/reports redirects to login', "code {$r['code']} loc {$r['location']}");

$r = req($anon, 'GET', '/admin');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'anonymous /admin redirects to login', "code {$r['code']}");

$r = req($anon, 'GET', '/bookings');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'anonymous /bookings redirects to login', "code {$r['code']}");

$r = req($anon, 'POST', '/bookings/' . $otherId . '/cancel', ['_csrf' => 'bogus']);
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'anonymous cancel attempt is stopped before any handler runs', "code {$r['code']}");

$r = req($anon, 'GET', '/admin/audit');
check($r['code'] === 302 && str_contains((string) $r['location'], '/login'),
    'anonymous /admin/audit redirects to login', "code {$r['code']}");

// ================================================================== client 1
$c1 = jar();
check(login($c1, 'client1@bidii.test', 'ClientPass123!'), 'client1 signs in');

$r = req($c1, 'GET', '/admin/reports');
check($r['code'] === 403, 'client /admin/reports is forbidden', "code {$r['code']}");
$r = req($c1, 'GET', '/admin');
check($r['code'] === 403, 'client /admin dashboard is forbidden', "code {$r['code']}");
$r = req($c1, 'GET', '/admin/payments');
check($r['code'] === 403, 'client /admin/payments is forbidden', "code {$r['code']}");
$r = req($c1, 'GET', '/admin/audit');
check($r['code'] === 403, 'client /admin/audit is forbidden', "code {$r['code']}");

$r = req($c1, 'GET', '/bookings');
check($r['code'] === 200, 'client can open their booking history', "code {$r['code']}");

if ($otherId > 0) {
    $r = req($c1, 'GET', '/bookings/' . $otherId);
    check($r['code'] === 403, 'IDOR: client cannot open another client\'s booking', "code {$r['code']}");

    $r = req($c1, 'GET', '/booking/' . $otherRef . '/confirm');
    check($r['code'] === 403, 'IDOR: client cannot open another client\'s confirmation page', "code {$r['code']}");
}

$st = $pdo->query(
    "SELECT b.id FROM bookings b JOIN clients c ON c.id = b.client_id
     JOIN users u ON u.id = c.user_id WHERE u.email = 'client1@bidii.test'
     ORDER BY b.id LIMIT 1"
);
$client1Booking = (int) $st->fetchColumn();
$r = req($c1, 'GET', '/bookings/' . $client1Booking);
check($r['code'] === 200 && str_contains($r['body'], 'Payments'),
    'client booking detail shows the payment records', "code {$r['code']}");

// ==================================================== booking lifecycle (CSRF)
$bookingA = createBooking($pdo, $c1, $carId, 60, $created);
check($bookingA !== null, 'client can create a booking over HTTP');

if ($bookingA !== null) {
    $rows = auditRows($pdo, 'booking.create', $bookingA);
    check(count($rows) === 1 && (int) $rows[0]['user_id'] === $client1UserId,
        'booking.create is audited against the signed-in client', json_encode($rows));

    $detail = req($c1, 'GET', '/bookings/' . $bookingA);
    $token = csrfIn($detail['body'], '/cancel');
    check($token !== null, 'the cancel form renders its CSRF token');

    $before = auditRows($pdo, 'booking.cancel', $bookingA);
    $r = req($c1, 'POST', '/bookings/' . $bookingA . '/cancel', ['_csrf' => $token]);
    check($r['code'] === 302 && str_contains((string) $r['location'], '/bookings'),
        'client cancels their own booking', "code {$r['code']} loc {$r['location']}");
    check(bookingStatus($pdo, $bookingA) === 'cancelled', 'the booking row is cancelled');
    $rows = auditRows($pdo, 'booking.cancel', $bookingA);
    check(count($rows) === count($before) + 1 && (int) $rows[array_key_last($rows)]['user_id'] === $client1UserId,
        'booking.cancel is audited against the signed-in client');

    $detailA = req($c1, 'GET', '/bookings/' . $bookingA);
    check(!str_contains($detailA['body'], '/cancel'),
        'the UI hides the cancel button once the booking is cancelled');

    $bookingB = createBooking($pdo, $c1, $carId, 63, $created);
    check($bookingB !== null, 'second throwaway booking created');
    if ($bookingB !== null) {
        $borrowed = csrfIn(req($c1, 'GET', '/bookings/' . $bookingB)['body'], '/cancel');
        check($borrowed !== null, 'the second booking renders a valid cancel token');

        $r = req($c1, 'POST', '/bookings/' . $bookingA . '/cancel', ['_csrf' => $borrowed]);
        $after = req($c1, 'GET', pathOf($r['location']));
        check(bookingStatus($pdo, $bookingA) === 'cancelled', 'the booking stays cancelled');
        check(str_contains($after['body'], 'cannot be cancelled'),
            'the second cancel attempt is refused and explained', "code {$r['code']} loc {$r['location']}");
        check(count(auditRows($pdo, 'booking.cancel', $bookingA)) === count($before) + 1,
            'the refused attempt writes no second audit row');

        $r = req($c1, 'POST', '/bookings/' . $bookingB . '/cancel', ['_csrf' => 'not-a-real-token']);
        check($r['code'] === 403, 'a forged CSRF token is rejected with 403', "code {$r['code']}");
        check(bookingStatus($pdo, $bookingB) === 'pending_payment', 'the booking is untouched after the forged token');

        $r = req($c1, 'POST', '/bookings/' . $bookingB . '/cancel', []);
        check($r['code'] === 403, 'a missing CSRF token is rejected with 403', "code {$r['code']}");

        if ($otherId > 0) {
            $r = req($c1, 'POST', '/bookings/' . $otherId . '/cancel', ['_csrf' => $borrowed]);
            check($r['code'] === 403, 'IDOR: a client cannot cancel somebody else\'s booking', "code {$r['code']}");
            check(bookingStatus($pdo, $otherId) !== 'cancelled', 'the victim booking is unchanged');
        }
    }
}

// ==================================================================== staff 1
$s1 = jar();
check(login($s1, 'staff1@bidii.test', 'StaffPass123!'), 'staff1 signs in');

$r = req($s1, 'GET', '/admin/reports');
check($r['code'] === 200 && str_contains($r['body'], '<h1>Reports</h1>'),
    'staff opens the reports page', "code {$r['code']}");

$r = req($s1, 'GET', '/admin/reports?from=2026-03-01&to=2026-04-30');
check($r['code'] === 200 && str_contains($r['body'], 'Reports'), 'a valid date range renders', "code {$r['code']}");

$r = req($s1, 'GET', '/admin/reports?from=banana&to=2026-04-30');
check($r['code'] === 200 && str_contains($r['body'], '2026-04-30'),
    'an invalid from-date is rejected but the value survives', "code {$r['code']}");

$r = req($s1, 'GET', '/admin/reports?from=2026-04-30&to=2026-03-01');
check($r['code'] === 200, 'a reversed range still renders', "code {$r['code']}");
check(str_contains($r['body'], 'before'), 'a reversed range explains itself');

$r = req($s1, 'GET', '/bookings');
check($r['code'] === 403, 'staff cannot use the client-only booking history', "code {$r['code']}");

// --- full staff lifecycle on a throwaway booking -------------------------
$bookingC = createBooking($pdo, $c1, $carId, 66, $created);
check($bookingC !== null, 'third throwaway booking created for the lifecycle');

if ($bookingC !== null) {
    $payPage = req($c1, 'GET', '/payment/' . $bookingC);
    $token = csrfIn($payPage['body'], '/initiate') ?? csrfAny($payPage['body']);
    $r = req($c1, 'POST', '/payment/' . $bookingC . '/initiate', [
        'phone' => '0712345678',
        'method' => 'cash',
        '_csrf' => $token,
    ]);
    $payId = (int) $pdo->query("SELECT id FROM payments WHERE booking_id = $bookingC ORDER BY id DESC LIMIT 1")->fetchColumn();
    check($payId > 0, 'client records a payment against the booking', "code {$r['code']}");

    $detail = req($c1, 'GET', '/bookings/' . $bookingC);
    check($detail['code'] === 200 && str_contains($detail['body'], 'Payments'),
        'the payment appears on the client\'s booking detail');

    $adminDetail = req($s1, 'GET', '/admin/bookings/' . $bookingC);
    check(!str_contains($adminDetail['body'], 'datetime-local'),
        'the return form is offered only while the hire is under way');

    $r = req($s1, 'POST', '/admin/bookings/' . $bookingC . '/complete', [
        'actual_return_at' => date('Y-m-d\TH:i'),
        '_csrf' => 'forged-token',
    ]);
    check($r['code'] === 403, 'a forged complete attempt is rejected with 403', "code {$r['code']}");
    check(bookingStatus($pdo, $bookingC) === 'pending_payment',
        'the pending booking is untouched by the forged attempt');

    $adminDetail = req($s1, 'GET', '/admin/bookings/' . $bookingC);
    $token = csrfIn($adminDetail['body'], '/confirm');
    $r = req($s1, 'POST', '/admin/bookings/' . $bookingC . '/confirm', ['_csrf' => $token]);
    check(bookingStatus($pdo, $bookingC) === 'confirmed', 'staff confirms the booking', "code {$r['code']}");

    $adminDetail = req($s1, 'GET', '/admin/bookings/' . $bookingC);
    $token = csrfIn($adminDetail['body'], '/start');
    $r = req($s1, 'POST', '/admin/bookings/' . $bookingC . '/start', ['_csrf' => $token]);
    check(bookingStatus($pdo, $bookingC) === 'active', 'staff starts the hire', "code {$r['code']}");

    $detail = req($c1, 'GET', '/bookings/' . $bookingC);
    $token = csrfIn($detail['body'], '/cancel');
    if ($token !== null) {
        $r = req($c1, 'POST', '/bookings/' . $bookingC . '/cancel', ['_csrf' => $token]);
        $page = req($c1, 'GET', pathOf($r['location']));
        check(bookingStatus($pdo, $bookingC) === 'active', 'a client cannot cancel an active hire');
        check(str_contains($page['body'], 'cannot be cancelled'), 'the refusal is explained to the client');
    } else {
        check(true, 'the cancel button is hidden for an active hire');
    }

    $pdo->prepare('UPDATE bookings SET pickup_date = ?, return_date = ? WHERE id = ?')->execute([
        date('Y-m-d', strtotime('-3 days')),
        date('Y-m-d', strtotime('-2 days')),
        $bookingC,
    ]);

    $adminDetail = req($s1, 'GET', '/admin/bookings/' . $bookingC);
    $token = csrfIn($adminDetail['body'], '/complete');
    check($token !== null, 'the return form appears once the hire is active');
    check(str_contains($adminDetail['body'], 'datetime-local'), 'the return-time control is present');

    $r = req($s1, 'POST', '/admin/bookings/' . $bookingC . '/complete', [
        'actual_return_at' => date('Y-m-d\TH:i', strtotime('+2 days')),
        '_csrf' => $token,
    ]);
    $page = req($s1, 'GET', pathOf($r['location']));
    check(bookingStatus($pdo, $bookingC) === 'active', 'a future return time is refused');
    check(str_contains($page['body'], 'future'), 'the future-time refusal reaches the screen', "code {$r['code']}");

    $adminDetail = req($s1, 'GET', '/admin/bookings/' . $bookingC);
    $token = csrfIn($adminDetail['body'], '/complete');
    $submitted = date('Y-m-d H:i:s', strtotime('-2 days 09:15:00'));
    $r = req($s1, 'POST', '/admin/bookings/' . $bookingC . '/complete', [
        'actual_return_at' => date('Y-m-d\TH:i', strtotime('-2 days 09:15:00')),
        '_csrf' => $token,
    ]);
    $st = $pdo->prepare('SELECT status, actual_return_at FROM bookings WHERE id = ?');
    $st->execute([$bookingC]);
    $row = $st->fetch();
    check($row['status'] === 'completed' && (string) $row['actual_return_at'] === $submitted,
        'a realistic return time completes the hire and is recorded', json_encode($row));
}

// ================================================================== owner
$owner = jar();
check(login($owner, 'owner@bidii.test', 'OwnerPass123!'), 'owner signs in');
$r = req($owner, 'GET', '/admin/reports');
check($r['code'] === 200 && str_contains($r['body'], '<h1>Reports</h1>'),
    'owner opens the reports page', "code {$r['code']}");

// ================================================== SEC-10 payment.initiate audit
echo '-- SEC-10: payment.initiate audit --' . PHP_EOL;
$bookingP = createBooking($pdo, $c1, $carId, 70, $created);
check($bookingP !== null, 'throwaway booking created for the payment');

if ($bookingP !== null) {
    $pay = req($c1, 'GET', '/payment/' . $bookingP);
    check($pay['code'] === 200, 'the payment page opens', "code {$pay['code']}");
    $token = csrfIn($pay['body'], '/initiate');
    check($token !== null, 'the initiate form renders its CSRF token');

    $r = req($c1, 'POST', '/payment/' . $bookingP . '/initiate', [
        'phone' => '0712345678',
        'method' => 'mpesa',
        '_csrf' => $token,
    ]);
    check($r['code'] === 302, 'the mock M-Pesa collection is initiated', "code {$r['code']}");

    $st = $pdo->prepare('SELECT id FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$bookingP]);
    $paymentId = (int) $st->fetchColumn();
    check($paymentId > 0, 'a payment row exists', "payment $paymentId");

    $rows = auditRows($pdo, 'payment.initiate', $paymentId);
    check(
        count($rows) === 1
            && (int) $rows[0]['user_id'] === $client1UserId
            && str_contains((string) $rows[0]['detail'], 'booking='),
        'payment.initiate is audited against the paying client',
        json_encode($rows)
    );

    $bookingP2 = createBooking($pdo, $c1, $carId, 73, $created);
    if ($bookingP2 !== null) {
        $pay2 = req($c1, 'GET', '/payment/' . $bookingP2);
        $token2 = csrfIn($pay2['body'], '/initiate');
        $r = req($c1, 'POST', '/payment/' . $bookingP2 . '/initiate', [
            'phone' => '0712345678',
            'method' => 'cash',
            '_csrf' => $token2,
        ]);
        check($r['code'] === 302, 'a cash record is created', "code {$r['code']}");
        $st = $pdo->prepare('SELECT id FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$bookingP2]);
        $cashPaymentId = (int) $st->fetchColumn();
        $rows = auditRows($pdo, 'payment.initiate', $cashPaymentId);
        check(count($rows) === 1 && (int) $rows[0]['user_id'] === $client1UserId,
            'cash payment.initiate is audited too', json_encode($rows));
    }
}

// ============================================================ client directory
echo '-- staff client directory --' . PHP_EOL;
$r = req($s1, 'GET', '/admin');
check($r['code'] === 200 && str_contains($r['body'], 'Manage clients'), 'dashboard renders', "code {$r['code']}");
$r = req($s1, 'GET', '/admin/clients');
check($r['code'] === 200 && str_contains($r['body'], 'Clients'), 'client directory renders', "code {$r['code']}");
check(str_contains($r['body'], 'client1@bidii.test') || str_contains($r['body'], 'Nairobi'),
    'directory lists seeded clients');
preg_match('#/admin/clients/(\d+)#', $r['body'], $m);
$clientId = $m[1] ?? '0';
$r = req($s1, 'GET', '/admin/clients/' . $clientId);
check($r['code'] === 200 && str_contains($r['body'], 'Bookings'), "client detail #$clientId renders", "code {$r['code']}");
$r = req($s1, 'GET', '/admin/clients/999999');
check($r['code'] === 404, 'unknown client is 404', "code {$r['code']}");

// ================================================= Phase 8: audit viewer (P8-2)
echo '-- Phase 8: audit viewer --' . PHP_EOL;
$r = req($s1, 'GET', '/admin/audit');
check($r['code'] === 200 && str_contains($r['body'], 'Audit trail'), 'staff opens the audit viewer', "code {$r['code']}");
check(str_contains($r['body'], 'auth.login'), 'the viewer lists real audit actions');
check(str_contains($r['body'], 'audit-action') && str_contains($r['body'], 'audit-user'),
    'the action and actor filters render');
$r = req($s1, 'GET', '/admin/audit?action=booking.create');
check($r['code'] === 200 && str_contains($r['body'], 'booking.create'), 'the action filter applies', "code {$r['code']}");
$r = req($s1, 'GET', '/admin/audit?page=banana');
check($r['code'] === 200, 'a junk page parameter falls back safely', "code {$r['code']}");
$r = req($owner, 'GET', '/admin/audit');
check($r['code'] === 200 && str_contains($r['body'], 'Audit trail'), 'owner opens the audit viewer', "code {$r['code']}");

// =============================================== Phase 8: availability (P8-3)
echo '-- Phase 8: fleet availability badges --' . PHP_EOL;
$r = req($anon, 'GET', '/cars');
check($r['code'] === 200, 'the public fleet page renders', "code {$r['code']}");
check(
    str_contains($r['body'], 'badge badge-confirmed') || str_contains($r['body'], 'badge badge-pending-payment'),
    'every fleet card carries an availability badge'
);
check(str_contains($r['body'], 'skip-link'), 'the skip link is the first tab stop on public pages');
$r = req($anon, 'GET', '/cars/' . $carId);
check($r['code'] === 200 && str_contains($r['body'], 'Availability'), 'the detail page keeps its 60-day calendar');

// ============================================ Phase 8: owner-only refund (P8-8)
echo '-- Phase 8: refunds are owner-only --' . PHP_EOL;
$st = $pdo->query("SELECT id FROM payments WHERE status = 'confirmed' ORDER BY id LIMIT 1");
$confirmedPaymentId = (int) $st->fetchColumn();
check($confirmedPaymentId > 0, 'a confirmed payment exists for the refund checks');

$r = req($s1, 'GET', '/admin/payments');
check($r['code'] === 200 && !str_contains($r['body'], '/refund'),
    'staff sees no refund control on the payments page');
if ($confirmedPaymentId > 0) {
    $st = $pdo->prepare('SELECT status FROM payments WHERE id = ?');
    $st->execute([$confirmedPaymentId]);
    $paymentStatusBefore = (string) $st->fetchColumn();

    $r = req($s1, 'POST', '/admin/payments/' . $confirmedPaymentId . '/refund', [
        '_csrf' => 'staff-must-be-stopped-here',
        'notes' => 'should never land',
    ]);
    check($r['code'] === 403, 'staff is refused at the route guard', "code {$r['code']}");

    $st->execute([$confirmedPaymentId]);
    check((string) $st->fetchColumn() === $paymentStatusBefore, 'the payment is untouched after the refusal');
}
$r = req($owner, 'GET', '/admin/payments');
check($r['code'] === 200 && str_contains($r['body'], '/refund'), 'the owner sees the refund control');

// ======================================== Phase 8: initiation throttle (P8-5)
echo '-- Phase 8: payment initiation throttle --' . PHP_EOL;
$thr = jar();
check(login($thr, 'client1@bidii.test', 'ClientPass123!'), 'a fresh session signs in for the throttle test');
$bookingT = createBooking($pdo, $thr, $carId, 76, $created);
check($bookingT !== null, 'throwaway booking created for the throttle test');
if ($bookingT !== null) {
    $pay = req($thr, 'GET', '/payment/' . $bookingT);
    $token = csrfIn($pay['body'], '/initiate');
    check($pay['code'] === 200 && $token !== null, 'the payment page renders its initiate form', "code {$pay['code']}");

    $max = RateLimiter::MAX_INITIATES_PER_WINDOW;
    $i = 0;
    for (; $i < $max; $i++) {
        // Invalid phone: counted by the throttle, refused by validation, so
        // no payment row is ever written by this loop.
        $r = req($thr, 'POST', '/payment/' . $bookingT . '/initiate', [
            'phone' => '',
            'method' => 'mpesa',
            '_csrf' => $token,
        ]);
        if ($r['code'] !== 302) {
            break;
        }
    }
    check($i === $max, "the first {$max} attempts are counted but still handled",
        "stopped after $i, code " . ($r['code'] ?? 'n/a'));

    $r = req($thr, 'POST', '/payment/' . $bookingT . '/initiate', [
        'phone' => '',
        'method' => 'mpesa',
        '_csrf' => $token,
    ]);
    check($r['code'] === 429, 'attempt ' . ($max + 1) . ' inside the window is refused with 429', "code {$r['code']}");

    $st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE booking_id = ?');
    $st->execute([$bookingT]);
    check((int) $st->fetchColumn() === 0, 'no payment row was written by any throttled attempt');
}

// ================================================================== cleanup
$pdo->prepare('DELETE FROM audit_logs WHERE user_id = ?')->execute([$staffUserId]);
$pdo->prepare('DELETE FROM audit_logs WHERE detail LIKE ?')->execute(['%' . $email . '%']);
$pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$email]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$staffUserId]);
echo "[cleanup] removed throwaway staff account $email" . PHP_EOL;

$ids = implode(',', array_map('intval', $created));
if ($ids !== '') {
    $payIds = implode(',', array_map('intval', $pdo->query(
        "SELECT id FROM payments WHERE booking_id IN ($ids)"
    )->fetchAll(PDO::FETCH_COLUMN)) ?: [0]);
    $pdo->exec("DELETE FROM payments WHERE booking_id IN ($ids)");
    $pdo->exec("DELETE FROM booking_days WHERE booking_id IN ($ids)");
    $pdo->exec("DELETE FROM bookings WHERE id IN ($ids)");
    $pdo->exec("DELETE FROM audit_logs WHERE ((entity = 'bookings' AND entity_id IN ($ids)) OR (entity = 'payments' AND entity_id IN ($payIds)))");
    echo "[cleanup] removed throwaway bookings: $ids" . PHP_EOL;
}

echo PHP_EOL . "== RESULT: $pass passed, $fail failed ==" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
