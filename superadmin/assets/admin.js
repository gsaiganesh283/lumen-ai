// InterviewLens superadmin: theme, sidebar, bulk selection, password reveal.
// Shared animations, dialogs, toasts, tables and the command palette live in ui.js.
(function () {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  window.HM = { csrf };
  try { const t = localStorage.getItem('hm_admin_theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
  document.querySelectorAll('[data-theme-toggle]').forEach(b => b.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('hm_admin_theme', next); } catch (e) {}
  }));
  document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => document.getElementById('sidebar')?.classList.toggle('open'));

  // Bulk select: <input type=checkbox data-bulk-all> + <input type=checkbox data-bulk name="ids[]">
  document.querySelectorAll('[data-bulk-all]').forEach(all => all.addEventListener('change', () => {
    all.closest('form').querySelectorAll('[data-bulk]').forEach(c => c.checked = all.checked);
    updateBulk(all.closest('form'));
  }));
  const updateBulk = form => {
    const n = form.querySelectorAll('[data-bulk]:checked').length, bar = form.querySelector('.bulk-bar');
    if (!bar) return;
    let c = bar.querySelector('.bulk-count');
    if (!c) { c = document.createElement('b'); c.className = 'bulk-count'; bar.prepend(c); }
    c.textContent = n ? `${n} selected` : '';
    bar.classList.toggle('active', n > 0);
  };
  document.addEventListener('change', e => { if (e.target.matches('[data-bulk]')) updateBulk(e.target.closest('form')); });
  document.addEventListener('submit', e => {
    const f = e.target;
    if (f.dataset.needsSelection && f.querySelector('[data-bulk]') && !f.querySelectorAll('[data-bulk]:checked').length && e.submitter?.closest('.bulk-bar')) {
      e.preventDefault(); e.stopImmediatePropagation(); toast('Select at least one row first.', 'var(--amber)');
    }
  }, true);
  document.querySelectorAll('[data-reveal]').forEach(b => b.addEventListener('click', () => {
    const i = document.getElementById(b.dataset.reveal); i.type = i.type === 'password' ? 'text' : 'password';
  }));
})();
