<?php
/** @var string $title */
/** @var array<string, mixed> $client */
/** @var list<array<string, mixed>> $bookings */
/** @var list<array<string, mixed>> $payments */
use App\Core\View;

$bookings = $bookings ?? [];
$payments = $payments ?? [];
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <div class="page-head">
    <h1><?= View::e($client['full_name']) ?></h1>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/clients')) ?>">&larr; All clients</a>
  </div>

  <div class="stat-grid">
    <div class="stat"><span class="stat-value"><?= View::e(count($bookings)) ?></span>
      <span class="stat-label">Bookings</span></div>
    <div class="stat"><span class="stat-value">KES <?= View::e(number_format(
        array_sum(array_map(
            static fn (array $p): float => $p['status'] === 'confirmed' ? (float) $p['amount'] : 0.0,
            $payments
        )),
        2
    )) ?></span><span class="stat-label">Total paid (confirmed)</span></div>
    <div class="stat"><span class="stat-value"><?= View::e(ucfirst((string) $client['status'])) ?></span>
      <span class="stat-label">Account status</span></div>
    <div class="stat"><span class="stat-value"><?= View::e(date('Y-m-d', strtotime((string) $client['created_at']))) ?></span>
      <span class="stat-label">Registered</span></div>
  </div>

  <h2>Contact details</h2>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Contact details table">
    <table class="table">
      <tbody>
        <tr><th scope="row">Email</th><td><?= View::e($client['email']) ?></td></tr>
        <tr><th scope="row">Phone</th><td><?= View::e($client['phone']) ?></td></tr>
        <tr><th scope="row">ID (<?= View::e(str_replace('_', ' ', (string) $client['id_type'])) ?>)</th>
            <td><?= View::e($client['id_number']) ?></td></tr>
        <tr><th scope="row">City</th><td><?= View::e($client['city'] ?? '—') ?></td></tr>
        <tr><th scope="row">Address</th><td><?= View::e($client['address'] ?? '—') ?></td></tr>
      </tbody>
    </table>
  </div>

  <h2>Bookings</h2>
  <?php if ($bookings === []): ?>
    <div class="empty-state"><p>No bookings yet.</p></div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Client bookings table">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Reference</th>
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

  <h2>Payments</h2>
  <?php if ($payments === []): ?>
    <div class="empty-state"><p>No payments recorded.</p></div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Client payments table">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Booking</th>
            <th scope="col">Amount</th>
            <th scope="col">Method</th>
            <th scope="col">Status</th>
            <th scope="col">Receipt</th>
            <th scope="col">Recorded</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><a href="<?= View::e(url('/admin/bookings/' . (int) $p['booking_id'])) ?>"><?= View::e($p['booking_ref']) ?></a></td>
              <td><?= View::e(number_format((float) $p['amount'], 2)) ?></td>
              <td><?= View::e($p['method']) ?></td>
              <td><span class="badge badge-<?= View::e($p['status'] === 'confirmed' ? 'confirmed'
                  : ($p['status'] === 'pending' ? 'pending-payment' : 'cancelled')) ?>">
                  <?= View::e(ucfirst((string) $p['status'])) ?></span></td>
              <td><?= View::e($p['mpesa_receipt'] ?? '—') ?></td>
              <td><?= View::e($p['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
