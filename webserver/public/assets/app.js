/* Progressive Verbesserungen – alle Funktionen der Seite laufen auch ohne
   JavaScript. Hier nur: Theme-Umschalter und Countdown bis Mitternacht. */
(function () {
  'use strict';

  /* Theme-Umschalter: Automatisch -> Hell -> Dunkel -> Automatisch */
  var toggle = document.getElementById('theme-toggle');
  if (toggle) {
    var readTheme = function () {
      try {
        var stored = localStorage.getItem('sw-theme');
        return stored === 'light' || stored === 'dark' ? stored : 'auto';
      } catch (e) { return 'auto'; }
    };
    var applyTheme = function (mode) {
      if (mode === 'auto') {
        document.documentElement.removeAttribute('data-theme');
      } else {
        document.documentElement.setAttribute('data-theme', mode);
      }
      try {
        if (mode === 'auto') { localStorage.removeItem('sw-theme'); }
        else { localStorage.setItem('sw-theme', mode); }
      } catch (e) { /* ohne Speicher gilt die Wahl nur für diese Seite */ }
      toggle.textContent = toggle.getAttribute('data-l-' + mode) || mode;
    };
    toggle.hidden = false;
    applyTheme(readTheme());
    toggle.addEventListener('click', function () {
      var order = ['auto', 'light', 'dark'];
      var next = order[(order.indexOf(readTheme()) + 1) % order.length];
      applyTheme(next);
    });
  }

  /* Countdown (z. B. bis zum nächsten möglichen Thema um 00:00) */
  var nodes = document.querySelectorAll('[data-countdown-to]');
  if (nodes.length > 0) {
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    var update = function () {
      nodes.forEach(function (node) {
        var target = Date.parse(node.getAttribute('data-countdown-to').replace(' ', 'T') + 'Z');
        var diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
        var text = pad(Math.floor(diff / 3600)) + ':' + pad(Math.floor((diff % 3600) / 60)) + ':' + pad(diff % 60);
        node.textContent = (node.getAttribute('data-label') || '') + ' ' + text;
      });
    };
    update();
    setInterval(update, 1000);
  }
})();
