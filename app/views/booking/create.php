<?php
/** @var string $title */
/** @var array<string, mixed> $car */
/** @var string $min_date */
/** @var string $max_date */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];

$err = static fn (string $f): string => isset($errors[$f])
    ? '<span class="field-error" id="' . $f . '-error">' . View::e($errors[$f]) . '</span>'
    : '';
$eattr = static fn (string $f): string => isset($errors[$f])
    ? ' aria-invalid="true" aria-describedby="' . $f . '-error"'
    : '';
$val = static fn (string $f): string => View::e($old[$f] ?? '');

require dirname(__DIR__) . '/layout/header.php';
$step = 2;
require dirname(__DIR__) . '/partials/steps.php';
?>
<section>
  <p><a href="<?= View::e(url('/cars/' . (int) $car['id'])) ?>">&larr; Back to vehicle</a></p>
  <h1>Book <?= View::e($car['make'] . ' ' . $car['model']) ?></h1>
  <p class="muted">
    KES <?= View::e(number_format((float) $car['daily_price'], 0)) ?> per day &middot;
    registration <?= View::e($car['registration_plate']) ?>
  </p>

  <?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Please fix the highlighted fields.</p>
  <?php endif; ?>

  <form method="post" action="<?= View::e(url('/cars/' . (int) $car['id'] . '/book')) ?>" novalidate>
    <?= \App\Core\Csrf::field('booking_create') ?>

    <label for="pickup_date">Pickup date</label>
    <input id="pickup_date" name="pickup_date" type="date"
           min="<?= View::e($min_date) ?>" max="<?= View::e($max_date) ?>"
           required value="<?= $val('pickup_date') ?>"<?= $eattr('pickup_date') ?>>
    <?= $err('pickup_date') ?>

    <label for="return_date">Return date</label>
    <input id="return_date" name="return_date" type="date"
           min="<?= View::e($min_date) ?>" max="<?= View::e($max_date) ?>"
           required value="<?= $val('return_date') ?>"<?= $eattr('return_date') ?>>
    <?= $err('return_date') ?>

    <label for="pickup_location">Pickup location <span class="optional">(optional)</span></label>
    <input id="pickup_location" name="pickup_location" type="text" maxlength="120"
           placeholder="Kitengela office" value="<?= $val('pickup_location') ?>"<?= $eattr('pickup_location') ?>>
    <?= $err('pickup_location') ?>

    <label for="notes">Notes <span class="optional">(optional)</span></label>
    <textarea id="notes" name="notes" rows="3" maxlength="500"<?= $eattr('notes') ?>><?= $val('notes') ?></textarea>
    <?= $err('notes') ?>

    <button class="btn btn-block" type="submit">Continue to confirmation</button>
  </form>

  <p class="muted">The final total is calculated from the number of days at the daily rate shown above.</p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
