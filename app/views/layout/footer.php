<?php $footerAuthed = \App\Core\Guard::check(); ?>
</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <div>
      <p class="footer-brand">BIDII BENZ <span>RENTALS</span></p>
      <p class="footer-note">Mercedes-Benz car hire in Kitengela, Kajiado County.
        Book online and pay with M-Pesa.</p>
    </div>
    <nav aria-label="Footer navigation">
      <h2 class="footer-heading">Quick links</h2>
      <ul class="footer-links">
        <li><a href="<?= \App\Core\View::e(url('/')) ?>">Home</a></li>
        <li><a href="<?= \App\Core\View::e(url('/cars')) ?>">Fleet</a></li>
        <?php if ($footerAuthed): ?>
          <li><a href="<?= \App\Core\View::e(url('/account')) ?>">My account</a></li>
        <?php else: ?>
          <li><a href="<?= \App\Core\View::e(url('/login')) ?>">Sign in</a></li>
        <?php endif; ?>
        <li><a href="<?= \App\Core\View::e(url('/privacy')) ?>">Privacy Notice</a></li>
      </ul>
    </nav>
    <div>
      <h2 class="footer-heading">Visit us</h2>
      <ul class="footer-links">
        <li>Kitengela, Kajiado County</li>
        <li>M-Pesa accepted</li>
        <li>Book online any time</li>
      </ul>
    </div>
  </div>
  <div class="container">
    <p class="footer-note">&copy; <?= date('Y') ?> Bidii Benz Rentals &middot;
      <a href="<?= \App\Core\View::e(url('/privacy')) ?>">Privacy Notice (Kenya Data Protection Act, 2019)</a></p>
  </div>
</footer>
</body>
</html>
