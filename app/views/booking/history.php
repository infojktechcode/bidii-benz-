<?php
/** @var string $title */
/** @var list<array<string, mixed>> $bookings */
/** @var array{upcoming:int, active:int, completed:int, outstanding:float} $summary */
/** @var array<string, mixed>|null $spotlight */
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$bookings = $bookings ?? [];
$summary = $summary ?? ['upcoming' => 0, 'active' => 0, 'completed' => 0, 'outstanding' => 0.0];
$spotlight = $spotlight ?? null;

$nameParts = preg_split('/\s+/', trim((string) Session::get('user_name', ''))) ?: [];
$firstName = $nameParts[0] ?? '';
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <div class="page-head">
    <div>
      <h1>Welcome back<?= $firstName !== '' ? ', ' . View::e($firstName) : '' ?></h1>
      <p class="dash-note">Your rentals, balances and payments in one place.</p>
    </div>
  </div>

  <nav class="action-tiles" aria-label="Quick actions">
    <a class="action-tile" href="<?= View::e(url('/cars')) ?>">Browse Fleet</a>
    <a class="action-tile" href="<?= View::e(url('/cars')) ?>">New Booking</a>
    <a class="action-tile" href="<?= View::e(url('/bookings')) ?>"<?= $at('/bookings', true) ?>>My Bookings</a>
    <a class="action-tile" href="<?= View::e(url('/account')) ?>">Account</a>
  </nav>

  <h2>Summary</h2>
  <div class="stat-grid">
    <div class="stat kpi-card"><span class="stat-value"><?= View::e((int) $summary['upcoming']) ?></span>
      <span class="stat-label">Upcoming bookings</span></div>
    <div class="stat kpi-card"><span class="stat-value"><?= View::e((int) $summary['active']) ?></span>
      <span class="stat-label">Active bookings</span></div>
    <div class="stat kpi-card"><span class="stat-value"><?= View::e((int) $summary['completed']) ?></span>
      <span class="stat-label">Completed bookings</span></div>
    <div class="stat kpi-card stat-accent"><span class="stat-value">KES <?= View::e(number_format((float) $summary['outstanding'], 2)) ?></span>
      <span class="stat-label">Outstanding balance</span></div>
  </div>

  <?php if ($spotlight !== null): ?>
    <h2>Upcoming booking</h2>
    <article class="spotlight" aria-label="Upcoming booking">
      <div class="spotlight-top">
        <p class="spotlight-title">
          <a href="<?= View::e(url('/bookings/' . (int) $spotlight['id'])) ?>">
            <?= View::e($spotlight['make'] . ' ' . $spotlight['model']) ?></a>
          <span class="muted">&middot; <?= View::e($spotlight['booking_ref']) ?></span>
        </p>
        <?= $statusBadge((string) $spotlight['status']) ?>
      </div>
      <dl class="spotlight-grid">
        <div><dt>Booking reference</dt><dd><?= View::e($spotlight['booking_ref']) ?></dd></div>
        <div><dt>Pickup</dt><dd><?= View::e($spotlight['pickup_date']) ?></dd></div>
        <div><dt>Return</dt><dd><?= View::e($spotlight['return_date']) ?></dd></div>
        <div><dt>Amount</dt><dd>KES <?= View::e(number_format((float) $spotlight['total_amount'], 2)) ?></dd></div>
        <div><dt>Balance</dt><dd><?= (float) $spotlight['balance'] > 0.01
            ? 'KES ' . View::e(number_format((float) $spotlight['balance'], 2)) . ' due'
            : 'Settled' ?></dd></div>
      </dl>
      <div class="spotlight-actions">
        <a class="btn btn-small" href="<?= View::e(url('/bookings/' . (int) $spotlight['id'])) ?>">View booking</a>
        <?php if ((float) $spotlight['balance'] > 0.01
            && in_array((string) $spotlight['status'], \App\Services\PaymentService::PAYABLE_STATUSES, true)): ?>
          <a class="btn btn-secondary btn-small" href="<?= View::e(url('/payment/' . (int) $spotlight['id'])) ?>">Pay now</a>
        <?php endif; ?>
      </div>
    </article>
  <?php endif; ?>

  <h2>Recent bookings</h2>
  <?php if ($bookings === []): ?>
    <div class="empty-state">
      <p>You have no bookings yet. Browse the fleet and reserve a Mercedes-Benz for your next trip.</p>
      <a class="btn" href="<?= View::e(url('/cars')) ?>">Browse the fleet</a>
      <a class="btn btn-secondary" href="<?= View::e(url('/cars')) ?>">Book a vehicle</a>
    </div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Your bookings table">
      <table class="table data-cards">
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
              <td data-label="Reference"><?= View::e($b['booking_ref']) ?></td>
              <td data-label="Vehicle"><span><?= View::e($b['make'] . ' ' . $b['model']) ?><br>
                  <span class="muted"><?= View::e($b['registration_plate']) ?></span></span></td>
              <td data-label="Pickup"><?= View::e($b['pickup_date']) ?></td>
              <td data-label="Return"><?= View::e($b['return_date']) ?></td>
              <td data-label="Total">KES <?= View::e(number_format((float) $b['total_amount'], 2)) ?></td>
              <td data-label="Balance">KES <?= View::e(number_format((float) ($b['balance'] ?? 0), 2)) ?></td>
              <td data-label="Status"><?= $statusBadge((string) $b['status']) ?></td>
              <td data-label="Actions">
                <a href="<?= View::e(url('/bookings/' . (int) $b['id'])) ?>">View</a>
                <?php if ((float) ($b['balance'] ?? 0) > 0.01
                    && in_array((string) $b['status'], \App\Services\PaymentService::PAYABLE_STATUSES, true)): ?>
                  &middot; <a href="<?= View::e(url('/payment/' . (int) $b['id'])) ?>">Pay</a>
                <?php endif; ?>
                <?php if (in_array($b['status'], ['pending_payment', 'confirmed'], true)): ?>
                  &middot;
                  <form method="post" action="<?= View::e(url('/bookings/' . (int) $b['id'] . '/cancel')) ?>" class="inline-form"
                        data-confirm="Cancel this booking? The dates will be released.">
                    <?= Csrf::field('booking_cancel') ?>
                    <button class="link-btn" type="submit">Cancel</button>
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
