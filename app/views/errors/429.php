<?php require dirname(__DIR__) . '/layout/header.php'; ?>
<section class="error-page">
  <h1>429 — Too many attempts</h1>
  <p>You have made too many payment attempts in a short period. Please wait a few minutes and try again.</p>
  <p><a class="btn" href="<?= \App\Core\View::e(url('/')) ?>">Back to home</a></p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
