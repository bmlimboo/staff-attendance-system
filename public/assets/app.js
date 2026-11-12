(function () {
  // Mobile sidebar toggle
  var t = document.getElementById('navToggle');
  if (t) {
    t.addEventListener('click', function (e) {
      e.stopPropagation();
      document.body.classList.toggle('nav-open');
    });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('nav-open') && !e.target.closest('#sidebar')) {
        document.body.classList.remove('nav-open');
      }
    });
  }

  // Confirm dialogs: <form data-confirm="Are you sure?">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  // Fill device details for registration
  var screenField = document.getElementById('dev_screen');
  if (screenField) { screenField.value = window.screen.width + 'x' + window.screen.height; }
  var platField = document.getElementById('dev_platform');
  if (platField) { platField.value = (navigator.platform || '').slice(0, 60); }

  // Auto-submit filter selects: <select data-autosubmit>
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
})();
