<?php
/** @var string $title */
/** @var array<string, mixed> $booking */
/** @var float $balance */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];

$err = static fn (string $f): string => isset($errors[$f])
    ? '<span class="field-error">' . View::e($errors[$f]) . '</span>'
    : '';
$val = static fn (string $f): string => View::e($old[$f] ?? '');

require dirname(__DIR__) . '/layout/header.php';
$step = 4;
require dirname(__DIR__) . '/partials/steps.php';
?>
<section class="auth-card auth-card-wide">
  <h1>Pay for <?= View::e($booking['booking_ref']) ?></h1>
  <p class="muted">
    <?= View::e($booking['make'] . ' ' . $booking['model']) ?> &middot;
    <?= View::e($booking['pickup_date']) ?> to <?= View::e($booking['return_date']) ?>
  </p>

  <p class="price">Balance: KES <?= View::e(number_format((float) $balance, 2)) ?></p>

  <?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Please fix the highlighted fields.</p>
  <?php endif; ?>

  <form method="post" action="<?= View::e(url('/payment/' . (int) $booking['id'] . '/initiate')) ?>" novalidate>
    <?= Csrf::field('payment_initiate') ?>

    <label for="phone">M-Pesa phone number</label>
    <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
           maxlength="20" required placeholder="07XXXXXXXX"
           value="<?= $val('phone') ?>">
    <?= $err('phone') ?>

    <label for="method">Payment method</label>
    <select id="method" name="method" required>
      <option value="mpesa"<?= $val('method') === 'mpesa' ? ' selected' : '' ?>>M-Pesa (STK push)</option>
      <option value="cash"<?= $val('method') === 'cash' ? ' selected' : '' ?>>Cash at the office</option>
      <option value="bank_transfer"<?= $val('method') === 'bank_transfer' ? ' selected' : '' ?>>Bank transfer</option>
    </select>
    <?= $err('method') ?>

    <button class="btn btn-block" type="submit">Pay KES <?= View::e(number_format((float) $balance, 2)) ?></button>
  </form>

  <p class="muted">
    Cash and bank transfers are recorded now and confirmed by the office.
    <a href="<?= View::e(url('/booking/' . rawurlencode((string) $booking['booking_ref']) . '/confirm')) ?>">Back to confirmation</a>
  </p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
