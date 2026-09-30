<?php
/** @var string $title */
/** @var array<string, mixed> $booking */
/** @var float $balance */
/** @var list<array<string, mixed>> $payments */
use App\Core\Csrf;
use App\Core\View;

$payments = $payments ?? [];
$status = (string) $booking['status'];

require dirname(__DIR__) . '/layout/header.php';

$action = static function (string $label, string $path, string $form, string $class = 'btn') {
    echo '<form method="post" action="' . View::e(url($path)) . '" class="inline-form">';
    echo Csrf::field($form);
    echo '<button class="' . View::e($class) . '" type="submit">' . View::e($label) . '</button>';
    echo '</form>';
};
?>
<section>
  <p><a href="<?= View::e(url('/admin/bookings')) ?>">&larr; Bookings</a></p>
  <div class="page-head">
    <h1>Booking <?= View::e($booking['booking_ref']) ?></h1>
    <span class="badge badge-<?= View::e(str_replace('_', '-', $status)) ?>">
      <?= View::e(ucwords(str_replace('_', ' ', $status))) ?></span>
  </div>

  <div class="two-col">
    <div>
      <h2>Booking</h2>
      <dl class="spec-list">
        <dt>Client</dt><dd><?= View::e($booking['full_name']) ?></dd>
        <dt>National ID</dt><dd><?= View::e($booking['id_number']) ?></dd>
        <dt>Email</dt><dd><?= View::e($booking['email']) ?></dd>
        <dt>Phone</dt><dd><?= View::e($booking['phone']) ?></dd>
        <dt>Vehicle</dt>
        <dd><?= View::e($booking['make'] . ' ' . $booking['model']) ?> (<?= View::e($booking['registration_plate']) ?>)</dd>
        <dt>Pickup</dt><dd><?= View::e($booking['pickup_date']) ?></dd>
        <dt>Return</dt><dd><?= View::e($booking['return_date']) ?></dd>
        <?php if (!empty($booking['pickup_location'])): ?>
          <dt>Pickup location</dt><dd><?= View::e($booking['pickup_location']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($booking['notes'])): ?>
          <dt>Notes</dt><dd><?= nl2br(View::e($booking['notes'])) ?></dd>
        <?php endif; ?>
        <?php if (!empty($booking['actual_return_at'])): ?>
          <dt>Actual return</dt><dd><?= View::e($booking['actual_return_at']) ?></dd>
        <?php endif; ?>
        <dt>Daily rate</dt><dd>KES <?= View::e(number_format((float) $booking['daily_rate'], 2)) ?></dd>
        <dt>Total</dt><dd>KES <?= View::e(number_format((float) $booking['total_amount'], 2)) ?></dd>
        <dt>Balance</dt><dd>KES <?= View::e(number_format((float) $balance, 2)) ?></dd>
      </dl>
    </div>

    <div>
      <h2>Payments</h2>
      <?php if ($payments === []): ?>
        <p class="muted">No payments recorded.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th scope="col">Amount</th><th scope="col">Method</th>
                  <th scope="col">Status</th><th scope="col">Receipt</th></tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $p): ?>
                <tr>
                  <td><?= View::e(number_format((float) $p['amount'], 2)) ?></td>
                  <td><?= View::e($p['method']) ?></td>
                  <td><span class="badge badge-<?= View::e($p['status'] === 'confirmed' ? 'confirmed' : ($p['status'] === 'pending' ? 'pending-payment' : 'cancelled')) ?>">
                      <?= View::e(ucfirst((string) $p['status'])) ?></span></td>
                  <td><?= View::e($p['mpesa_receipt'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <h2>Actions</h2>
  <div class="btn-row">
    <?php if ($status === 'pending_payment'): ?>
      <?php $action('Confirm booking', '/admin/bookings/' . (int) $booking['id'] . '/confirm', 'admin_booking_confirm'); ?>
    <?php endif; ?>
    <?php if ($status === 'confirmed'): ?>
      <?php $action('Start hire', '/admin/bookings/' . (int) $booking['id'] . '/start', 'admin_booking_start'); ?>
    <?php endif; ?>
    <?php if ($status === 'active'): ?>
      <?php $action('Record return & complete', '/admin/bookings/' . (int) $booking['id'] . '/complete', 'admin_booking_complete'); ?>
    <?php endif; ?>
    <?php if (in_array($status, ['pending_payment', 'confirmed', 'active'], true)): ?>
      <?php $action('Cancel booking', '/admin/bookings/' . (int) $booking['id'] . '/cancel', 'admin_booking_cancel', 'btn btn-secondary'); ?>
    <?php endif; ?>
    <?php if (in_array($status, ['pending_payment', 'confirmed'], true) && (float) $balance > 0.01): ?>
      <a class="btn btn-secondary" href="<?= View::e(url('/admin/payments')) ?>">Confirm a payment</a>
    <?php endif; ?>
  </div>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
