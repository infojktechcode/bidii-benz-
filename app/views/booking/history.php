<?php
/** @var string $title */
/** @var list<array<string, mixed>> $bookings */
use App\Core\View;

$bookings = $bookings ?? [];
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <h1>My bookings</h1>

  <?php if ($bookings === []): ?>
    <p class="alert alert-error">You have no bookings yet.</p>
    <p><a class="btn" href="<?= View::e(url('/cars')) ?>">Browse the fleet</a></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Vehicle</th>
            <th scope="col">Pickup</th>
            <th scope="col">Return</th>
            <th scope="col">Total</th>
            <th scope="col">Balance</th>
            <th scope="col">Status</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $b): ?>
            <tr>
              <td><?= View::e($b['booking_ref']) ?></td>
              <td><?= View::e($b['make'] . ' ' . $b['model']) ?><br>
                  <span class="muted"><?= View::e($b['registration_plate']) ?></span></td>
              <td><?= View::e($b['pickup_date']) ?></td>
              <td><?= View::e($b['return_date']) ?></td>
              <td><?= View::e(number_format((float) $b['total_amount'], 2)) ?></td>
              <td><?= View::e(number_format((float) ($b['balance'] ?? 0), 2)) ?></td>
              <td><span class="badge badge-<?= View::e(str_replace('_', '-', (string) $b['status'])) ?>">
                  <?= View::e(ucwords(str_replace('_', ' ', (string) $b['status']))) ?></span></td>
              <td>
                <a href="<?= View::e(url('/bookings/' . (int) $b['id'])) ?>">View</a>
                <?php if ((float) ($b['balance'] ?? 0) > 0.01
                    && in_array((string) $b['status'], \App\Services\PaymentService::PAYABLE_STATUSES, true)): ?>
                  &middot; <a href="<?= View::e(url('/payment/' . (int) $b['id'])) ?>">Pay</a>
                <?php endif; ?>
                <?php if (in_array($b['status'], ['pending_payment', 'confirmed'], true)): ?>
                  &middot;
                  <form method="post" action="<?= View::e(url('/bookings/' . (int) $b['id'] . '/cancel')) ?>" class="inline-form">
                    <?= \App\Core\Csrf::field('booking_cancel') ?>
                    <button class="link-btn" type="submit"
                            onclick="return confirm('Cancel this booking? The dates will be released.');">Cancel</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
