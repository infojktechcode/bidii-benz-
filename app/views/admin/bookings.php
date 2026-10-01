<?php
/** @var string $title */
/** @var list<array<string, mixed>> $bookings */
/** @var string|null $filter */
/** @var list<string> $statuses */
use App\Core\View;

$bookings = $bookings ?? [];
$filter = $filter ?? null;
$statuses = $statuses ?? [];
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
  <div class="page-head">
    <h1>Bookings</h1>
    <nav class="filter-nav" aria-label="Filter bookings by status">
      <a href="<?= View::e(url('/admin/bookings')) ?>" class="<?= $filter === null ? 'current' : '' ?>">All</a>
      <?php foreach ($statuses as $status): ?>
        <a href="<?= View::e(url('/admin/bookings?status=' . $status)) ?>"
           class="<?= $filter === $status ? 'current' : '' ?>"><?= View::e($statusLabels[$status]) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if ($bookings === []): ?>
    <div class="empty-state"><p>No bookings match this filter.</p></div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Bookings table">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Client</th>
            <th scope="col">Vehicle</th>
            <th scope="col">Dates</th>
            <th scope="col">Total</th>
            <th scope="col">Status</th>
            <th scope="col">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $b): ?>
            <tr>
              <td><a href="<?= View::e(url('/admin/bookings/' . (int) $b['id'])) ?>"><?= View::e($b['booking_ref']) ?></a></td>
              <td><?= View::e($b['full_name']) ?><br>
                  <span class="muted"><?= View::e($b['phone']) ?></span></td>
              <td><?= View::e($b['make'] . ' ' . $b['model']) ?><br>
                  <span class="muted"><?= View::e($b['registration_plate']) ?></span></td>
              <td><?= View::e($b['pickup_date']) ?> &rarr; <?= View::e($b['return_date']) ?></td>
              <td><?= View::e(number_format((float) $b['total_amount'], 2)) ?></td>
              <td><span class="badge badge-<?= View::e(str_replace('_', '-', (string) $b['status'])) ?>">
                  <?= View::e(ucwords(str_replace('_', ' ', (string) $b['status']))) ?></span></td>
              <td><?= View::e($b['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
