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
    // Lock every submit button for this navigation: double-clicks, impatient
    // re-clicks and the back button can no longer replay a POST. File uploads
    // also swap the label so the button does not look dead during a long
    // multipart POST. Page-based flow: a failed validation lands on a fresh
    // page with the buttons restored.
    var buttons = form.querySelectorAll('button[type="submit"]:not([disabled])');
    var isUpload = !!form.querySelector('input[type="file"]');
    Array.prototype.forEach.call(buttons, function (button, index) {
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      if (isUpload && index === 0) {
        button.textContent = 'Working…';
      }
    });
  });

  // Restore buttons when the page returns from the back/forward cache
  // (the DOM snapshot would otherwise keep them disabled).
  window.addEventListener('pageshow', function () {
    var buttons = document.querySelectorAll('button[type="submit"][disabled]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    });
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
