// Theme switch: remembers the visitor's choice, otherwise follows the phone or computer setting.
(function () {
  var root = document.documentElement;
  function stored() { try { return localStorage.getItem('hl-theme'); } catch (e) { return null; } }
  function setTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('hl-theme', t); } catch (e) {}
    var btn = document.getElementById('theme-btn');
    if (btn) btn.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
  }
  var initial = stored() || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  setTheme(initial);

  document.addEventListener('click', function (e) {
    if (e.target.closest('#theme-btn')) {
      setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    }
  });

  // Share: "Copy link" buttons.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var url = btn.getAttribute('data-copy');
    var label = btn.textContent;
    function done() { btn.textContent = btn.getAttribute('data-done'); setTimeout(function () { btn.textContent = label; }, 2000); }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(done, function () { window.prompt('', url); });
    else window.prompt('', url);
  });

  // Language dropdown: each option's value is the same page in that language.
  document.addEventListener('change', function (e) {
    if (e.target.id === 'lang-select' && e.target.value) window.location.href = e.target.value;
    if (e.target.matches('[data-filter-select]')) e.target.form.submit();
  });
})();
