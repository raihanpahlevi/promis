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

/* Mobile sidebar: tap the hamburger to open, tap the dimmed backdrop (or any
   nav link, or Escape) to close — previously only the hamburger itself could
   close it again, which reads as "stuck" on a phone. */
(function () {
  var sidebar = document.getElementById('sidebar');
  var toggle = document.getElementById('sidebarToggle');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar || !toggle || !backdrop) return;

  function openSidebar() {
    sidebar.classList.add('open');
    backdrop.classList.add('open');
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('open');
  }

  toggle.addEventListener('click', function () {
    if (sidebar.classList.contains('open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });
  backdrop.addEventListener('click', closeSidebar);
  sidebar.addEventListener('click', function (e) {
    if (e.target.closest('a.sb-item')) closeSidebar();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
  });
})();
