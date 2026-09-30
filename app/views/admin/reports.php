<?php
/** @var string $title */
/** @var array<string, string> $errors */
/** @var string|null $from */
/** @var string|null $to */
/** @var string $raw_from */
/** @var string $raw_to */
/** @var array<string, mixed>|null $summary */
use App\Core\View;

$errors = $errors ?? [];
$from = $from ?? null;
$to = $to ?? null;
$raw_from = $raw_from ?? '';
$raw_to = $raw_to ?? '';
$summary = $summary ?? null;

require dirname(__DIR__) . '/layout/header.php';

$bookingBadge = static fn (string $s): string => str_replace('_', '-', $s);

$period = 'All time';
if ($from !== null || $to !== null) {
    $period = ($from ?? 'the beginning') . ' to ' . ($to ?? 'today');
}
?>
<section>
  <div class="page-head">
    <h1>Reports</h1>
    <nav class="filter-nav" aria-label="Report shortcuts">
      <a href="<?= View::e(url('/admin/reports')) ?>">All time</a>
      <a href="<?= View::e(url('/admin/reports?from=' . date('Y-m-d', strtotime('first day of this month')) . '&to=' . date('Y-m-d'))) ?>">This month</a>
      <a href="<?= View::e(url('/admin/reports?from=' . date('Y-m-d', strtotime('-30 days')) . '&to=' . date('Y-m-d'))) ?>">Last 30 days</a>
    </nav>
  </div>

  <form method="get" action="<?= View::e(url('/admin/reports')) ?>" class="filter-form">
    <div class="filter-fields">
      <div>
        <label for="from">From</label>
        <input id="from" name="from" type="date" max="<?= View::e(date('Y-m-d')) ?>"
               value="<?= View::e($raw_from) ?>">
        <?php if (isset($errors['from'])): ?>
          <span class="field-error"><?= View::e($errors['from']) ?></span>
        <?php endif; ?>
      </div>
      <div>
        <label for="to">To</label>
        <input id="to" name="to" type="date" max="<?= View::e(date('Y-m-d')) ?>"
               value="<?= View::e($raw_to) ?>">
        <?php if (isset($errors['to'])): ?>
          <span class="field-error"><?= View::e($errors['to']) ?></span>
        <?php endif; ?>
      </div>
      <div class="filter-actions">
        <button class="btn" type="submit">Apply range</button>
        <a class="btn btn-secondary" href="<?= View::e(url('/admin/reports')) ?>">Reset</a>
      </div>
    </div>
    <?php if (isset($errors['range'])): ?>
      <p class="alert alert-error" role="alert"><?= View::e($errors['range']) ?></p>
    <?php endif; ?>
  </form>

  <?php if ($summary === null): ?>
    <p class="alert alert-error" role="alert">Fix the date fields above to run the report.</p>
  <?php else: ?>
    <p class="muted">
      Period: <?= View::e($period) ?>.
      Bookings and outstanding balances are counted by booking date;
      payments are counted by the date the payment was recorded.
    </p>

    <div class="stat-grid">
      <div class="stat"><span class="stat-value"><?= View::e((int) $summary['bookings']['count']) ?></span>
        <span class="stat-label">Bookings</span></div>
      <div class="stat"><span class="stat-value">KES <?= View::e(number_format((float) $summary['bookings']['revenue'], 2)) ?></span>
        <span class="stat-label">Booking revenue (excl. cancelled)</span></div>
      <div class="stat"><span class="stat-value"><?= View::e((int) $summary['payments']['count']) ?></span>
        <span class="stat-label">Payments recorded</span></div>
      <div class="stat"><span class="stat-value">KES <?= View::e(number_format((float) $summary['payments']['total'], 2)) ?></span>
        <span class="stat-label">Payments value</span></div>
      <div class="stat"><span class="stat-value">KES <?= View::e(number_format((float) $summary['outstanding'], 2)) ?></span>
        <span class="stat-label">Outstanding (bookings in period)</span></div>
      <div class="stat"><span class="stat-value">KES <?= View::e(number_format((float) $summary['outstanding_all'], 2)) ?></span>
        <span class="stat-label">Outstanding (all bookings)</span></div>
      <div class="stat"><span class="stat-value"><?= View::e((int) $summary['bookings']['clients']) ?></span>
        <span class="stat-label">Clients who booked</span></div>
      <div class="stat"><span class="stat-value"><?= View::e((int) $summary['bookings']['vehicles']) ?></span>
        <span class="stat-label">Vehicles hired</span></div>
    </div>

    <h2>Bookings by status</h2>
    <?php if ($summary['bookings_by_status'] === []): ?>
      <p class="muted">No bookings in this period.</p>
    <?php else: ?>
      <ul class="chip-list">
        <?php foreach ($summary['bookings_by_status'] as $status => $count): ?>
          <li class="badge badge-<?= View::e($bookingBadge((string) $status)) ?>">
            <?= View::e(ucwords(str_replace('_', ' ', (string) $status))) ?>: <?= View::e((int) $count) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Payments by status</h2>
    <?php if ($summary['payments_count_by_status'] === []): ?>
      <p class="muted">No payments recorded in this period.</p>
    <?php else: ?>
      <ul class="chip-list">
        <?php foreach ($summary['payments_count_by_status'] as $status => $count): ?>
          <li class="badge badge-<?= View::e(match ($status) {
              'confirmed' => 'confirmed',
              'pending' => 'pending-payment',
              'refunded' => 'refunded',
              default => 'failed',
          }) ?>">
            <?= View::e(ucfirst((string) $status)) ?>: <?= View::e((int) $count) ?>
            (KES <?= View::e(number_format((float) ($summary['payments_by_status'][$status] ?? 0), 2)) ?>)
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>By vehicle</h2>
    <?php if ($summary['by_vehicle'] === []): ?>
      <p class="muted">No vehicle activity in this period.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Vehicle</th>
              <th scope="col">Bookings</th>
              <th scope="col">Revenue</th>
              <th scope="col">Outstanding</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary['by_vehicle'] as $v): ?>
              <tr>
                <td><?= View::e($v['make'] . ' ' . $v['model']) ?><br>
                    <span class="muted"><?= View::e($v['plate']) ?></span></td>
                <td><?= View::e((int) $v['bookings']) ?></td>
                <td><?= View::e(number_format((float) $v['total'], 2)) ?></td>
                <td><?= View::e(number_format((float) $v['outstanding'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <h2>By client</h2>
    <?php if ($summary['by_client'] === []): ?>
      <p class="muted">No client activity in this period.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Client</th>
              <th scope="col">Bookings</th>
              <th scope="col">Total</th>
              <th scope="col">Outstanding</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary['by_client'] as $c): ?>
              <tr>
                <td><?= View::e($c['full_name']) ?></td>
                <td><?= View::e((int) $c['bookings']) ?></td>
                <td><?= View::e(number_format((float) $c['total'], 2)) ?></td>
                <td><?= View::e(number_format((float) $c['outstanding'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
