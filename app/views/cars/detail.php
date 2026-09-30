<?php
/** @var string $title */
/** @var array<string, mixed> $car */
/** @var list<string> $occupied_days */
/** @var string $min_date */
/** @var string $max_date */
use App\Core\Guard;
use App\Core\View;

$occupied = array_flip($occupied_days ?? []);
require dirname(__DIR__) . '/layout/header.php';

$cursor = new DateTimeImmutable($min_date);
$end = new DateTimeImmutable($max_date);
$month = '';
?>
<section>
  <p><a href="<?= View::e(url('/cars')) ?>">&larr; Back to fleet</a></p>

  <div class="detail-layout">
    <div class="detail-media">
      <?php if (!empty($car['image_path'])): ?>
        <img src="<?= View::e(url('/media/cars/' . (int) $car['id'])) ?>"
             alt="<?= View::e($car['make'] . ' ' . $car['model']) ?>" width="640" height="400">
      <?php else: ?>
        <div class="card-media-placeholder large" aria-hidden="true">MB</div>
      <?php endif; ?>
    </div>

    <div class="detail-info">
      <h1><?= View::e($car['make'] . ' ' . $car['model']) ?></h1>
      <p class="price">KES <?= View::e(number_format((float) $car['daily_price'], 0)) ?> <span>/ day</span></p>

      <dl class="spec-list">
        <dt>Year</dt><dd><?= View::e($car['year'] ?? '—') ?></dd>
        <dt>Body type</dt><dd><?= View::e($car['body_type'] ?? '—') ?></dd>
        <dt>Seats</dt><dd><?= View::e($car['seats']) ?></dd>
        <dt>Transmission</dt><dd><?= View::e(ucfirst((string) $car['transmission'])) ?></dd>
        <dt>Fuel</dt><dd><?= View::e(ucfirst((string) $car['fuel_type'])) ?></dd>
        <dt>Registration</dt><dd><?= View::e($car['registration_plate']) ?></dd>
      </dl>

      <?php if (!empty($car['description'])): ?>
        <p><?= nl2br(View::e($car['description'])) ?></p>
      <?php endif; ?>

      <?php if (Guard::check() && Guard::hasRole('client')): ?>
        <a class="btn" href="<?= View::e(url('/cars/' . (int) $car['id'] . '/book')) ?>">Book this vehicle</a>
      <?php elseif (Guard::check()): ?>
        <p class="muted">Sign in with a client account to book this vehicle.</p>
      <?php else: ?>
        <a class="btn" href="<?= View::e(url('/login')) ?>">Sign in to book</a>
      <?php endif; ?>
    </div>
  </div>

  <h2>Availability — next 60 days</h2>
  <p class="muted">Shaded days are already booked.</p>
  <div class="calendar" role="list" aria-label="Occupancy for the next 60 days">
    <?php while ($cursor <= $end): ?>
      <?php
      $label = $cursor->format('F Y');
      if ($label !== $month) {
          $month = $label;
          echo '<div class="calendar-month" role="presentation">' . View::e($month) . '</div>';
      }
      $iso = $cursor->format('Y-m-d');
      $taken = isset($occupied[$iso]);
      ?>
      <span class="calendar-day<?= $taken ? ' taken' : '' ?>"
            role="listitem"
            title="<?= View::e($iso . ($taken ? ' — booked' : ' — available')) ?>"
            <?= $taken ? 'aria-label="Booked"' : 'aria-label="Available"' ?>>
        <?= View::e($cursor->format('j')) ?>
      </span>
      <?php $cursor = $cursor->modify('+1 day'); ?>
    <?php endwhile; ?>
  </div>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
