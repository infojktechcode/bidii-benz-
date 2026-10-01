<?php
/** @var string $title */
/** @var list<array<string, mixed>> $featured */
use App\Core\View;

$featured = $featured ?? [];
require __DIR__ . '/layout/header.php';
?>
<section class="hero">
  <span class="eyebrow">Kitengela &middot; Kajiado County</span>
  <h1>Mercedes-Benz Car Hire in Kitengela</h1>
  <p>Browse our professional fleet, check availability, book online and pay with
    M-Pesa &mdash; transparent pricing, no hidden charges.</p>
  <div class="btn-row">
    <a class="btn" href="<?= View::e(url('/cars')) ?>">Browse the fleet</a>
    <a class="btn btn-secondary" href="#how-it-works">How booking works</a>
  </div>
</section>

<section class="cta-strip" aria-label="Check availability">
  <div>
    <strong>Check availability for your dates</strong>
    <p>Live availability and pricing are shown on every vehicle in the fleet.</p>
  </div>
  <a class="btn" href="<?= View::e(url('/cars')) ?>">See available cars</a>
</section>

<?php if ($featured !== []): ?>
<section class="home-section" aria-labelledby="featured-heading">
  <div class="section-head">
    <h2 id="featured-heading">Featured Mercedes-Benz vehicles</h2>
    <p>A selection from our Kitengela fleet &mdash; every car includes transparent daily pricing.</p>
  </div>
  <div class="card-grid">
    <?php foreach ($featured as $car): ?>
      <article class="card">
        <div class="card-media">
          <?php if (!empty($car['image_path'])): ?>
            <img src="<?= View::e(url('/media/cars/' . (int) $car['id'])) ?>"
                 alt="<?= View::e($car['make'] . ' ' . $car['model']) ?>"
                 width="400" height="250" loading="lazy">
          <?php else: ?>
            <div class="card-media-placeholder" aria-hidden="true">MB</div>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <h3><?= View::e($car['make'] . ' ' . $car['model']) ?></h3>
          <ul class="chip-row">
            <li class="chip"><?= View::e($car['seats']) ?> seats</li>
            <li class="chip"><?= View::e(ucfirst((string) $car['transmission'])) ?></li>
            <li class="chip"><?= View::e((string) ($car['year'] ?? '')) ?></li>
          </ul>
          <p class="price">KES <?= View::e(number_format((float) $car['daily_price'], 0)) ?>
            <span>/ day</span></p>
          <a class="btn" href="<?= View::e(url('/cars/' . (int) $car['id'])) ?>">View details</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="home-section" aria-labelledby="why-heading">
  <div class="section-head">
    <h2 id="why-heading">Why choose Bidii Benz</h2>
    <p>Professional vehicles, honest pricing and a booking process built for how Kenya pays.</p>
  </div>
  <div class="value-grid">
    <div class="value">
      <h3>Professional fleet</h3>
      <p>Clean, well-maintained Mercedes-Benz vehicles inspected before every hire.</p>
    </div>
    <div class="value">
      <h3>Transparent pricing</h3>
      <p>The daily rate you see is the rate you pay &mdash; totals shown before you confirm.</p>
    </div>
    <div class="value">
      <h3>Pay with M-Pesa</h3>
      <p>STK push on your phone, or record cash and bank payments at the office.</p>
    </div>
    <div class="value">
      <h3>Local Kitengela service</h3>
      <p>A Kajiado County business you can visit &mdash; bookings managed by our own office.</p>
    </div>
  </div>
</section>

<section class="home-section" id="how-it-works" aria-labelledby="how-heading">
  <div class="section-head">
    <h2 id="how-heading">How booking works</h2>
    <p>Five simple steps from browsing to keys in hand.</p>
  </div>
  <ol class="steps-home">
    <li><strong>Choose a vehicle</strong> Browse the fleet and open the car you want.</li>
    <li><strong>Pick your dates</strong> Enter pickup and return dates to check availability.</li>
    <li><strong>Review the booking</strong> See the full total before you commit.</li>
    <li><strong>Pay</strong> M-Pesa, cash at the office, or bank transfer.</li>
    <li><strong>Confirmation</strong> Print or keep your booking reference.</li>
  </ol>
</section>

<section class="home-section" aria-labelledby="pay-heading">
  <div class="section-head">
    <h2 id="pay-heading">Payments made simple</h2>
  </div>
  <div class="info-panel">
    <div class="pay-grid">
      <div class="pay-item">
        <strong>M-Pesa (STK push)</strong>
        <span>Enter your phone number and approve the prompt &mdash; confirmed automatically.</span>
      </div>
      <div class="pay-item">
        <strong>Cash at the office</strong>
        <span>Pay in person; recorded instantly and confirmed by the office.</span>
      </div>
      <div class="pay-item">
        <strong>Bank transfer</strong>
        <span>Recorded on your booking and confirmed by the office.</span>
      </div>
    </div>
    <p>Part-payments are supported &mdash; your balance updates as payments are confirmed.</p>
  </div>
</section>

<section class="home-section" aria-labelledby="local-heading">
  <div class="section-head">
    <h2 id="local-heading">Your local Mercedes-Benz rental</h2>
    <p>Bidii Benz Rentals is based in Kitengela, Kajiado County. Book online at any
      time and deal with a real local office for pickup, questions and support.</p>
  </div>
  <div class="btn-row">
    <a class="btn" href="<?= View::e(url('/cars')) ?>">Browse the fleet</a>
    <a class="btn btn-secondary" href="<?= View::e(url('/register')) ?>">Create an account</a>
  </div>
</section>
<?php require __DIR__ . '/layout/footer.php'; ?>
