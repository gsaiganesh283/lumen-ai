<?php
/** Live monitor: online users and apps, real-time event stream, traffic chart (polls every 3 s). */
require __DIR__ . '/_init.php';
require_admin('live');

if (isset($_GET['data'])) {
    $since = max(0, input_int('since'));
    $hb = setting('heartbeat_minutes') * 2 + 1;
    $events = rows('SELECT e.id, e.kind, e.label, e.path, e.ip, e.source, e.device_id, e.user_id, e.created_at, u.email
        FROM activity_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.id > ? ORDER BY e.id DESC LIMIT 60', [$since]);
    foreach ($events as &$ev) {
        $ev['label_kind'] = event_label($ev['kind']);
        $ev['time'] = gmdate('H:i:s', utc_ts($ev['created_at']) + setting('timezone_offset_minutes') * 60);
    }
    $per_min = rows("SELECT DATE_FORMAT(created_at, '%H:%i') m, COUNT(*) c FROM activity_events WHERE created_at >= ? GROUP BY m ORDER BY m", [ts_plus('-30 minutes')]);
    json_out([
        'events' => array_reverse($events),
        'stats' => [
            'online_users' => (int)val("SELECT COUNT(DISTINCT owner_id) FROM sessions WHERE kind = 'user' AND revoked = 0 AND last_seen_at > ?", [ts_plus('-5 minutes')]),
            'online_apps' => (int)val('SELECT COUNT(*) FROM devices WHERE last_seen_at > ?', [ts_plus("-$hb minutes")]),
            'online_admins' => (int)val("SELECT COUNT(DISTINCT owner_id) FROM sessions WHERE kind = 'admin' AND revoked = 0 AND last_seen_at > ?", [ts_plus('-5 minutes')]),
            'events_min' => (int)val('SELECT COUNT(*) FROM activity_events WHERE created_at >= ?', [ts_plus('-1 minute')]),
            'logins_hour' => (int)val("SELECT COUNT(*) FROM login_attempts WHERE success = 1 AND created_at >= ?", [ts_plus('-1 hour')]),
            'failed_hour' => (int)val("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ?", [ts_plus('-1 hour')]),
            'errors_hour' => (int)val("SELECT COUNT(*) FROM activity_events WHERE kind = 'error' AND created_at >= ?", [ts_plus('-1 hour')]),
            'revenue_today' => money((int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'paid' AND paid_at >= ?", [ts_plus('-24 hours')])),
            'signups_today' => (int)val('SELECT COUNT(*) FROM users WHERE created_at >= ?', [ts_plus('-24 hours')]),
        ],
        'online' => rows("SELECT u.id, u.email, u.name, s.ip, s.user_agent, s.last_seen_at, s.impersonated_by,
              (SELECT path FROM activity_events e WHERE e.user_id = u.id AND e.kind = 'page_view' ORDER BY e.id DESC LIMIT 1) AS page
            FROM sessions s JOIN users u ON u.id = s.owner_id WHERE s.kind = 'user' AND s.revoked = 0 AND s.last_seen_at > ?
            GROUP BY u.id ORDER BY s.last_seen_at DESC LIMIT 50", [ts_plus('-5 minutes')]),
        'apps' => rows("SELECT d.device_id, d.name, d.app_version, d.last_ip, d.last_seen_at, d.user_id, u.email FROM devices d JOIN users u ON u.id = d.user_id
            WHERE d.last_seen_at > ? ORDER BY d.last_seen_at DESC LIMIT 50", [ts_plus("-$hb minutes")]),
        'per_min' => $per_min,
        'last' => $events ? (int)$events[0]['id'] : $since,
    ]);
}
admin_start('Live monitor');
?>
<p class="muted"><span class="live-dot"></span> Updates every 3 seconds. <label class="check" style="display:inline-flex;margin-left:12px"><input type="checkbox" id="pause"> Pause</label>
  <label class="check" style="display:inline-flex;margin-left:12px"><input type="checkbox" id="hide-hb" checked> Hide app check-ins</label></p>
<div class="grid grid-4" id="stats"></div><br>
<div class="grid grid-2">
  <div class="card"><h2>📈 Events per minute (30 min)</h2><div class="chart-box"><canvas id="c-live"></canvas></div></div>
  <div class="card"><h2>🟢 Online in portal</h2><div class="table-wrap"><table class="compact"><tbody id="online"></tbody></table></div>
    <h2 style="margin-top:16px">🖥 Desktop apps online</h2><div class="table-wrap"><table class="compact"><tbody id="apps"></tbody></table></div></div>
</div>
<div class="card"><div class="card-head"><h2>⚡ Live activity stream</h2><input type="search" id="filter" placeholder="Filter (email, IP, event…)" style="max-width:280px"></div>
  <div class="feed" id="feed"></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  let last = 0, chart;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const feed = document.getElementById('feed');
  const statLabels = { online_users: '🟢 Users online', online_apps: '🖥 Apps online', online_admins: '🛡 Admins online', events_min: '⚡ Events / min',
    logins_hour: '🔑 Logins (1h)', failed_hour: '⛔ Failed logins (1h)', errors_hour: '🐞 Errors (1h)', revenue_today: '💰 Revenue (24h)', signups_today: '🆕 Sign-ups (24h)' };
  async function tick() {
    if (document.getElementById('pause').checked) return;
    try {
      const r = await fetch('live.php?data=1&since=' + last, { headers: { Accept: 'application/json' } });
      const d = await r.json();
      document.getElementById('stats').innerHTML = Object.entries(d.stats).map(([k, v]) => `<div class="stat"><div class="label">${statLabels[k]}</div><div class="value">${esc(v)}</div></div>`).join('');
      document.getElementById('online').innerHTML = d.online.length ? d.online.map(u => `<tr><td><a href="user.php?id=${u.id}">${esc(u.email)}</a>${u.impersonated_by ? ' <span class="badge badge-amber">admin view</span>' : ''}<div class="small muted">${esc(u.page || '')}</div></td><td class="small">${esc(u.ip)}</td></tr>`).join('') : '<tr><td class="muted">Nobody right now</td></tr>';
      document.getElementById('apps').innerHTML = d.apps.length ? d.apps.map(a => `<tr><td><code>${esc(a.device_id)}</code><div class="small muted">${esc(a.name)} · v${esc(a.app_version)}</div></td><td><a href="user.php?id=${a.user_id}">${esc(a.email)}</a></td><td class="small">${esc(a.last_ip)}</td></tr>`).join('') : '<tr><td class="muted">No apps online</td></tr>';
      const hideHb = document.getElementById('hide-hb').checked, q = document.getElementById('filter').value.toLowerCase();
      for (const ev of d.events) {
        if (hideHb && ev.kind === 'desktop_heartbeat') continue;
        const div = document.createElement('div'); div.className = 'new';
        div.dataset.text = (ev.email + ' ' + ev.ip + ' ' + ev.label + ' ' + ev.kind + ' ' + ev.device_id).toLowerCase();
        div.innerHTML = `<time>${esc(ev.time)}</time><span>${esc(ev.label_kind)}</span><span style="flex:1">${ev.user_id ? `<a href="user.php?id=${ev.user_id}">${esc(ev.email)}</a> · ` : ''}${esc(ev.label || ev.path)}</span><span class="muted">${esc(ev.ip)}</span>`;
        if (q && !div.dataset.text.includes(q)) div.style.display = 'none';
        feed.prepend(div);
      }
      while (feed.children.length > 300) feed.lastChild.remove();
      last = d.last;
      const labels = d.per_min.map(x => x.m), data = d.per_min.map(x => +x.c);
      if (!chart) chart = new Chart(document.getElementById('c-live'), { type: 'line', data: { labels, datasets: [{ label: 'Events', data, borderColor: '#2ecc8f', backgroundColor: 'rgba(46,204,143,.15)', fill: true, tension: .3 }] },
        options: { responsive: true, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true } } } });
      else { chart.data.labels = labels; chart.data.datasets[0].data = data; chart.update(); }
    } catch (e) { console.warn(e); }
  }
  document.getElementById('filter').addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    for (const div of feed.children) div.style.display = !q || div.dataset.text.includes(q) ? '' : 'none';
  });
  tick(); setInterval(tick, 3000);
})();
</script>
<?php admin_end();
