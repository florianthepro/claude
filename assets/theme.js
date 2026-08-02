/* Früh geladen (blockierend, winzig), um Theme-Flackern zu vermeiden:
   gespeicherte manuelle Wahl anwenden, sonst Systemeinstellung wirken lassen. */
(function () {
  'use strict';
  try {
    var stored = localStorage.getItem('sw-theme');
    if (stored === 'light' || stored === 'dark') {
      document.documentElement.setAttribute('data-theme', stored);
    }
  } catch (e) { /* localStorage gesperrt: Systemeinstellung gilt */ }
})();
