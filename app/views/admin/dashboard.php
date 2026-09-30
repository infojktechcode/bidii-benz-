<?php
/** @var string $title */
/** @var array<string, mixed> $stats */
/** @var array<string, int> $by_status */
/** @var list<array<string, mixed>> $recent */
use App\Core\View;

$recent = $recent ?? [];
$byStatus = $by_status ?? [];
require dirname(__DIR__) . '/layout/header.php';

$statusLabels = [
    'pending_payment' => 'Pending payment',
    'confirmed' => 'Confirmed',
    'active' => 'Active hire',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show' => 'No show',
];
?>
<section>
  <h1>Dashboard</h1>

  <div class="stat-grid">
    <div class="stat"><span class="stat-value"><?= View::e((int) $stats['cars']) ?></span>
      <span class="stat-label">Vehicles</span></div>
    <div class="stat"><span class="stat-value"><?= View::e((int) $stats['clients']) ?></span>
      <span class="stat-label">Clients</span></div>
    <div class="stat"><span class="stat-value"><?= View::e((int) $stats['bookings']) ?></span>
      <span class="stat-label">Bookings</span></div>
    <div class="stat"><span class="stat-value"><?= View::e((int) $stats['pending_payments']) ?></span>
      <span class="stat-label">Payments awaiting confirmation</span></div>
    <div class="stat"><span class="stat-value">KES <?= View::e(number_format((float) $stats['outstanding'], 2)) ?></span>
      <span class="stat-label">Outstanding balance</span></div>
  </div>

  <h2>Bookings by status</h2>
  <ul class="chip-list">
    <?php foreach ($statusLabels as $key => $label): ?>
      <li class="badge badge-<?= View::e(str_replace('_', '-', $key)) ?>">
        <?= View::e($label) ?>: <?= View::e((int) ($byStatus[$key] ?? 0)) ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="btn-row">
    <a class="btn" href="<?= View::e(url('/admin/bookings')) ?>">Manage bookings</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/payments')) ?>">Manage payments</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/vehicles')) ?>">Manage vehicles</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/clients')) ?>">Manage clients</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/reports')) ?>">Reports</a>
  </div>

  <h2>Recent bookings</h2>
  <?php if ($recent === []): ?>
    <div class="empty-state"><p>No bookings yet.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Client</th>
            <th scope="col">Vehicle</th>
            <th scope="col">Dates</th>
            <th scope="col">Total</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $b): ?>
            <tr>
              <td><a href="<?= View::e(url('/admin/bookings/' . (int) $b['id'])) ?>"><?= View::e($b['booking_ref']) ?></a></td>
              <td><?= View::e($b['full_name']) ?></td>
              <td><?= View::e($b['make'] . ' ' . $b['model']) ?><br>
                  <span class="muted"><?= View::e($b['registration_plate']) ?></span></td>
              <td><?= View::e($b['pickup_date']) ?> &rarr; <?= View::e($b['return_date']) ?></td>
              <td><?= View::e(number_format((float) $b['total_amount'], 2)) ?></td>
              <td><span class="badge badge-<?= View::e(str_replace('_', '-', (string) $b['status'])) ?>">
                  <?= View::e(ucwords(str_replace('_', ' ', (string) $b['status']))) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
