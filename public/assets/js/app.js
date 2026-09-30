/* Bidii Benz Rentals — minimal progressive enhancement.
   No framework: confirm destructive submits and keep flash messages dismissible. */
(function () {
  'use strict';

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var alerts = document.querySelectorAll('.alert[role="status"]');
    Array.prototype.forEach.call(alerts, function (alert) {
      window.setTimeout(function () {
        alert.style.display = 'none';
      }, 8000);
    });
  });
})();
