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
  var content = document.querySelector('.content');

  function showBar() {
    requestAnimationFrame(function () {
      bar.classList.add('active');
    });
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      bar.classList.remove('active');
      if (content) content.classList.remove('is-submitting');
    }, 4000);
  }

  window.addEventListener('pageshow', function () {
    clearTimeout(hideTimer);
    bar.classList.remove('active');
    if (content) content.classList.remove('is-submitting');
  });

  document.addEventListener('submit', function (e) {
    if (e.target.tagName !== 'FORM') return;
    showBar();
    if (content) content.classList.add('is-submitting');
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

/* Motion pass (2026-07-23): count-up stat numbers, ratio-ring fill-in, and
   Chart.js animation defaults. Presentation-only — every element already
   renders its final server-side value in the HTML; this only replays how
   that value appears. All of it stands down for prefers-reduced-motion. */
(function () {
  var reduceMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }

  /* Stat-card numbers count up from 0 over ~700ms. Only fires on text that
     is purely an integer with optional thousands separators — composites
     like "3 / 5" or anything with units are left untouched. The separator
     found in the original string (number_format may emit "," or ".") is
     reused while counting, and the element always ends on the exact
     original server-rendered string, so no rounding drift can stick. */
  if (!reduceMotion) {
    document.querySelectorAll('.stat-card .num').forEach(function (el) {
      var original = el.textContent.trim();
      if (!/^\d{1,3}([.,]\d{3})*$/.test(original)) return;
      var target = parseInt(original.replace(/[.,]/g, ''), 10);
      if (!target) return;
      var sep = original.indexOf('.') !== -1 ? '.'
        : (original.indexOf(',') !== -1 ? ',' : '');
      var startTs = null;

      function fmt(n) {
        var s = String(n);
        return sep ? s.replace(/\B(?=(\d{3})+(?!\d))/g, sep) : s;
      }
      function tick(ts) {
        if (startTs === null) startTs = ts;
        var p = Math.min((ts - startTs) / 700, 1);
        if (p < 1) {
          el.textContent = fmt(Math.round(target * easeOutQuart(p)));
          requestAnimationFrame(tick);
        } else {
          el.textContent = original;
        }
      }
      el.textContent = fmt(0);
      requestAnimationFrame(tick);
    });
  }

  /* Ratio rings: rewind --pct to 0, then restore it a frame later so the
     registered-property transition in app.css tweens the fill; the % label
     counts up in the same 700ms so the two never visibly disagree. */
  if (!reduceMotion) {
    document.querySelectorAll('.ratio-ring').forEach(function (ring) {
      var pct = parseFloat(ring.style.getPropertyValue('--pct'));
      if (isNaN(pct) || pct <= 0) return;
      ring.style.setProperty('--pct', '0');
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          ring.style.setProperty('--pct', String(pct));
        });
      });

      var label = ring.querySelector('span');
      var labelText = label ? label.textContent.trim() : '';
      if (!label || !/^\d+(\.\d+)?%$/.test(labelText)) return;
      var decimals = (labelText.match(/\.(\d+)%$/) || [, ''])[1].length;
      var value = parseFloat(labelText);
      var startTs = null;
      function tick(ts) {
        if (startTs === null) startTs = ts;
        var p = Math.min((ts - startTs) / 700, 1);
        if (p < 1) {
          label.textContent = (value * easeOutQuart(p)).toFixed(decimals) + '%';
          requestAnimationFrame(tick);
        } else {
          label.textContent = labelText;
        }
      }
      label.textContent = (0).toFixed(decimals) + '%';
      requestAnimationFrame(tick);
    });
  }

  /* Chart.js (loaded per-page via @push('head'), so it's already on window
     here; pages instantiate their charts in @stack('scripts') AFTER this
     file, so these defaults apply without touching any page's own config):
     bars grow in sequence — a small per-bar delay ladder, capped so a
     many-bar chart still finishes well under a second total. */
  if (window.Chart) {
    if (reduceMotion) {
      Chart.defaults.animation = false;
    } else {
      Chart.defaults.animation = Object.assign({}, Chart.defaults.animation, {
        duration: 500,
        easing: 'easeOutQuart',
        delay: function (ctx) {
          if (ctx.type !== 'data' || ctx.mode !== 'default') return 0;
          return Math.min(ctx.dataIndex * 40, 320) + (ctx.datasetIndex || 0) * 80;
        },
      });
    }
  }
})();
