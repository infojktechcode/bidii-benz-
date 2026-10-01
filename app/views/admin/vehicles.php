<?php
/** @var string $title */
/** @var list<array<string, mixed>> $cars */
use App\Core\Csrf;
use App\Core\View;

$cars = $cars ?? [];
require dirname(__DIR__) . '/layout/header.php';
?>
<section>
  <div class="page-head">
    <h1>Vehicles</h1>
    <a class="btn" href="<?= View::e(url('/admin/vehicles/create')) ?>">Add vehicle</a>
  </div>

  <?php if ($cars === []): ?>
    <div class="empty-state"><p>No vehicles in the fleet yet.</p></div>
  <?php else: ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Vehicles table">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Vehicle</th>
            <th scope="col">Plate</th>
            <th scope="col">Seats</th>
            <th scope="col">Price / day</th>
            <th scope="col">Status</th>
            <th scope="col">Photo</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cars as $car): ?>
            <tr>
              <td><?= View::e($car['make'] . ' ' . $car['model']) ?><br>
                  <span class="muted"><?= View::e(($car['year'] ?? '') . ' · ' . ($car['body_type'] ?? '')) ?></span></td>
              <td><?= View::e($car['registration_plate']) ?></td>
              <td><?= View::e($car['seats']) ?></td>
              <td><?= View::e(number_format((float) $car['daily_price'], 2)) ?></td>
              <td><span class="badge badge-<?= View::e($car['status'] === 'active' ? 'confirmed' : ($car['status'] === 'maintenance' ? 'pending-payment' : 'cancelled')) ?>">
                  <?= View::e(ucfirst((string) $car['status'])) ?></span></td>
              <td><?= !empty($car['image_path']) ? 'Yes' : 'No' ?></td>
              <td>
                <a href="<?= View::e(url('/admin/vehicles/' . (int) $car['id'] . '/edit')) ?>">Edit</a>
                <form method="post" action="<?= View::e(url('/admin/vehicles/' . (int) $car['id'] . '/delete')) ?>"
                      class="inline-form" data-confirm="Remove this vehicle from the fleet?">
                  <?= Csrf::field('admin_vehicle_delete') ?>
                  <button class="link-btn danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
