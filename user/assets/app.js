// InterviewLens user portal: theme, sidebar, password tools, activity tracking.
// Shared animations, dialogs, toasts and tables live in ui.js.
(function () {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  window.HM = { csrf };

  try { const saved = localStorage.getItem('hm_theme'); if (saved) document.documentElement.dataset.theme = saved; } catch (e) {}
  document.querySelectorAll('[data-theme-toggle]').forEach(b => b.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('hm_theme', next); } catch (e) {}
    if (document.querySelector('.sidebar')) fetch('profile.php?theme=' + next, { method: 'POST', headers: { 'X-CSRF-Token': csrf } }).catch(() => {});
  }));
  document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => document.getElementById('sidebar')?.classList.toggle('open'));

  // Password strength meter: <input data-strength> + <div class="strength"><div></div></div>
  document.querySelectorAll('input[data-strength]').forEach(inp => {
    const bar = (inp.closest('form') || inp.parentElement).querySelector('.strength div');
    if (!bar) return;
    inp.addEventListener('input', () => {
      const v = inp.value; let s = 0;
      if (v.length >= 8) s++; if (v.length >= 12) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++; if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
      bar.style.width = (v ? Math.max(15, s * 25) : 0) + '%';
      bar.style.background = ['#ff5d6c', '#ff5d6c', '#f5b342', '#7b6cff', '#2ecc8f'][s];
    });
  });
  document.querySelectorAll('[data-reveal]').forEach(b => b.addEventListener('click', () => {
    const i = document.getElementById(b.dataset.reveal); i.type = i.type === 'password' ? 'text' : 'password';
  }));

  // Activity tracking: clicks and time on page (only when enabled by the admin).
  if (document.body.dataset.track === '1') {
    const start = Date.now(); let maxScroll = 0;
    const send = data => {
      const body = new URLSearchParams(Object.assign({ csrf, path: location.pathname + location.search }, data));
      if (navigator.sendBeacon) navigator.sendBeacon('track.php', body); else fetch('track.php', { method: 'POST', body, keepalive: true });
    };
    document.addEventListener('click', e => {
      const el = e.target.closest('a,button,input[type=submit],summary,[data-track-label]');
      if (!el) return;
      const label = (el.dataset.trackLabel || el.innerText || el.value || el.title || el.getAttribute('href') || '').trim().slice(0, 80);
      send({ kind: 'click', label, target: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') });
    }, true);
    addEventListener('scroll', () => {
      const h = document.documentElement;
      maxScroll = Math.max(maxScroll, Math.round((h.scrollTop + innerHeight) * 100 / h.scrollHeight));
    }, { passive: true });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') send({ kind: 'page_time', seconds: Math.round((Date.now() - start) / 1000), scroll: maxScroll });
    });
  }
})();
