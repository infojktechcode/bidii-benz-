<?php
/** @var string $title */
/** @var list<array<string, mixed>> $clients */
use App\Core\View;

$clients = $clients ?? [];
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <div class="page-head">
    <h1>Clients</h1>
  </div>

  <p class="muted"><?= View::e(count($clients)) ?> registered client<?= count($clients) === 1 ? '' : 's' ?>.
     Select a client to see their bookings and payment history.</p>

  <?php if ($clients === []): ?>
    <p class="alert alert-error">No clients registered yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Client</th>
            <th scope="col">Phone</th>
            <th scope="col">ID number</th>
            <th scope="col">City</th>
            <th scope="col">Bookings</th>
            <th scope="col">Total paid</th>
            <th scope="col">Account</th>
            <th scope="col">Joined</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td>
                <a href="<?= View::e(url('/admin/clients/' . (int) $c['id'])) ?>"><?= View::e($c['full_name']) ?></a>
                <br><span class="muted"><?= View::e($c['email']) ?></span>
              </td>
              <td><?= View::e($c['phone']) ?></td>
              <td><?= View::e($c['id_number']) ?></td>
              <td><?= View::e($c['city'] ?? '—') ?></td>
              <td><?= View::e((int) $c['booking_count']) ?></td>
              <td>KES <?= View::e(number_format((float) $c['total_paid'], 2)) ?></td>
              <td><span class="badge badge-<?= View::e($c['status'] === 'active' ? 'confirmed' : 'cancelled') ?>">
                  <?= View::e(ucfirst((string) $c['status'])) ?></span></td>
              <td><?= View::e(date('Y-m-d', strtotime((string) $c['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
