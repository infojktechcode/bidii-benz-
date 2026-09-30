<?php
/** @var string $title */
/** @var list<array<string, mixed>> $cars */
/** @var array<int, string> $availability car_id => latest return date held today */
use App\Core\View;

$cars = $cars ?? [];
$availability = $availability ?? [];
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <h1>Our Fleet</h1>
  <p class="muted">Mercedes-Benz vehicles available for hire in Kitengela, Kajiado County.</p>

  <?php if ($cars === []): ?>
    <p class="alert alert-error">No vehicles are available right now. Please check back soon.</p>
  <?php else: ?>
    <div class="card-grid">
      <?php foreach ($cars as $car): ?>
        <article class="card">
          <div class="card-media">
            <?php if (!empty($car['image_path'])): ?>
              <img src="<?= View::e(url('/media/cars/' . (int) $car['id'])) ?>"
                   alt="<?= View::e($car['make'] . ' ' . $car['model']) ?>"
                   width="400" height="250" loading="lazy">
            <?php else: ?>
              <div class="card-media-placeholder" aria-hidden="true">MB</div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <h2><?= View::e($car['make'] . ' ' . $car['model']) ?></h2>
            <p class="muted">
              <?= View::e($car['year'] ?? '') ?> &middot;
              <?= View::e($car['seats']) ?> seats &middot;
              <?= View::e($car['transmission']) ?> &middot;
              <?= View::e($car['fuel_type']) ?>
            </p>
            <p class="price">
              KES <?= View::e(number_format((float) $car['daily_price'], 0)) ?>
              <span>/ day</span>
            </p>
            <?php $until = $availability[(int) $car['id']] ?? null; ?>
            <?php if ($until !== null): ?>
              <p><span class="badge badge-pending-payment">
                Booked until <?= View::e($until > date('Y-m-d') ? date('j M', strtotime($until)) : 'today') ?>
              </span></p>
            <?php else: ?>
              <p><span class="badge badge-confirmed">Available</span></p>
            <?php endif; ?>
            <a class="btn" href="<?= View::e(url('/cars/' . (int) $car['id'])) ?>">View details</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
