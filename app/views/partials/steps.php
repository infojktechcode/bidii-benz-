<?php
/** @var int $step */
use App\Core\View;

$step = (int) ($step ?? 2);
$stepLabels = [
    1 => 'Choose vehicle',
    2 => 'Dates',
    3 => 'Review',
    4 => 'Payment',
    5 => 'Confirmation',
];
?>
<ol class="steps" aria-label="Booking progress">
  <?php for ($i = 1; $i <= 5; $i++): ?>
    <li class="steps-item<?= $i < $step ? ' done' : '' ?><?= $i === $step ? ' current' : '' ?>"
        <?= $i === $step ? 'aria-current="step"' : '' ?>>
      <span class="steps-num" aria-hidden="true"><?= $i ?></span>
      <?= View::e($stepLabels[$i]) ?>
    </li>
  <?php endfor; ?>
</ol>
