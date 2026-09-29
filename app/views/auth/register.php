<?php
/** @var string $title */
/** @var array<string,string> $errors */
/** @var array<string,string> $old */
/** @var string|null $success */
use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];

$err = static fn (string $f): string => isset($errors[$f])
    ? '<span class="field-error">' . View::e($errors[$f]) . '</span>'
    : '';
$val = static fn (string $f): string => View::e($old[$f] ?? '');

require dirname(__DIR__) . '/layout/header.php';
?>
<section class="auth-card auth-card-wide">
  <h1>Create account</h1>
  <p class="muted">Register to book a car and pay with M-Pesa.</p>

  <?php if (!empty($success)): ?>
    <p class="alert alert-success" role="status"><?= View::e($success) ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Please fix the highlighted fields.</p>
  <?php endif; ?>

  <form method="post" action="<?= View::e(url('/register')) ?>" novalidate>
    <?= Csrf::field('register') ?>

    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" type="text" autocomplete="name"
           maxlength="120" required value="<?= $val('full_name') ?>">
    <?= $err('full_name') ?>

    <label for="email">Email address</label>
    <input id="email" name="email" type="email" inputmode="email"
           autocomplete="email" maxlength="190" required value="<?= $val('email') ?>">
    <?= $err('email') ?>

    <label for="phone">Mobile number (M-Pesa)</label>
    <input id="phone" name="phone" type="tel" inputmode="tel"
           autocomplete="tel" maxlength="20" required value="<?= $val('phone') ?>"
           placeholder="07XXXXXXXX">
    <?= $err('phone') ?>

    <label for="id_number">National ID number</label>
    <input id="id_number" name="id_number" type="text" inputmode="numeric"
           maxlength="20" required value="<?= $val('id_number') ?>">
    <?= $err('id_number') ?>

    <label for="city">Town / area <span class="optional">(optional)</span></label>
    <input id="city" name="city" type="text" maxlength="80"
           value="<?= $val('city') ?>" placeholder="Kitengela">
    <?= $err('city') ?>

    <label for="password">Password</label>
    <input id="password" name="password" type="password"
           autocomplete="new-password" maxlength="72" required>
    <?= $err('password') ?>

    <label for="confirm_password">Confirm password</label>
    <input id="confirm_password" name="confirm_password" type="password"
           autocomplete="new-password" maxlength="72" required>
    <?= $err('confirm_password') ?>

    <button class="btn btn-block" type="submit">Create account</button>
  </form>

  <p class="muted">By registering you accept our
    <a href="<?= View::e(url('/privacy')) ?>">Privacy Notice (Kenya DPA, 2019)</a>.
    Already have an account? <a href="<?= View::e(url('/login')) ?>">Sign in</a></p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
