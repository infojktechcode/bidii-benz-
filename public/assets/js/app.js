/* Bidii Benz Rentals — minimal progressive enhancement.
   No framework: confirm destructive submits and keep flash messages dismissible. */
(function () {
  'use strict';

  // Marks the document as JS-capable so the mobile drawer CSS activates;
  // without JS the toggle stays hidden and the nav links simply wrap.
  document.documentElement.classList.add('js');

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    // File uploads POST without any page activity for seconds; give feedback
    // so the button does not look dead. Page-based flow: a failed validation
    // lands on a fresh page with the button restored.
    if (form.querySelector('input[type="file"]')) {
      var button = form.querySelector('button[type="submit"]:not([disabled])');
      if (button) {
        button.disabled = true;
        button.textContent = 'Working…';
      }
    }
  });

  document.addEventListener('click', function (event) {
    var target = event.target;
    // Buttons declare behavior through data-attributes so the production
    // CSP (script-src 'self') never has to allow inline handlers.
    if (target instanceof Element && target.closest('[data-print]')) {
      window.print();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('[data-nav-toggle]');
    var panel = document.getElementById('site-nav');
    if (toggle && panel) {
      var setOpen = function (open) {
        panel.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      toggle.addEventListener('click', function () {
        setOpen(!panel.classList.contains('is-open'));
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && panel.classList.contains('is-open')) {
          setOpen(false);
          toggle.focus();
        }
      });
    }

    var alerts = document.querySelectorAll('.alert[role="status"]');
    Array.prototype.forEach.call(alerts, function (alert) {
      window.setTimeout(function () {
        alert.style.display = 'none';
      }, 8000);
    });
  });
})();
