<?php
/** @var string $title */
/** @var list<array<string, mixed>> $payments */
/** @var string|null $filter */
/** @var float $outstanding */
use App\Core\Csrf;
use App\Core\Guard;
use App\Core\View;

$payments = $payments ?? [];
$filter = $filter ?? null;
require dirname(__DIR__) . '/layout/header.php';

$badge = static fn (string $s): string => $s === 'confirmed' ? 'confirmed'
    : ($s === 'pending' ? 'pending-payment' : ($s === 'refunded' ? 'no-show' : 'cancelled'));
?>
<section>
  <div class="page-head">
    <h1>Payments</h1>
    <nav class="filter-nav" aria-label="Filter payments by status">
      <a href="<?= View::e(url('/admin/payments')) ?>" class="<?= $filter === null ? 'current' : '' ?>">All</a>
      <?php foreach (['pending', 'confirmed', 'failed', 'refunded'] as $s): ?>
        <a href="<?= View::e(url('/admin/payments?status=' . $s)) ?>"
           class="<?= $filter === $s ? 'current' : '' ?>"><?= View::e(ucfirst($s)) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <p class="muted">Outstanding balance across all bookings: KES <?= View::e(number_format((float) $outstanding, 2)) ?></p>

  <?php if ($payments === []): ?>
    <p class="alert alert-error">No payments match this filter.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Booking</th>
            <th scope="col">Client</th>
            <th scope="col">Amount</th>
            <th scope="col">Method</th>
            <th scope="col">Status</th>
            <th scope="col">Receipt</th>
            <th scope="col">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><a href="<?= View::e(url('/admin/bookings/' . (int) $p['booking_id'])) ?>"><?= View::e($p['booking_ref']) ?></a></td>
              <td><?= View::e($p['full_name']) ?><br><span class="muted"><?= View::e($p['phone']) ?></span></td>
              <td><?= View::e(number_format((float) $p['amount'], 2)) ?></td>
              <td><?= View::e($p['method']) ?></td>
              <td><span class="badge badge-<?= View::e($badge((string) $p['status'])) ?>">
                  <?= View::e(ucfirst((string) $p['status'])) ?></span></td>
              <td><?= View::e($p['mpesa_receipt'] ?? '—') ?></td>
              <td>
                <?php if ($p['status'] === 'pending'): ?>
                  <form method="post" action="<?= View::e(url('/admin/payments/' . (int) $p['id'] . '/confirm')) ?>"
                        class="stacked-form">
                    <?= Csrf::field('admin_payment_confirm') ?>
                    <label class="sr-only" for="receipt-<?= View::e((string) $p['id']) ?>">Receipt</label>
                    <input id="receipt-<?= View::e((string) $p['id']) ?>" name="receipt" type="text"
                           maxlength="20" placeholder="Receipt no." required>
                    <button class="btn btn-small" type="submit">Confirm</button>
                  </form>
                <?php elseif ($p['status'] === 'confirmed' && Guard::hasRole('owner')): ?>
                  <form method="post" action="<?= View::e(url('/admin/payments/' . (int) $p['id'] . '/refund')) ?>"
                        class="stacked-form" data-confirm="Refund this payment?">
                    <?= Csrf::field('admin_payment_refund') ?>
                    <label class="sr-only" for="notes-<?= View::e((string) $p['id']) ?>">Reason</label>
                    <input id="notes-<?= View::e((string) $p['id']) ?>" name="notes" type="text"
                           maxlength="255" placeholder="Reason (optional)">
                    <button class="btn btn-small btn-secondary" type="submit">Refund</button>
                  </form>
                <?php elseif ($p['status'] === 'confirmed'): ?>
                  <span class="muted">Refund: owner only</span>
                <?php else: ?>
                  <span class="muted">—</span>
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
