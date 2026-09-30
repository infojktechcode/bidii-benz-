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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index, follow">
<title><?= View::e($nonceTitle) ?></title>
<link rel="stylesheet" href="<?= View::e(View::url('/assets/css/app.css')) ?>">
<script src="<?= View::e(View::url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= View::e(View::url('/')) ?>">BIDII BENZ <span>RENTALS</span></a>
    <nav aria-label="Main navigation">
      <a href="<?= View::e(View::url('/')) ?>">Home</a>
      <a href="<?= View::e(View::url('/cars')) ?>">Fleet</a>
      <?php if ($isAuthed): ?>
        <?php if (in_array($role, ['staff', 'owner'], true)): ?>
          <a href="<?= View::e(View::url('/admin')) ?>">Dashboard</a>
        <?php else: ?>
          <a href="<?= View::e(View::url('/bookings')) ?>">My bookings</a>
        <?php endif; ?>
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
<main class="container">
<?php if ($flashSuccess !== null && $flashSuccess !== ''): ?>
  <p class="alert alert-success" role="status"><?= View::e($flashSuccess) ?></p>
<?php endif; ?>
<?php if ($flashError !== null && $flashError !== ''): ?>
  <p class="alert alert-error" role="alert"><?= View::e($flashError) ?></p>
<?php endif; ?>
