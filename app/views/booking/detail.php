<?php
/** @var string $title */
/** @var array<string, mixed> $booking */
/** @var float $balance */
use App\Core\Guard;
use App\Core\View;

$payable = $balance > 0.01
    && in_array($booking['status'], ['pending_payment', 'confirmed'], true);

require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <p><a href="<?= View::e(url('/bookings')) ?>">&larr; My bookings</a></p>
  <h1>Booking <?= View::e($booking['booking_ref']) ?></h1>

  <dl class="spec-list">
    <dt>Vehicle</dt>
    <dd><?= View::e($booking['make'] . ' ' . $booking['model']) ?> (<?= View::e($booking['registration_plate']) ?>)</dd>
    <dt>Status</dt>
    <dd><span class="badge badge-<?= View::e(str_replace('_', '-', (string) $booking['status'])) ?>">
      <?= View::e(ucwords(str_replace('_', ' ', (string) $booking['status']))) ?></span></dd>
    <dt>Pickup date</dt><dd><?= View::e($booking['pickup_date']) ?></dd>
    <dt>Return date</dt><dd><?= View::e($booking['return_date']) ?></dd>
    <?php if (!empty($booking['pickup_location'])): ?>
      <dt>Pickup location</dt><dd><?= View::e($booking['pickup_location']) ?></dd>
    <?php endif; ?>
    <?php if (!empty($booking['notes'])): ?>
      <dt>Notes</dt><dd><?= nl2br(View::e($booking['notes'])) ?></dd>
    <?php endif; ?>
    <dt>Daily rate</dt><dd>KES <?= View::e(number_format((float) $booking['daily_rate'], 2)) ?></dd>
    <dt>Total amount</dt><dd>KES <?= View::e(number_format((float) $booking['total_amount'], 2)) ?></dd>
    <dt>Balance due</dt><dd>KES <?= View::e(number_format((float) $balance, 2)) ?></dd>
    <?php if (!empty($booking['actual_return_at'])): ?>
      <dt>Returned</dt><dd><?= View::e($booking['actual_return_at']) ?></dd>
    <?php endif; ?>
  </dl>

  <div class="btn-row">
    <?php if ($payable && Guard::hasRole('client')): ?>
      <a class="btn" href="<?= View::e(url('/payment/' . (int) $booking['id'])) ?>">Pay with M-Pesa</a>
    <?php endif; ?>
    <a class="btn btn-secondary" href="<?= View::e(url('/booking/' . rawurlencode((string) $booking['booking_ref']) . '/confirm')) ?>">
      View confirmation
    </a>
  </div>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
