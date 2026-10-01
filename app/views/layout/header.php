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
$at = static fn (string $p, bool $prefix = false): string => ($prefix
    ? str_starts_with($currentPath, $p)
    : $currentPath === $p)
    ? ' aria-current="page"'
    : '';
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
<body>
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
        <span class="nav-user"><?= View::e((string) Session::get('user_name', '')) ?></span>
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
<main id="main-content" class="container">
<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
  <p class="alert alert-success" role="status"><?= View::e($flashSuccess) ?></p>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
  <p class="alert alert-error" role="alert"><?= View::e($flashError) ?></p>
<?php endif; ?>
