<?php
/** @var string $title */
use App\Core\Csrf;
use App\Core\Guard;
use App\Core\Session;
use App\Core\View;

$nonceTitle = $title ?? 'Bidii Benz Rentals';
$flashSuccess = Session::flash('success');
$flashError = Session::flash('error');
$isAuthed = Guard::check();
$role = Guard::role();

$currentPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
// Strip the deployment base path ("/bidii-benz/public") so aria-current and
// the admin rail match application-relative paths in every environment.
$basePath = View::basePath();
if ($basePath !== '' && ($currentPath === $basePath || str_starts_with($currentPath, $basePath . '/'))) {
    $currentPath = substr($currentPath, strlen($basePath)) ?: '/';
}
$at = static fn (string $p, bool $prefix = false): string => ($prefix
    ? str_starts_with($currentPath, $p)
    : $currentPath === $p)
    ? ' aria-current="page"'
    : '';

$roleLabels = ['client' => 'Client', 'staff' => 'Staff', 'owner' => 'Owner'];

// Staff/owner see the dashboard rail while inside the /admin area only;
// public and client pages keep the plain top navigation.
$isAdminArea = in_array($role, ['staff', 'owner'], true)
    && ($currentPath === '/admin' || str_starts_with($currentPath, '/admin/'));

$adminRailLinks = [
    ['/admin', 'Dashboard', false],
    ['/admin/vehicles', 'Vehicles', true],
    ['/admin/bookings', 'Bookings', true],
    ['/admin/clients', 'Clients', true],
    ['/admin/payments', 'Payments', true],
    ['/admin/reports', 'Reports', true],
    ['/admin/audit', 'Audit Log', true],
    ['/account', 'Account', true],
];

// Shared booking-status vocabulary: filter labels, chips and badges.
$statusLabels = [
    'pending_payment' => 'Pending payment',
    'confirmed' => 'Confirmed',
    'active' => 'Active hire',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No show',
];
$statusBadge = static function (string $status) use ($statusLabels): string {
    $label = $statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    return '<span class="badge badge-' . View::e(str_replace('_', '-', $status)) . '">'
        . View::e($label) . '</span>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index, follow">
<meta name="description" content="Mercedes-Benz car hire in Kitengela, Kajiado County. Browse the fleet, book online and pay with M-Pesa.">
<link rel="icon" href="<?= View::e(View::url('/assets/favicon.svg')) ?>" type="image/svg+xml">
<title><?= View::e($nonceTitle) ?></title>
<link rel="stylesheet" href="<?= View::e(View::url('/assets/css/app.css')) ?>">
<script src="<?= View::e(View::url('/assets/js/app.js')) ?>" defer></script>
</head>
<body<?= $isAdminArea ? ' class="has-rail"' : '' ?>>
<a class="skip-link" href="#main-content">Skip to content</a>
<header class="site-header">
  <div class="container header-bar">
    <a class="brand" href="<?= View::e(View::url('/')) ?>">BIDII BENZ <span>RENTALS</span></a>
    <button type="button" class="nav-toggle" data-nav-toggle
            aria-expanded="false" aria-controls="site-nav">Menu</button>
    <nav id="site-nav" class="nav-panel" aria-label="Main navigation">
      <a href="<?= View::e(View::url('/')) ?>"<?= $at('/') ?>>Home</a>
      <a href="<?= View::e(View::url('/cars')) ?>"<?= $at('/cars', true) ?>>Fleet</a>
      <?php if ($isAuthed): ?>
        <?php if (in_array($role, ['staff', 'owner'], true)): ?>
          <a href="<?= View::e(View::url('/admin')) ?>"<?= $at('/admin', true) ?>>Dashboard</a>
        <?php else: ?>
          <a href="<?= View::e(View::url('/bookings')) ?>"<?= $at('/bookings', true) ?>>My bookings</a>
        <?php endif; ?>
        <a href="<?= View::e(View::url('/account')) ?>"<?= $at('/account', true) ?>>Account</a>
        <span class="nav-user"><?= View::e((string) Session::get('user_name', '')) ?>
          <span class="role-chip"><?= View::e($roleLabels[$role] ?? (string) $role) ?></span></span>
        <form method="post" action="<?= View::e(View::url('/logout')) ?>" class="nav-inline">
          <?= Csrf::field('logout') ?>
          <button type="submit" class="link-btn">Sign out</button>
        </form>
      <?php else: ?>
        <a href="<?= View::e(View::url('/login')) ?>">Sign in</a>
        <a href="<?= View::e(View::url('/register')) ?>">Register</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php if ($isAdminArea): ?>
<nav class="rail" aria-label="Dashboard navigation">
  <?php foreach ($adminRailLinks as [$railPath, $railLabel, $railPrefix]): ?>
    <a href="<?= View::e(url($railPath)) ?>"<?= $at($railPath, $railPrefix) ?>><?= View::e($railLabel) ?></a>
  <?php endforeach; ?>
  <form method="post" action="<?= View::e(url('/logout')) ?>" class="rail-logout">
    <?= Csrf::field('logout') ?>
    <button type="submit" class="link-btn">Sign out</button>
  </form>
</nav>
<?php endif; ?>
<main id="main-content" class="container">
<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
  <p class="alert alert-success" role="status"><?= View::e($flashSuccess) ?></p>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
  <p class="alert alert-error" role="alert"><?= View::e($flashError) ?></p>
<?php endif; ?>
