<?php
/** @var string $title */
/** @var array<string, mixed> $stats */
/** @var array<string, int> $by_status */
/** @var list<array<string, mixed>> $recent */
/** @var list<array<string, mixed>> $recent_payments */
/** @var string $today */
use App\Core\View;

$recent = $recent ?? [];
$recentPayments = $recent_payments ?? [];
$byStatus = $by_status ?? [];

// Payment status reuses the shared badge classes (same vocabulary as payments.php).
$payBadge = static fn (string $s): string => $s === 'confirmed' ? 'confirmed'
    : ($s === 'pending' ? 'pending-payment' : ($s === 'refunded' ? 'no-show' : 'cancelled'));
$payLabel = static fn (string $s): string => ucfirst($s);

$fleetActive = (int) ($stats['fleet_active'] ?? 0);
$available = (int) ($stats['available_today'] ?? 0);
$pct = $fleetActive > 0 ? (int) floor(($available / $fleetActive) * 10) * 10 : 0;
$pct = max(0, min(100, $pct));
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <div class="page-head">
    <div>
      <h1>Dashboard</h1>
      <p class="dash-note">Operations overview &middot; <?= View::e(date('j M Y')) ?></p>
    </div>
  </div>

  <h2>Key figures</h2>
  <div class="stat-grid">
    <div class="stat kpi-card stat-accent"><span class="stat-value">KES <?= View::e(number_format((float) $stats['outstanding'], 2)) ?></span>
      <span class="stat-label">Outstanding balance</span></div>
    <div class="stat kpi-card stat-accent"><span class="stat-value"><?= View::e((int) $stats['pending_payments']) ?></span>
      <span class="stat-label">Payments awaiting confirmation</span></div>
    <div class="stat kpi-card"><span class="stat-value"><?= View::e((int) $stats['active_rentals']) ?></span>
      <span class="stat-label">Active rentals</span></div>
    <div class="stat kpi-card"><span class="stat-value"><?= View::e($available) ?></span>
      <span class="stat-label">Vehicles available today</span></div>
    <div class="stat kpi-card"><span class="stat-value"><?= View::e((int) $stats['bookings']) ?></span>
      <span class="stat-label">Total bookings</span></div>
    <div class="stat kpi-card"><span class="stat-value"><?= View::e((int) $stats['clients']) ?></span>
      <span class="stat-label">Clients</span></div>
  </div>

  <div class="two-col dash-section">
    <section aria-labelledby="fleet-h">
      <h2 id="fleet-h">Fleet availability</h2>
      <div class="avail-bar avail-<?= View::e((string) $pct) ?>" aria-hidden="true"><span></span></div>
      <p class="avail-note"><?= View::e($available) ?> of <?= View::e($fleetActive) ?> active vehicles
        are free today (<?= View::e($today) ?>).</p>
      <p class="avail-note"><a href="<?= View::e(url('/admin/vehicles')) ?>">Manage vehicles</a></p>
    </section>
    <section aria-labelledby="pending-h">
      <h2 id="pending-h">Pending actions</h2>
      <?php $pendingPayments = (int) $stats['pending_payments'];
            $pendingBookings = (int) $stats['pending_bookings']; ?>
      <?php if ($pendingPayments === 0 && $pendingBookings === 0): ?>
        <p class="dash-note">All clear &mdash; nothing is waiting for confirmation.</p>
      <?php else: ?>
        <ul class="pending-list">
          <?php if ($pendingPayments > 0): ?>
            <li><a href="<?= View::e(url('/admin/payments')) ?>">
              <span><span class="count"><?= View::e($pendingPayments) ?></span>
                payment<?= $pendingPayments === 1 ? '' : 's' ?> awaiting confirmation</span>
              <span aria-hidden="true">&rarr;</span></a></li>
          <?php endif; ?>
          <?php if ($pendingBookings > 0): ?>
            <li><a href="<?= View::e(url('/admin/bookings?status=pending_payment')) ?>">
              <span><span class="count"><?= View::e($pendingBookings) ?></span>
                booking<?= $pendingBookings === 1 ? '' : 's' ?> awaiting confirmation</span>
              <span aria-hidden="true">&rarr;</span></a></li>
          <?php endif; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <h2>Bookings by status</h2>
  <?php $anyStatus = false; ?>
  <ul class="chip-list">
    <?php foreach ($statusLabels as $key => $label): ?>
      <?php if ((int) ($byStatus[$key] ?? 0) > 0): $anyStatus = true; ?>
        <li class="badge badge-<?= View::e(str_replace('_', '-', $key)) ?>">
          <?= View::e($label) ?>: <?= View::e((int) $byStatus[$key]) ?>
        </li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ul>
  <?php if (!$anyStatus): ?>
    <p class="dash-note">No bookings yet.</p>
  <?php endif; ?>

  <h2>Quick actions</h2>
  <div class="btn-row">
    <a class="btn" href="<?= View::e(url('/admin/bookings')) ?>">Manage bookings</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/payments')) ?>">Manage payments</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/vehicles')) ?>">Manage vehicles</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/clients')) ?>">Manage clients</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/reports')) ?>">Reports</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/admin/vehicles/create')) ?>">Add vehicle</a>
    <a class="btn btn-ghost" href="<?= View::e(url('/admin/audit')) ?>">Audit log</a>
  </div>

  <h2>Recent bookings</h2>
  <?php if ($recent === []): ?>
    <div class="empty-state"><p>No bookings yet.</p></div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Recent bookings table">
      <table class="table data-cards">
        <thead>
          <tr>
            <th scope="col">Reference</th>
            <th scope="col">Client</th>
            <th scope="col">Vehicle</th>
            <th scope="col">Dates</th>
            <th scope="col">Total</th>
            <th scope="col">Balance</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $b): ?>
            <tr>
              <td data-label="Reference"><a href="<?= View::e(url('/admin/bookings/' . (int) $b['id'])) ?>"><?= View::e($b['booking_ref']) ?></a></td>
              <td data-label="Client"><?= View::e($b['full_name']) ?></td>
              <td data-label="Vehicle"><span><?= View::e($b['make'] . ' ' . $b['model']) ?><br>
                  <span class="muted"><?= View::e($b['registration_plate']) ?></span></span></td>
              <td data-label="Dates"><?= View::e($b['pickup_date']) ?> &rarr; <?= View::e($b['return_date']) ?></td>
              <td data-label="Total">KES <?= View::e(number_format((float) $b['total_amount'], 2)) ?></td>
              <td data-label="Balance">KES <?= View::e(number_format((float) ($b['balance'] ?? 0), 2)) ?></td>
              <td data-label="Status"><?= $statusBadge((string) $b['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h2>Payment activity</h2>
  <?php if ($recentPayments === []): ?>
    <div class="empty-state"><p>No payment activity yet.</p></div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Payment activity table">
      <table class="table data-cards">
        <thead>
          <tr>
            <th scope="col">Booking</th>
            <th scope="col">Client</th>
            <th scope="col">Amount</th>
            <th scope="col">Status</th>
            <th scope="col">Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentPayments as $p): ?>
            <tr>
              <td data-label="Booking"><a href="<?= View::e(url('/admin/bookings/' . (int) $p['booking_id'])) ?>"><?= View::e($p['booking_ref']) ?></a></td>
              <td data-label="Client"><?= View::e($p['full_name']) ?></td>
              <td data-label="Amount">KES <?= View::e(number_format((float) $p['amount'], 2)) ?></td>
              <td data-label="Status"><span class="badge badge-<?= View::e($payBadge((string) $p['status'])) ?>">
                  <?= View::e($payLabel((string) $p['status'])) ?></span></td>
              <td data-label="Date"><?= View::e(substr((string) $p['created_at'], 0, 10)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
