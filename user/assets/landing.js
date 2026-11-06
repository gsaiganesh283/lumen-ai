// InterviewLens landing page + auth pages: animations and the sign-in / sign-up panel.
(function () {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  // Theme (shared with the rest of the portal)
  try { const t = localStorage.getItem('hm_theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
  $$('[data-theme-toggle]').forEach(b => b.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('hm_theme', next); } catch (e) {}
  }));

  // Particle network background
  const cv = $('#particles');
  if (cv && !reduce) {
    const ctx = cv.getContext('2d');
    let w, h, pts = [], mouse = { x: -999, y: -999 };
    const resize = () => {
      w = cv.width = innerWidth * devicePixelRatio; h = cv.height = innerHeight * devicePixelRatio;
      const n = Math.min(70, Math.floor(innerWidth / 22));
      pts = Array.from({ length: n }, () => ({ x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - .5) * .35, vy: (Math.random() - .5) * .35 }));
    };
    resize(); addEventListener('resize', resize);
    addEventListener('mousemove', e => { mouse.x = e.clientX * devicePixelRatio; mouse.y = e.clientY * devicePixelRatio; });
    const max = 130 * devicePixelRatio;
    (function draw() {
      if (!document.hidden) {
        ctx.clearRect(0, 0, w, h);
        const light = document.documentElement.dataset.theme === 'light';
        const col = light ? '91,91,200' : '170,160,255';
        for (const p of pts) {
          p.x += p.vx; p.y += p.vy;
          if (p.x < 0 || p.x > w) p.vx *= -1; if (p.y < 0 || p.y > h) p.vy *= -1;
          const dm = Math.hypot(p.x - mouse.x, p.y - mouse.y);
          if (dm < max) { p.x += (p.x - mouse.x) / dm * .6; p.y += (p.y - mouse.y) / dm * .6; }
          ctx.fillStyle = `rgba(${col},.55)`; ctx.beginPath(); ctx.arc(p.x, p.y, 1.6 * devicePixelRatio, 0, 7); ctx.fill();
        }
        for (let i = 0; i < pts.length; i++) for (let j = i + 1; j < pts.length; j++) {
          const d = Math.hypot(pts[i].x - pts[j].x, pts[i].y - pts[j].y);
          if (d < max) { ctx.strokeStyle = `rgba(${col},${.18 * (1 - d / max)})`; ctx.lineWidth = devicePixelRatio; ctx.beginPath(); ctx.moveTo(pts[i].x, pts[i].y); ctx.lineTo(pts[j].x, pts[j].y); ctx.stroke(); }
        }
      }
      requestAnimationFrame(draw);
    })();
  }

  // Orbs follow the mouse a little
  if (!reduce) addEventListener('mousemove', e => {
    const x = e.clientX / innerWidth - .5, y = e.clientY / innerHeight - .5;
    $$('.orb').forEach((o, i) => o.style.translate = `${x * (i + 1) * 30}px ${y * (i + 1) * 30}px`);
  });

  // Nav: glass on scroll, progress bar, active section, back-to-top
  const nav = $('.lp-nav'), bar = $('.scroll-bar'), top = $('.to-top');
  const onScroll = () => {
    const y = scrollY, max = document.documentElement.scrollHeight - innerHeight;
    nav?.classList.toggle('scrolled', y > 30);
    if (bar) bar.style.width = (max > 0 ? y / max * 100 : 0) + '%';
    top?.classList.toggle('show', y > 700);
    let cur = '';
    $$('section[id]').forEach(s => { if (s.getBoundingClientRect().top < innerHeight * .4) cur = s.id; });
    $$('.lp-links a.lnk').forEach(a => a.classList.toggle('on', a.getAttribute('href') === '#' + cur));
  };
  addEventListener('scroll', onScroll, { passive: true }); onScroll();
  top?.addEventListener('click', () => scrollTo({ top: 0, behavior: 'smooth' }));
  $('.menu-btn')?.addEventListener('click', () => $('.lp-links').classList.toggle('open'));
  $$('.lp-links a').forEach(a => a.addEventListener('click', () => $('.lp-links').classList.remove('open')));

  // Scroll reveal
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .15 });
  $$('.reveal, .steps2').forEach(el => io.observe(el));

  // Count-up numbers: <b data-count="90" data-prefix="" data-suffix="+">
  const cio = new IntersectionObserver(es => es.forEach(e => {
    if (!e.isIntersecting) return; cio.unobserve(e.target);
    const el = e.target, end = +el.dataset.count, pre = el.dataset.prefix || '', suf = el.dataset.suffix || '';
    if (reduce) { el.textContent = pre + end + suf; return; }
    const t0 = performance.now();
    const tick = t => { const k = Math.min(1, (t - t0) / 1400), v = Math.round(end * (1 - Math.pow(1 - k, 3)));
      el.textContent = pre + v.toLocaleString('en-IN') + suf; if (k < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  }), { threshold: .6 });
  $$('[data-count]').forEach(el => cio.observe(el));

  // Typed rotating words
  const typed = $('.typed');
  if (typed) {
    const words = JSON.parse(typed.dataset.words); let wi = 0, ci = 0, del = false;
    if (reduce) typed.textContent = words[0];
    else (function step() {
      const w = words[wi];
      typed.textContent = w.slice(0, ci);
      if (!del && ci < w.length) { ci++; setTimeout(step, 70); }
      else if (!del) { del = true; setTimeout(step, 1600); }
      else if (ci > 0) { ci--; setTimeout(step, 35); }
      else { del = false; wi = (wi + 1) % words.length; setTimeout(step, 250); }
    })();
  }

  // Live app demo in the hero
  const demo = $('#demo');
  if (demo) {
    const script = JSON.parse(demo.dataset.script);
    let k = 0;
    const play = async () => {
      const item = script[k % script.length]; k++;
      demo.querySelectorAll('.bubble').forEach(b => b.remove());
      const q = document.createElement('div'); q.className = 'bubble q';
      q.innerHTML = '<small>🎧 Interviewer</small>'; q.append(item.q); demo.append(q);
      await wait(60); q.classList.add('show');
      await wait(reduce ? 200 : 1100);
      const a = document.createElement('div'); a.className = 'bubble a';
      a.innerHTML = '<small>🧠 InterviewLens · from your resume</small><span class="caret"></span>'; demo.append(a);
      await wait(60); a.classList.add('show');
      const span = a.querySelector('span');
      for (let i = 1; i <= item.a.length; i += reduce ? item.a.length : 2) { span.textContent = item.a.slice(0, i); await wait(18); }
      span.classList.remove('caret');
      await wait(3600); play();
    };
    const wait = ms => new Promise(r => setTimeout(r, ms));
    play();
  }

  // 3D tilt for the app mock and the price cards
  if (!reduce && matchMedia('(pointer: fine)').matches) {
    $$('[data-tilt]').forEach(el => {
      const base = el.dataset.tilt === 'mock' ? 'rotateY(-8deg) rotateX(4deg)' : '';
      el.addEventListener('mousemove', e => {
        const r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
        el.style.transform = `perspective(1000px) rotateY(${x * 10}deg) rotateX(${-y * 10}deg) translateY(-4px)`;
      });
      el.addEventListener('mouseleave', () => el.style.transform = base);
    });
  }
  // Spotlight on feature cards
  $$('.f-card').forEach(c => c.addEventListener('mousemove', e => {
    const r = c.getBoundingClientRect();
    c.style.setProperty('--mx', (e.clientX - r.left) + 'px'); c.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }));

  // Rotating cards on the auth pages
  const quotes = $$('.quote-rot div');
  if (quotes.length > 1) { let q = 0; setInterval(() => { quotes[q].classList.remove('on'); q = (q + 1) % quotes.length; quotes[q].classList.add('on'); }, 4200); }

  // ── Auth modal ─────────────────────────────────────────────────────────────
  const modal = $('#auth');
  if (modal) {
    const tabs = $('.auth-tabs', modal), panes = { login: $('#pane-login'), register: $('#pane-register') };
    let last = null;
    const show = which => {
      tabs.classList.toggle('reg', which === 'register');
      $$('button', tabs).forEach(b => b.classList.toggle('on', b.dataset.tab === which));
      panes.login.classList.toggle('hide', which !== 'login'); panes.login.classList.toggle('left', which === 'register');
      panes.register.classList.toggle('hide', which !== 'register');
      setTimeout(() => $('input:not([type=hidden]):not([tabindex="-1"])', panes[which])?.focus(), 250);
    };
    const open = which => { last = document.activeElement; show(which); modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; };
    const close = () => { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; last?.focus?.(); if (/^#(login|register)$/.test(location.hash)) history.replaceState(null, '', location.pathname); };
    $$('[data-auth]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); open(b.dataset.auth); }));
    $$('button', tabs).forEach(b => b.addEventListener('click', () => show(b.dataset.tab)));
    $$('[data-switch]', modal).forEach(a => a.addEventListener('click', e => { e.preventDefault(); show(a.dataset.switch); }));
    $('.x', modal).addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    addEventListener('keydown', e => {
      if (!modal.classList.contains('open')) return;
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {   // keep focus inside the panel
        const f = $$('a,button,input,select', modal).filter(x => x.offsetParent && !x.closest('.hide'));
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1).focus(); }
        else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0].focus(); }
      }
    });
    if (location.hash === '#login' || location.hash === '#register') open(location.hash.slice(1));
  }

  // Forms: show/hide password, strength meter, check passwords match, loading state
  $$('.eye').forEach(b => b.addEventListener('click', () => { const i = b.parentElement.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; b.textContent = i.type === 'password' ? '👁' : '🙈'; }));
  $$('input[data-strength]').forEach(inp => {
    const bar = inp.closest('form').querySelector('.strength div');
    inp.addEventListener('input', () => {
      const v = inp.value; let s = 0;
      if (v.length >= 8) s++; if (v.length >= 12) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++; if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
      bar.style.width = (v ? Math.max(15, s * 25) : 0) + '%';
      bar.style.background = ['#ff5d6c', '#ff5d6c', '#f5b342', '#7b6cff', '#2ecc8f'][s];
    });
  });
  $$('form[data-auth-form]').forEach(f => f.addEventListener('submit', e => {
    const p1 = f.querySelector('[name=password]'), p2 = f.querySelector('[name=password2]');
    if (p2 && p1.value !== p2.value) { e.preventDefault(); p2.setCustomValidity("Passwords don't match"); p2.reportValidity(); p2.setCustomValidity(''); f.classList.add('shake'); setTimeout(() => f.classList.remove('shake'), 400); return; }
    const btn = f.querySelector('button[type=submit]'); btn.classList.add('loading'); setTimeout(() => btn.disabled = true, 0);
  }));
  $$('[data-autohide]').forEach(a => setTimeout(() => a.style.display = 'none', 8000));
})();
