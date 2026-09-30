<?php
/** @var string $title */
/** @var list<array<string, mixed>> $entries */
/** @var list<string> $actions */
/** @var list<array{id:int, label:string}> $actors */
/** @var string|null $filterAction */
/** @var int|null $filterUser */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
use App\Core\View;

$entries = $entries ?? [];
$actions = $actions ?? [];
$actors = $actors ?? [];
$filterAction = $filterAction ?? null;
$filterUser = $filterUser ?? null;
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$total = (int) ($total ?? count($entries));
require dirname(__DIR__) . '/layout/header.php';

$qs = static function (int $p) use ($filterAction, $filterUser): string {
    $q = [];
    if ($filterAction !== null) {
        $q[] = 'action=' . rawurlencode($filterAction);
    }
    if ($filterUser !== null) {
        $q[] = 'user=' . $filterUser;
    }
    if ($p > 1) {
        $q[] = 'page=' . $p;
    }
    return $q === [] ? '' : '?' . implode('&', $q);
};
?>
<section>
  <div class="page-head">
    <h1>Audit trail</h1>
    <p class="muted">
      <?= View::e((string) $total) ?> entries
      <?= $pages > 1 ? ' &middot; page ' . View::e((string) $page) . ' of ' . View::e((string) $pages) : '' ?>
    </p>
  </div>

  <form method="get" action="<?= View::e(url('/admin/audit')) ?>" class="filter-form">
    <div class="filter-fields">
      <div>
        <label for="audit-action">Action</label>
        <select id="audit-action" name="action">
          <option value="">All actions</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= View::e($a) ?>"<?= $filterAction === $a ? ' selected' : '' ?>><?= View::e($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="audit-user">Actor</label>
        <select id="audit-user" name="user">
          <option value="">Everyone</option>
          <?php foreach ($actors as $a): ?>
            <option value="<?= View::e((string) $a['id']) ?>"<?= $filterUser === $a['id'] ? ' selected' : '' ?>>
              <?= View::e($a['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-actions">
        <button class="btn" type="submit">Filter</button>
        <a class="btn btn-secondary" href="<?= View::e(url('/admin/audit')) ?>">Reset</a>
      </div>
    </div>
  </form>

  <?php if ($entries === []): ?>
    <div class="empty-state"><p>No audit entries match this filter.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">When</th>
            <th scope="col">Who</th>
            <th scope="col">Action</th>
            <th scope="col">Entity</th>
            <th scope="col">Detail</th>
            <th scope="col">IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): ?>
            <tr>
              <td><?= View::e(date('Y-m-d H:i', strtotime((string) $e['created_at']))) ?></td>
              <td>
                <?php if (isset($e['email']) && $e['email'] !== null && $e['email'] !== ''): ?>
                  <?php if (isset($e['full_name']) && $e['full_name'] !== null && $e['full_name'] !== ''): ?>
                    <?= View::e((string) $e['full_name']) ?><br>
                    <span class="muted"><?= View::e((string) ($e['role'] ?? '—')) ?> &middot; <?= View::e((string) $e['email']) ?></span>
                  <?php else: ?>
                    <?= View::e((string) $e['email']) ?><br>
                    <span class="muted"><?= View::e((string) ($e['role'] ?? '—')) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">Deleted user &middot; <?= View::e((string) ($e['role'] ?? '—')) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-active"><?= View::e((string) $e['action']) ?></span></td>
              <td>
                <?php if (isset($e['entity']) && $e['entity'] !== null && $e['entity'] !== ''): ?>
                  <?= View::e((string) $e['entity']) ?><?= $e['entity_id'] !== null ? ' #' . (int) $e['entity_id'] : '' ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td>
                <?php if (isset($e['detail']) && $e['detail'] !== null && $e['detail'] !== ''): ?>
                  <?= nl2br(View::e((string) $e['detail'])) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="muted"><?= View::e((string) ($e['ip_address'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Audit trail pages">
        <?php if ($page > 1): ?>
          <a href="<?= View::e(url('/admin/audit' . $qs($page - 1))) ?>">&larr; Newer</a>
        <?php endif; ?>
        <span class="muted">Page <?= View::e((string) $page) ?> of <?= View::e((string) $pages) ?></span>
        <?php if ($page < $pages): ?>
          <a href="<?= View::e(url('/admin/audit' . $qs($page + 1))) ?>">Older &rarr;</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
