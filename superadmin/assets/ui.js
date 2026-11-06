// InterviewLens shared UI: animations, styled dialogs, toasts, sortable/searchable tables,
// command palette (Ctrl+K). Used by both portals.
(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ── Page load bar ──────────────────────────────────────────────────────────
  const bar = document.createElement('div'); bar.id = 'loadbar'; document.body.prepend(bar);
  const startBar = () => { bar.className = ''; void bar.offsetWidth; bar.className = 'go'; };
  addEventListener('pageshow', () => { bar.className = 'done'; });
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank' || a.hasAttribute('download')) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript') || href.startsWith('mailto') || /export=|download|get=1|print/.test(href)) return;
    startBar();
  });

  // ── Toasts ─────────────────────────────────────────────────────────────────
  const wrap = document.createElement('div'); wrap.className = 'toast-wrap'; document.body.append(wrap);
  window.toast = (text, color) => {
    const t = document.createElement('div'); t.className = 'toast'; t.textContent = text;
    if (color) t.style.borderLeftColor = color;
    wrap.append(t);
    setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, 2600);
  };

  // ── Styled dialog (replaces confirm / prompt) ──────────────────────────────
  window.hmDialog = ({ title = 'Are you sure?', text = '', ok = 'Confirm', danger = false, typed = '' }) => new Promise(res => {
    const bg = document.createElement('div'); bg.className = 'dlg-bg';
    bg.innerHTML = `<div class="dlg" role="dialog" aria-modal="true"><h3>${esc(title)}</h3><p>${esc(text)}</p>
      ${typed ? `<label>Type <b>${esc(typed)}</b> to confirm</label><input class="dlg-in" autocomplete="off">` : ''}
      <div class="btns"><button class="btn btn-ghost" data-v="0">Cancel</button><button class="btn ${danger ? 'btn-danger' : ''}" data-v="1">${esc(ok)}</button></div></div>`;
    document.body.append(bg);
    const okBtn = $('[data-v="1"]', bg), inp = $('.dlg-in', bg);
    if (inp) { okBtn.disabled = true; inp.addEventListener('input', () => okBtn.disabled = inp.value !== typed); inp.focus(); } else okBtn.focus();
    const done = v => { bg.remove(); document.removeEventListener('keydown', key); res(v); };
    const key = e => { if (e.key === 'Escape') done(false); if (e.key === 'Enter' && !okBtn.disabled) done(true); };
    document.addEventListener('keydown', key);
    bg.addEventListener('click', e => { if (e.target === bg) done(false); const b = e.target.closest('[data-v]'); if (b) done(b.dataset.v === '1'); });
  });
  document.addEventListener('submit', async e => {
    const f = e.target, sub = e.submitter;
    if (f.dataset.ok) return;   // already confirmed
    const msg = sub?.dataset.confirm || f.dataset.confirm, typed = f.dataset.typeConfirm;
    if (!msg && !typed) return;
    e.preventDefault(); e.stopImmediatePropagation();
    const yes = await hmDialog({ title: typed ? 'This cannot be undone' : 'Please confirm', text: msg || '', typed, danger: !!typed || /delete|revoke|ban|remove|refund|reject|restore/i.test(msg || ''), ok: sub?.textContent.trim() || 'Confirm' });
    if (!yes) return;
    f.dataset.ok = '1';
    setTimeout(() => delete f.dataset.ok, 1500);
    if (f.requestSubmit && sub && sub.form === f) f.requestSubmit(sub);   // keeps the button's name/value, formaction and formtarget
    else { if (sub?.name) { const h = document.createElement('input'); h.type = 'hidden'; h.name = sub.name; h.value = sub.value; f.append(h); } startBar(); f.submit(); }
  }, true);
  document.addEventListener('submit', e => { if (!e.defaultPrevented && !e.target.target) startBar(); });

  // ── Entrance animations (stagger) ──────────────────────────────────────────
  if (!reduce) {
    $$('.main > .card, .main > .grid > *, .main > div > .card, .main > .grid > .card, .plans > *, .qa-grid > *, .wizard > *').forEach((el, i) => { el.classList.add('anim-in'); el.style.setProperty('--i', Math.min(i, 14)); });
    $$('.table-wrap tbody tr').forEach((tr, i) => { if (i < 25) { tr.classList.add('anim-in'); tr.style.setProperty('--i', i); } });
    // Drop the class when done: a finished transform animation still layers
    // elements above each other and would cover dropdown menus.
    document.addEventListener('animationend', e => { if (e.target.classList?.contains('anim-in')) e.target.classList.remove('anim-in'); });
  }

  // ── Sidebar: staggered entrance + a highlight that glides to the hovered item ──
  const nav = $('.sidebar nav');
  if (nav) {
    $$('a', nav).forEach((a, i) => a.style.setProperty('--n', i));
    $$('.nav-group', nav).forEach((g, i) => g.style.setProperty('--n', i * 3));
    if (!reduce) {
      const hl = document.createElement('i'); hl.className = 'nav-hl'; nav.prepend(hl);
      const active = $('a.active', nav);
      const move = a => { if (!a) { hl.style.opacity = 0; return; } hl.style.opacity = 1; hl.style.top = a.offsetTop + 'px'; hl.style.height = a.offsetHeight + 'px'; };
      $$('a', nav).forEach(a => a.addEventListener('mouseenter', () => move(a)));
      nav.addEventListener('mouseleave', () => move(active));
      setTimeout(() => move(active), 450);
      // Re-measure once the web font and page have loaded (item heights change).
      document.fonts?.ready.then(() => move(active)); addEventListener('load', () => move(active)); addEventListener('resize', () => move(active));
    }
    $('a.active', nav)?.scrollIntoView({ block: 'nearest' });
  }

  // ── Count-up for stat values ───────────────────────────────────────────────
  if (!reduce) $$('.stat .value').forEach(el => {
    if (el.children.length) return;
    const m = el.textContent.trim().match(/^([^\d-]*)([\d,]+(?:\.\d+)?)(.*)$/);
    if (!m) return;
    const end = parseFloat(m[2].replace(/,/g, '')); if (!isFinite(end) || end === 0) return;
    const dec = (m[2].split('.')[1] || '').length, t0 = performance.now();
    const fmt = v => m[1] + (m[2].includes(',') ? v.toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec }) : v.toFixed(dec)) + m[3];
    const tick = t => { const k = Math.min(1, (t - t0) / 1100); el.textContent = fmt(end * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  });

  // ── Button ripple ──────────────────────────────────────────────────────────
  document.addEventListener('pointerdown', e => {
    const b = e.target.closest('.btn'); if (!b || reduce) return;
    const r = b.getBoundingClientRect(), s = Math.max(r.width, r.height), c = document.createElement('span');
    c.className = 'ripple'; c.style.cssText = `width:${s}px;height:${s}px;left:${e.clientX - r.left - s / 2}px;top:${e.clientY - r.top - s / 2}px`;
    b.append(c); setTimeout(() => c.remove(), 600);
  });

  // ── Copy buttons ───────────────────────────────────────────────────────────
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]'); if (!b) return;
    e.preventDefault(); navigator.clipboard.writeText(b.dataset.copy).then(() => toast('Copied to clipboard ✓', 'var(--green)'));
  });

  // ── Flash messages fade away ───────────────────────────────────────────────
  $$('[data-autohide]').forEach(a => setTimeout(() => { a.classList.add('leaving'); setTimeout(() => a.remove(), 400); }, 7000));

  // ── Tables: sort by clicking headers + quick search ────────────────────────
  $$('.table-wrap table').forEach(table => {
    const body = table.tBodies[0]; if (!body || body.rows.length < 2) return;
    $$('thead th', table).forEach((th, col) => {
      if (th.querySelector('a, input') || !th.textContent.trim()) return;
      th.classList.add('sortable');
      th.addEventListener('click', () => {
        const asc = !th.classList.contains('asc');
        $$('th', table).forEach(x => x.classList.remove('asc', 'desc')); th.classList.add(asc ? 'asc' : 'desc');
        const val = r => { const t = (r.cells[col]?.innerText || '').trim(); const n = parseFloat(t.replace(/[₹,%\s]/g, '')); return isNaN(n) || /[a-z]{3,}/i.test(t.replace(/(days?|ms|d)$/i, '')) ? t.toLowerCase() : n; };
        [...body.rows].sort((a, b) => { const x = val(a), y = val(b); return (x > y ? 1 : x < y ? -1 : 0) * (asc ? 1 : -1); }).forEach(r => body.append(r));
      });
    });
    if (body.rows.length >= 6 && !table.closest('[data-no-filter]')) {
      const tools = document.createElement('div'); tools.className = 'table-tools no-print';
      tools.innerHTML = '<input type="search" placeholder="🔎 Filter this table…" aria-label="Filter table"><span class="muted small"></span>';
      table.closest('.table-wrap').before(tools);
      const inp = $('input', tools), info = $('span', tools);
      inp.addEventListener('input', () => {
        const q = inp.value.toLowerCase(); let n = 0;
        [...body.rows].forEach(r => { const show = !q || r.innerText.toLowerCase().includes(q); r.style.display = show ? '' : 'none'; n += show; });
        info.textContent = q ? `${n} shown` : '';
      });
    }
  });

  // ── Menus: float above everything (tables clip them otherwise), flip up near the bottom ──
  const placeMenu = d => {
    const list = $('.menu-list', d), btn = $('summary', d);
    if (!list || !btn) return;
    list.classList.add('floating');
    const r = btn.getBoundingClientRect(), w = list.offsetWidth, h = list.offsetHeight;
    // A transformed / filtered ancestor becomes the reference box for position:fixed — subtract its offset.
    let cb = d.parentElement, ox = 0, oy = 0;
    while (cb && cb !== document.body) {
      const cs = getComputedStyle(cb);
      if (cs.transform !== 'none' || cs.filter !== 'none' || (cs.backdropFilter && cs.backdropFilter !== 'none') || cs.perspective !== 'none' || /paint|layout|strict|content/.test(cs.contain)) { const b = cb.getBoundingClientRect(); ox = b.left; oy = b.top; break; }
      cb = cb.parentElement;
    }
    const left = Math.max(8, Math.min(r.right - w, innerWidth - w - 8));
    const below = r.bottom + 6 + h <= innerHeight - 8 || r.top - h - 6 < 8;
    list.style.left = (left - ox) + 'px';
    list.style.top = ((below ? r.bottom + 6 : r.top - h - 6) - oy) + 'px';
    list.style.transformOrigin = (below ? 'top ' : 'bottom ') + (left + w / 2 > r.left + r.width / 2 ? 'left' : 'right');
    list.style.maxHeight = (innerHeight - 16) + 'px';
  };
  document.addEventListener('toggle', e => {
    const d = e.target;
    if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('menu') || !d.open) return;
    $$('details.menu[open]').forEach(o => { if (o !== d && !o.contains(d)) o.open = false; });
    placeMenu(d);
  }, true);
  const replace = () => $$('details.menu[open]').forEach(placeMenu);
  addEventListener('scroll', replace, { passive: true, capture: true });
  addEventListener('resize', replace);
  // Menus: close on outside click / Escape
  document.addEventListener('click', e => $$('details.menu[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('details.menu[open]').forEach(d => d.open = false); });

  // ── Back to top ────────────────────────────────────────────────────────────
  const tt = document.createElement('button'); tt.className = 'to-top2 no-print'; tt.textContent = '↑'; tt.title = 'Back to top'; document.body.append(tt);
  tt.addEventListener('click', () => scrollTo({ top: 0, behavior: 'smooth' }));
  addEventListener('scroll', () => tt.classList.toggle('show', scrollY > 500), { passive: true });

  // ── Command palette (Ctrl+K or /) ──────────────────────────────────────────
  const items = [];
  $$('.sidebar nav a').forEach(a => items.push({ label: a.innerText.replace(/\d+$/, '').trim(), href: a.href, icon: a.querySelector('span')?.textContent || '→', hint: 'Go to' }));
  try { JSON.parse($('#cmd-extra')?.textContent || '[]').forEach(x => items.push(x)); } catch (e) {}
  const openPalette = () => {
    if ($('.cmdk')) return;
    const bg = document.createElement('div'); bg.className = 'dlg-bg';
    bg.innerHTML = '<div class="cmdk"><input placeholder="Type a page or action… (Enter to open)" aria-label="Command"><ul></ul><div class="foot"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div></div>';
    document.body.append(bg);
    const inp = $('input', bg), ul = $('ul', bg); let sel = 0, list = [];
    const search = $('form.top-search');
    const render = () => {
      const q = inp.value.toLowerCase().trim();
      list = items.filter(i => !q || i.label.toLowerCase().includes(q));
      if (q && search) list.push({ label: `Search everything for “${inp.value}”`, href: search.action + '?q=' + encodeURIComponent(inp.value), icon: '🔎', hint: 'Search' });
      sel = Math.min(sel, Math.max(0, list.length - 1));
      ul.innerHTML = list.map((i, k) => `<li class="${k === sel ? 'sel' : ''}"><a href="${esc(i.href)}">${esc(i.icon)} ${esc(i.label)}<small>${esc(i.hint || '')}</small></a></li>`).join('') || '<li class="muted" style="padding:12px">No matches</li>';
      $('li.sel', ul)?.scrollIntoView({ block: 'nearest' });
    };
    const close = () => bg.remove();
    inp.addEventListener('input', () => { sel = 0; render(); });
    inp.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { sel = Math.min(sel + 1, list.length - 1); render(); e.preventDefault(); }
      if (e.key === 'ArrowUp') { sel = Math.max(sel - 1, 0); render(); e.preventDefault(); }
      if (e.key === 'Enter' && list[sel]) { startBar(); location.href = list[sel].href; }
      if (e.key === 'Escape') close();
    });
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    render(); inp.focus();
  };
  window.hmPalette = openPalette;
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); }
    else if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); openPalette(); }
  });
  $$('[data-palette]').forEach(b => b.addEventListener('click', openPalette));
})();
