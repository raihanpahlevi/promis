/* PROMIS v2 — lightweight presentation-only helpers.
   Server-rendered MPA (no client-side fetch to hook into), so "loading state"
   here means: a thin top-of-page progress bar on any full-page navigation
   (form submit / same-origin link click), plus a spinner swap on the submit
   button that triggered it. Self-hides after a few seconds as a safety net
   for actions that don't actually navigate away (e.g. file downloads). */
(function () {
  var bar = document.createElement('div');
  bar.className = 'page-loading-bar';
  document.body.appendChild(bar);

  var hideTimer = null;

  function showBar() {
    requestAnimationFrame(function () {
      bar.classList.add('active');
    });
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      bar.classList.remove('active');
    }, 4000);
  }

  window.addEventListener('pageshow', function () {
    clearTimeout(hideTimer);
    bar.classList.remove('active');
  });

  document.addEventListener('submit', function (e) {
    if (e.target.tagName !== 'FORM') return;
    showBar();
    var btn = e.target.querySelector('button[type="submit"].btn-primary-custom');
    if (btn) btn.classList.add('is-loading');
  }, true);

  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href]');
    if (!link) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    var href = link.getAttribute('href');
    if (!href || href.charAt(0) === '#' || href.toLowerCase().indexOf('javascript:') === 0) return;
    if (link.origin !== window.location.origin) return;
    showBar();
  }, true);
})();
