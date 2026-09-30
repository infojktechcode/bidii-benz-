<?php
/** @var string $title */
/** @var array<string, mixed> $booking */
/** @var float $balance */
/** @var list<array<string, mixed>> $payments */
use App\Core\Csrf;
use App\Core\Guard;
use App\Core\View;
use App\Services\PaymentService;

$payments = $payments ?? [];
$payable = $balance > 0.01
    && in_array((string) $booking['status'], PaymentService::PAYABLE_STATUSES, true);
$cancellable = Guard::hasRole('client')
    && in_array($booking['status'], ['pending_payment', 'confirmed'], true);

require dirname(__DIR__) . '/layout/header.php';

$paymentBadge = static function (string $status): string {
    return match ($status) {
        'confirmed' => 'confirmed',
        'pending' => 'pending-payment',
        'refunded' => 'refunded',
        default => 'failed',
    };
};
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

  <h2>Payments</h2>
  <?php if ($payments === []): ?>
    <p class="muted">No payments recorded yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Amount</th>
            <th scope="col">Method</th>
            <th scope="col">Status</th>
            <th scope="col">Reference</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= View::e($p['paid_at'] ?? $p['created_at']) ?></td>
              <td><?= View::e(number_format((float) $p['amount'], 2)) ?></td>
              <td><?= View::e(str_replace('_', ' ', (string) $p['method'])) ?></td>
              <td><span class="badge badge-<?= View::e($paymentBadge((string) $p['status'])) ?>">
                  <?= View::e(ucfirst((string) $p['status'])) ?></span></td>
              <td><?= View::e($p['mpesa_receipt'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ((string) ($payments[0]['status'] ?? '') === 'pending'): ?>
      <p class="muted">A pending payment is waiting for office confirmation. Your balance updates once it is confirmed.</p>
    <?php endif; ?>
  <?php endif; ?>

  <div class="btn-row">
    <?php if ($payable && Guard::hasRole('client')): ?>
      <a class="btn" href="<?= View::e(url('/payment/' . (int) $booking['id'])) ?>">Pay with M-Pesa</a>
    <?php endif; ?>
    <a class="btn btn-secondary" href="<?= View::e(url('/booking/' . rawurlencode((string) $booking['booking_ref']) . '/confirm')) ?>">
      View confirmation
    </a>
    <?php if ($cancellable): ?>
      <form method="post" action="<?= View::e(url('/bookings/' . (int) $booking['id'] . '/cancel')) ?>"
            class="inline-form" data-confirm="Cancel this booking? The dates will be released.">
        <?= Csrf::field('booking_cancel') ?>
        <button class="btn btn-secondary" type="submit">Cancel booking</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
