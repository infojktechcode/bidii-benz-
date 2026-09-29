<?php require dirname(__DIR__) . '/layout/header.php'; ?>
<section class="error-page">
  <h1>403 — Access denied</h1>
  <p>You do not have permission to view this page.</p>
  <p><a class="btn" href="<?= \App\Core\View::e(url('/')) ?>">Back to home</a></p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
