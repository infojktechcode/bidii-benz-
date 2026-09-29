<?php
/** @var string $title */
/** @var string|null $error */
/** @var string $old_identifier */
use App\Core\Csrf;
use App\Core\View;

$identifier = $old_identifier ?? '';
require dirname(__DIR__) . '/layout/header.php';
?>
<section class="auth-card">
  <h1>Sign in</h1>
  <p class="muted">Access your bookings and payments.</p>

  <?php if (!empty($error)): ?>
    <p class="alert alert-error" role="alert"><?= View::e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= View::e(url('/login')) ?>" novalidate>
    <?= Csrf::field('login') ?>

    <label for="identifier">Email or phone number</label>
    <input id="identifier" name="identifier" type="text" inputmode="email"
           autocomplete="username" maxlength="190" required
           value="<?= View::e($identifier) ?>"
           placeholder="you@example.com or 07XXXXXXXX">

    <label for="password">Password</label>
    <input id="password" name="password" type="password"
           autocomplete="current-password" maxlength="72" required>

    <button class="btn btn-block" type="submit">Sign in</button>
  </form>

  <p class="muted">No account yet? <a href="<?= View::e(url('/register')) ?>">Create one</a></p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
