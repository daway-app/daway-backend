/* Daway offline stub for Chart.js (no local copy available; charts degrade offline).
   The real Chart.js is loaded from CDN at runtime; this stub only exists so the
   service worker can precache a same-origin asset and so any code referencing
   window.Chart when offline does not throw hard errors. */
(function () {
    if (typeof window === 'undefined') return;
    if (window.Chart) return; // real Chart.js already loaded from CDN
    function noop() {}
    function warn() { console.warn('[Daway] Chart.js غير متوفر بدون اتصال — سيتم تخطي الرسوم البيانية.'); }
    window.Chart = function () { warn(); this.config = { options: {} }; };
    window.Chart.register = noop;
    window.Chart.unregister = noop;
    window.Chart.defaults = {};
    window.Chart.instances = {};
})();
