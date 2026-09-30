<?php
/** @var string $reason */
$reason = $reason ?? 'payment';
$messages = [
    'payment' => 'You have made too many payment attempts in a short period. Please wait a few minutes and try again.',
    'password' => 'You have made too many password-change attempts in a short period. Please wait a few minutes and try again.',
    'registration' => 'You have made too many account-registration attempts in a short period. Please wait a few minutes and try again.',
];
?>
<?php require dirname(__DIR__) . '/layout/header.php'; ?>
<section class="error-page">
  <h1>429 &mdash; Too many attempts</h1>
  <p><?= \App\Core\View::e($messages[$reason] ?? $messages['payment']) ?></p>
  <p><a class="btn" href="<?= \App\Core\View::e(url('/')) ?>">Back to home</a></p>
</section>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
