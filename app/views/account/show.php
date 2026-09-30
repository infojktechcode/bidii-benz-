<?php
/** @var string $title */
/** @var array<string, mixed> $user */
/** @var array<string, mixed>|null $profile */
/** @var array<string, string> $profileErrors */
/** @var array<string, string> $profileOld */
/** @var array<string, string> $passwordErrors */
use App\Core\Csrf;
use App\Core\View;

$profileErrors = $profileErrors ?? [];
$profileOld = $profileOld ?? [];
$passwordErrors = $passwordErrors ?? [];

$err = static fn (string $f): string => isset($profileErrors[$f])
    ? '<span class="field-error">' . View::e($profileErrors[$f]) . '</span>'
    : '';
$perr = static fn (string $f): string => isset($passwordErrors[$f])
    ? '<span class="field-error">' . View::e($passwordErrors[$f]) . '</span>'
    : '';
$val = static fn (string $f, string $default = ''): string => View::e((string) ($profileOld[$f] ?? $default));

$roleLabels = ['client' => 'Client', 'staff' => 'Staff', 'owner' => 'Owner'];
$role = (string) $user['role'];

require dirname(__DIR__) . '/layout/header.php';
?>
<section class="auth-card auth-card-wide">
  <h1>My account</h1>
  <p class="muted">
    <?= View::e((string) $user['email']) ?> &middot;
    <?= View::e($roleLabels[$role] ?? $role) ?>
  </p>

  <?php if ($profile !== null): ?>
    <?php if ($profileErrors !== []): ?>
      <p class="alert alert-error" role="alert">Please fix the highlighted fields.</p>
    <?php endif; ?>
    <h2>Profile</h2>
    <form method="post" action="<?= View::e(url('/account/profile')) ?>" novalidate>
      <?= Csrf::field('account_profile') ?>

      <label for="full_name">Full name</label>
      <input id="full_name" name="full_name" type="text" autocomplete="name"
             minlength="3" maxlength="120" required
             value="<?= $val('full_name', (string) $profile['full_name']) ?>">
      <?= $err('full_name') ?>

      <label for="phone">Phone number</label>
      <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
             maxlength="20" required placeholder="07XXXXXXXX"
             value="<?= $val('phone', (string) $profile['phone']) ?>">
      <?= $err('phone') ?>

      <label for="city">City</label>
      <input id="city" name="city" type="text" autocomplete="address-level2"
             maxlength="80"
             value="<?= $val('city', (string) ($profile['city'] ?? '')) ?>">
      <?= $err('city') ?>

      <button class="btn btn-block" type="submit">Save profile</button>
    </form>
    <p class="muted">
      Your email address and ID number cannot be changed here &mdash; contact the office.
    </p>
  <?php elseif ($role === 'client'): ?>
    <p class="muted">No client profile is linked to this account.</p>
  <?php endif; ?>

  <h2>Change password</h2>
  <?php if ($passwordErrors !== []): ?>
    <p class="alert alert-error" role="alert">Please fix the highlighted fields.</p>
  <?php endif; ?>
  <form method="post" action="<?= View::e(url('/account/password')) ?>" novalidate>
    <?= Csrf::field('account_password') ?>

    <label for="current_password">Current password</label>
    <input id="current_password" name="current_password" type="password"
           autocomplete="current-password" required>
    <?= $perr('current_password') ?>

    <label for="password">New password</label>
    <input id="password" name="password" type="password"
           autocomplete="new-password" minlength="8" required>
    <?= $perr('password') ?>

    <label for="confirm_password">Confirm new password</label>
    <input id="confirm_password" name="confirm_password" type="password"
           autocomplete="new-password" minlength="8" required>
    <?= $perr('confirm_password') ?>

    <button class="btn btn-block" type="submit">Change password</button>
  </form>
  <p class="muted">
    At least 8 characters. You stay signed in on this device after the change.
  </p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
