<?php
/** @var string $title */
/** @var array<string, mixed> $booking */
/** @var float $balance */
use App\Core\Csrf;
use App\Core\Guard;
use App\Core\View;
use App\Services\PaymentService;

$payable = $balance > 0.01
    && in_array((string) $booking['status'], PaymentService::PAYABLE_STATUSES, true);

require dirname(__DIR__) . '/layout/header.php';
$step = ($payable && Guard::hasRole('client')) ? 3 : 5;
require dirname(__DIR__) . '/partials/steps.php';
?>
<section class="print-sheet">
  <p class="alert alert-success" role="status">Your booking has been received.</p>

  <h1>Booking <?= View::e($booking['booking_ref']) ?></h1>

  <dl class="spec-list">
    <dt>Vehicle</dt>
    <dd><?= View::e($booking['make'] . ' ' . $booking['model']) ?> (<?= View::e($booking['registration_plate']) ?>)</dd>
    <dt>Status</dt>
    <dd><?= $statusBadge((string) $booking['status']) ?></dd>
    <dt>Pickup date</dt><dd><?= View::e($booking['pickup_date']) ?></dd>
    <dt>Return date</dt><dd><?= View::e($booking['return_date']) ?></dd>
    <?php if (!empty($booking['pickup_location'])): ?>
      <dt>Pickup location</dt><dd><?= View::e($booking['pickup_location']) ?></dd>
    <?php endif; ?>
    <dt>Daily rate</dt><dd>KES <?= View::e(number_format((float) $booking['daily_rate'], 2)) ?></dd>
    <dt>Total amount</dt><dd>KES <?= View::e(number_format((float) $booking['total_amount'], 2)) ?></dd>
    <dt>Balance due</dt>
    <dd>KES <?= View::e(number_format((float) $balance, 2)) ?></dd>
  </dl>

  <p class="muted">A copy of this confirmation can be printed for your records.</p>

  <div class="btn-row">
    <button class="btn btn-secondary" type="button" data-print>Print confirmation</button>
    <?php if ($payable && Guard::hasRole('client')): ?>
      <a class="btn" href="<?= View::e(url('/payment/' . (int) $booking['id'])) ?>">Pay with M-Pesa</a>
    <?php endif; ?>
    <a class="btn btn-secondary" href="<?= View::e(url('/bookings')) ?>">My bookings</a>
  </div>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
