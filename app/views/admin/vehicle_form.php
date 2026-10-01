<?php
/** @var string $title */
/** @var array<string, mixed>|null $car */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
use App\Core\Csrf;
use App\Core\View;

$car = $car ?? null;
$errors = $errors ?? [];
$old = $old ?? [];
$isEdit = $car !== null;

$v = static function (string $field, string $default = '') use ($old, $car): string {
    if (array_key_exists($field, $old)) {
        return $old[$field];
    }
    $value = $car[$field] ?? $default;
    return $value === null ? '' : (string) $value;
};

$err = static fn (string $f): string => isset($errors[$f])
    ? '<span class="field-error" id="' . $f . '-error">' . View::e($errors[$f]) . '</span>'
    : '';
$eattr = static fn (string $f): string => isset($errors[$f])
    ? ' aria-invalid="true" aria-describedby="' . $f . '-error"'
    : '';

$action = $isEdit
    ? url('/admin/vehicles/' . (int) $car['id'])
    : url('/admin/vehicles');
$csrfForm = $isEdit ? 'admin_vehicle_update' : 'admin_vehicle_create';

require dirname(__DIR__) . '/layout/header.php';
?>
<section class="auth-card auth-card-wide">
  <h1><?= $isEdit ? 'Edit vehicle' : 'Add vehicle' ?></h1>

  <?php if ($errors !== []): ?>
    <p class="alert alert-error" role="alert">Please fix the highlighted fields.</p>
  <?php endif; ?>

  <form method="post" action="<?= View::e($action) ?>" enctype="multipart/form-data" novalidate>
    <?= Csrf::field($csrfForm) ?>

    <label for="model">Model</label>
    <input id="model" name="model" type="text" maxlength="60" required
           placeholder="C200" value="<?= View::e($v('model')) ?>"<?= $eattr('model') ?>>
    <?= $err('model') ?>

    <label for="registration_plate">Registration plate</label>
    <input id="registration_plate" name="registration_plate" type="text" maxlength="20" required
           placeholder="KDG123A" value="<?= View::e($v('registration_plate')) ?>"<?= $eattr('registration_plate') ?>>
    <?= $err('registration_plate') ?>

    <label for="year">Year <span class="optional">(optional)</span></label>
    <input id="year" name="year" type="number" min="1980" max="<?= View::e((string) ((int) date('Y') + 1)) ?>"
           value="<?= View::e($v('year')) ?>"<?= $eattr('year') ?>>
    <?= $err('year') ?>

    <label for="body_type">Body type <span class="optional">(optional)</span></label>
    <input id="body_type" name="body_type" type="text" maxlength="40"
           placeholder="Sedan" value="<?= View::e($v('body_type')) ?>"<?= $eattr('body_type') ?>>
    <?= $err('body_type') ?>

    <label for="seats">Seats</label>
    <input id="seats" name="seats" type="number" min="1" max="20" required
           value="<?= View::e($v('seats', '5')) ?>"<?= $eattr('seats') ?>>
    <?= $err('seats') ?>

    <label for="transmission">Transmission</label>
    <select id="transmission" name="transmission" required<?= $eattr('transmission') ?>>
      <option value="automatic"<?= $v('transmission', 'automatic') === 'automatic' ? ' selected' : '' ?>>Automatic</option>
      <option value="manual"<?= $v('transmission') === 'manual' ? ' selected' : '' ?>>Manual</option>
    </select>
    <?= $err('transmission') ?>

    <label for="fuel_type">Fuel type</label>
    <select id="fuel_type" name="fuel_type" required<?= $eattr('fuel_type') ?>>
      <?php foreach (['petrol', 'diesel', 'hybrid'] as $fuel): ?>
        <option value="<?= View::e($fuel) ?>"<?= $v('fuel_type', 'petrol') === $fuel ? ' selected' : '' ?>>
          <?= View::e(ucfirst($fuel)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?= $err('fuel_type') ?>

    <label for="daily_price">Daily price (KES)</label>
    <input id="daily_price" name="daily_price" type="number" min="1" step="0.01" required
           value="<?= View::e($v('daily_price')) ?>"<?= $eattr('daily_price') ?>>
    <?= $err('daily_price') ?>

    <label for="status">Status</label>
    <select id="status" name="status" required<?= $eattr('status') ?>>
      <?php foreach (['active', 'maintenance', 'retired'] as $state): ?>
        <option value="<?= View::e($state) ?>"<?= $v('status', 'active') === $state ? ' selected' : '' ?>>
          <?= View::e(ucfirst($state)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?= $err('status') ?>

    <label for="description">Description <span class="optional">(optional)</span></label>
    <textarea id="description" name="description" rows="4" maxlength="2000"<?= $eattr('description') ?>><?= View::e($v('description')) ?></textarea>
    <?= $err('description') ?>

    <label for="photo">Photo <span class="optional">(JPEG, PNG or WebP)</span></label>
    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"<?= $eattr('photo') ?>>
    <?= $err('photo') ?>
    <?php if ($isEdit && !empty($car['image_path'])): ?>
      <p class="muted">Current photo on file.
        <label class="checkbox"><input type="checkbox" name="remove_photo" value="1"> Remove it</label>
      </p>
    <?php endif; ?>

    <button class="btn btn-block" type="submit"><?= $isEdit ? 'Save changes' : 'Add vehicle' ?></button>
  </form>

  <p><a href="<?= View::e(url('/admin/vehicles')) ?>">&larr; Back to vehicles</a></p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
