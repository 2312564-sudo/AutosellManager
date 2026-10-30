/* Trạm theo dõi acc — giao diện (Mẫu C). Một trang, đổi mục bằng #tongquan #thongke #vps #caidat #nguoidung. */
(function () {
  'use strict';

  const Q = window.QQ || { me: { name: '', role: 'view' }, csrf: '', site: '' };
  const money = window.QQMoney;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = (s) => String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const isOwner = Q.me.role === 'owner', canCtl = Q.me.role !== 'view';
  const store = {
    get(k, d) { try { const v = localStorage.getItem('qq.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('qq.' + k, JSON.stringify(v)); } catch (e) { /* trình duyệt chặn lưu */ } },
  };

  const S = {
    data: null, off: 0, now: 0, fetchedAt: 0, ok: false, route: 'tongquan',
    sel: null, detail: null, detSeq: 0,
    tf: store.get('tf', '1h'), chartAcc: 'all', candles: [], more: false, cseq: 0, chart: null,
    ind: Object.assign({ ma: [7, 25, 99], maOn: [true, true, false], ema: [9, 21], emaOn: [false, false], boll: false, vol: true, rsi: false, macd: false }, store.get('ind', {})),
    filter: 'all', grp: store.get('grp', ''), sort: store.get('sort', 'order'), view: store.get('view', 'list'), q: '',
    rowHtml: {}, rowOrder: '', dragging: false, prevStatus: {}, statDays: 30,
  };

  /* ── icon ── */
  const ICONS = {
    overview: '<rect x="3" y="3" width="8" height="10" rx="2"/><rect x="13" y="3" width="8" height="6" rx="2"/><rect x="13" y="11" width="8" height="10" rx="2"/><rect x="3" y="15" width="8" height="6" rx="2"/>',
    stats: '<path d="M4 20V11M10 20V4M16 20v-6M21 20H3"/>',
    server: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
    settings: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    camera: '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
    grip: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
    send: '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
    x: '<path d="M18 6L6 18M6 6l12 12"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    candle: '<path d="M7 3v18M17 3v18"/><rect x="4" y="7" width="6" height="9" rx="1"/><rect x="14" y="5" width="6" height="8" rx="1"/>',
    play: '<path d="M6 4l14 8-14 8z"/>',
    stop: '<rect x="5" y="5" width="14" height="14" rx="2"/>',
    restart: '<path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5"/>',
    next: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    check: '<path d="M20 6L9 17l-5-5"/>',
    reset: '<path d="M3 3v6h6"/><path d="M3.5 13a9 9 0 1 0 2.1-6.6L3 9"/>',
  };
  const ic = (n) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[n] + '</svg>';

  /* ── định dạng ── */
  const smoney = (v) => (v > 0 ? '+' : '') + money(v || 0);
  const upCls = (v) => (v > 0 ? 'up' : v < 0 ? 'down' : '');
  const upCol = (v) => (v > 0 ? 'var(--up)' : v < 0 ? 'var(--down)' : 'var(--text)');
  const pad = (n) => String(n).padStart(2, '0');
  const local = (t) => new Date((t + S.off) * 1000);
  const fHM = (t) => { const d = local(t); return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()); };
  const fDM = (t) => { const d = local(t); return pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1); };
  const fShort = (t) => (fDM(t) === fDM(S.now || t) ? fHM(t) : fDM(t));
  const fDT = (t) => fDM(t) + ' ' + fHM(t);
  const fLocal = (t, size) => { const d = new Date(t * 1000); const dm = pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1); return size >= 86400 ? dm + '/' + d.getUTCFullYear() : dm + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()); };
  function dur(sec) {
    sec = Math.max(0, Math.round(sec));
    if (sec < 60) return sec + ' giây';
    if (sec < 3600) return Math.floor(sec / 60) + ' phút';
    if (sec < 86400) { const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h + ' giờ' + (m ? ' ' + m + ' phút' : ''); }
    return Math.floor(sec / 86400) + ' ngày' + (sec % 86400 >= 3600 ? ' ' + Math.floor(sec % 86400 / 3600) + ' giờ' : '');
  }
  const ago = (t) => (t ? dur(S.now - t) + ' trước' : 'chưa có');
  const vnd = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(v || 0)) + ' đ';
  const headUrl = (a, s) => 'api/head.php?u=' + encodeURIComponent(a.uuid || '') + '&n=' + encodeURIComponent(a.name || '') + '&s=' + (s || 64);
  const dotCls = (cls) => (cls === 'good' ? '' : cls);
  const accById = (id) => (S.data ? S.data.accounts.find((a) => a.id === +id) : null);

  /* ── gọi máy chủ ── */
  async function api(path, body) {
    const opt = { credentials: 'same-origin', headers: { 'X-CSRF': Q.csrf } };
    if (body !== undefined) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    let r;
    try { r = await fetch('api/' + path, opt); } catch (e) { throw new Error('Không kết nối được web (mạng yếu?)'); }
    let j = null;
    try { j = await r.json(); } catch (e) { /* không phải JSON */ }
    if (r.status === 401) { location.href = 'login.php'; throw new Error('Hết phiên đăng nhập'); }
    if (!r.ok || !j || j.ok === 0) throw new Error((j && j.error) || 'Lỗi máy chủ ' + r.status);
    return j;
  }

  function toast(msg, kind) {
    const box = $('#toasts'); if (!box) return;
    const t = document.createElement('div'); t.className = 'toast' + (kind ? ' ' + kind : ''); t.textContent = msg; box.appendChild(t);
    setTimeout(() => t.remove(), kind === 'err' ? 6000 : 3500);
  }
  const fail = (e) => toast(e.message || String(e), 'err');

  async function copy(text) {
    try { await navigator.clipboard.writeText(text); } catch (e) {
      const ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e2) { /* bỏ qua */ } ta.remove();
    }
    toast('Đã copy: ' + (text.length > 40 ? text.slice(0, 40) + '…' : text), 'ok');
  }

  function modal(html, opts) {
    opts = opts || {};
    const bg = document.createElement('div'); bg.className = 'modal-bg';
    bg.innerHTML = '<div class="modal ' + (opts.cls || '') + '" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(bg);
    const key = (e) => { if (e.key === 'Escape') { const all = document.querySelectorAll('.modal-bg'); if (all[all.length - 1] === bg) close(); } };
    function close() { if (!bg.isConnected) return; bg.remove(); document.removeEventListener('keydown', key); if (opts.onClose) opts.onClose(); }
    document.addEventListener('keydown', key);
    bg.addEventListener('click', (e) => { if (e.target === bg || e.target.closest('[data-close]')) close(); });
    return { el: bg.firstChild, close };
  }
  function confirmBox(msg, okText, danger) {
    return new Promise((res) => {
      let done = false;
      const m = modal('<div class="modal-b"><div style="font-size:15px">' + msg + '</div><div class="row" style="justify-content:flex-end"><button class="btn" data-close>Thôi</button><button class="btn ' + (danger ? 'danger' : 'pri') + '" data-ok>' + esc(okText || 'Đồng ý') + '</button></div></div>', { cls: 'sm', onClose: () => { if (!done) res(false); } });
      $('[data-ok]', m.el).onclick = () => { done = true; m.close(); res(true); };
    });
  }

  // ảnh head lỗi → dùng đầu Steve
  document.addEventListener('error', (e) => {
    const t = e.target;
    if (t && t.tagName === 'IMG' && t.src.indexOf('api/head.php') >= 0 && !t.dataset.fb) { t.dataset.fb = '1'; t.src = 'assets/head-default.png'; }
  }, true);

  /* ── khung trang ── */
  const NAV = [['tongquan', 'Tổng quan', 'overview'], ['thongke', 'Thống kê', 'stats'], ['vps', 'VPS', 'server'], ['caidat', isOwner ? 'Cài đặt' : 'Tài khoản', 'settings']];
  if (isOwner) NAV.push(['nguoidung', 'Người dùng', 'users']);

  function shell() {
    $('#app').innerHTML =
      '<header class="top"><div class="brand"><img src="assets/icon-192.png" alt="" width="44" height="44"><div><h1 id="siteName">' + esc(Q.site) + '</h1>' +
      '<div class="sub"><span class="live"><i class="dot warn" id="liveDot"></i><span id="liveTxt">Đang kết nối…</span></span></div></div></div>' +
      '<div class="top-r"><label class="search">' + ic('search') + '<input id="q" type="search" placeholder="Tìm acc, IP, home, VPS, nhóm…" autocomplete="off" aria-label="Tìm acc"></label>' +
      '<button class="btn icon" id="refreshBtn" title="Làm mới" aria-label="Làm mới">' + ic('refresh') + '</button>' +
      '<a class="btn icon" href="logout.php" title="Đăng xuất (' + esc(Q.me.name) + ')" aria-label="Đăng xuất">' + ic('logout') + '</a></div></header>' +
      '<nav class="nav glass" id="nav">' + NAV.map((n) => '<a href="#' + n[0] + '" data-r="' + n[0] + '">' + ic(n[2]) + '<span>' + n[1] + '</span></a>').join('') + '</nav>' +
      '<div id="page" class="page"></div>';
    $('#q').addEventListener('input', (e) => { S.q = e.target.value.trim().toLowerCase(); if (S.route !== 'tongquan') location.hash = '#tongquan'; else renderList(); });
    $('#refreshBtn').onclick = () => { refresh(true); if (S.route === 'tongquan') loadCandles(true); };
  }

  function updateLive() {
    const dot = $('#liveDot'), txt = $('#liveTxt'); if (!dot || !S.data) return;
    const age = Math.round((Date.now() - S.fetchedAt) / 1000), sum = S.data.summary;
    dot.className = 'dot ' + (!S.ok || age > 40 ? 'bad' : sum.stat.bad ? 'warn' : '');
    txt.textContent = (S.ok ? '' : 'Mất kết nối web · ') + 'DonutSMP · ' + sum.count + ' acc · cập nhật ' + (age < 3 ? 'vừa xong' : age + ' giây trước');
  }

  function route() {
    const r = (location.hash || '#tongquan').slice(1);
    S.route = NAV.some((n) => n[0] === r) ? r : 'tongquan';
    $$('#nav a').forEach((a) => a.classList.toggle('on', a.dataset.r === S.route));
    if (S.chart) { S.chart.destroy(); S.chart = null; }
    S.rowHtml = {}; S.rowOrder = '';
    const el = $('#page');
    el.onclick = null; el.onsubmit = null;
    el.innerHTML = '<div class="empty"><div class="spin" style="margin:30px auto 10px"></div>Đang tải…</div>';
    ({ tongquan: pageOverview, thongke: pageStats, vps: pageVps, caidat: pageSettings, nguoidung: pageUsers })[S.route](el);
    window.scrollTo(0, 0);
  }

  /* ── lấy dữ liệu định kỳ ── */
  async function refresh(manual) {
    try {
      const j = await api('data.php');
      const first = !S.data;
      S.data = j; S.off = j.tzOffset; S.now = j.now; S.fetchedAt = Date.now(); S.ok = true;
      if (j.site && $('#siteName')) { $('#siteName').textContent = j.site; document.title = (j.summary.stat.bad ? '(' + j.summary.stat.bad + ') ' : '') + j.site; }
      watchStatus(first);
      updateLive();
      if (S.route === 'tongquan' && $('#ov')) renderOverview();
      if (manual) toast('Đã làm mới', 'ok');
    } catch (e) {
      S.ok = false; updateLive();
      if (manual || !S.data) fail(e);
    }
  }
  function watchStatus(first) {
    for (const a of S.data.accounts) {
      const prev = S.prevStatus[a.id];
      if (!first && prev && prev !== a.status) {
        if (a.cls === 'bad') toast(a.name + ': ' + a.label + (a.statusNote ? ' · ' + a.statusNote : ''), 'err');
        else if (a.cls === 'good' && ['off', 'crash', 'lost'].includes(prev)) toast(a.name + ' đã chạy lại: ' + a.label, 'ok');
      }
      S.prevStatus[a.id] = a.status;
    }
  }

  /* ═════════════════════════════ TỔNG QUAN ═════════════════════════════ */
  const TFS = [['1m', '1p', '1 phút'], ['5m', '5p', '5 phút'], ['15m', '15p', '15 phút'], ['30m', '30p', '30 phút'], ['1h', '1h', '1 giờ'], ['4h', '4h', '4 giờ'], ['1d', '1D', '1 ngày'], ['3d', '3D', '3 ngày'], ['1w', '1W', '1 tuần']];
  const LIMIT = { '1m': 600, '5m': 500, '15m': 400, '30m': 400, '1h': 500, '4h': 300, '1d': 200, '3d': 120, '1w': 100 };
  const IND_COL = { ma: ['#ffd59a', '#ff9ad5', '#9ad0ff'], ema: ['#c6a6ff', '#7ef0e0'] };

  function pageOverview(el) {
    if (!S.data) { setTimeout(() => S.route === 'tongquan' && route(), 400); return; }
    el.innerHTML =
      '<div id="ov" class="page">' +
      '<div id="banner"></div>' +
      '<div class="strip glass" id="strip"></div>' +
      '<div class="kpis" id="kpis"></div>' +
      '<div class="main"><div class="col">' +
      '<section class="glass card" id="chartCard" style="position:relative">' +
      '<div class="chart-head"><h2 id="chartTitle"></h2><div class="chart-tools">' +
      '<div class="seg" id="tfSeg">' + TFS.map((t) => '<button data-tf="' + t[0] + '" title="Mỗi nến = ' + t[2] + '"' + (S.tf === t[0] ? ' class="on"' : '') + '>' + t[1] + '</button>').join('') + '</div>' +
      '<select class="input sm" id="chartAcc" aria-label="Chọn acc cho biểu đồ"></select>' +
      '<button class="btn sm" id="indBtn">' + ic('settings') + 'Chỉ báo</button>' +
      '<button class="btn sm icon" id="resetBtn" title="Về khung mặc định" aria-label="Về khung mặc định">' + ic('reset') + '</button>' +
      '</div></div>' +
      '<div class="ind-pop glass2" id="indPop" hidden></div>' +
      '<div class="chart-box" id="chartBox"></div><div class="legend" id="legend"></div></section>' +
      '<section class="glass card" id="listCard"><div class="list-head"><div class="row" id="chips"></div><div class="row" id="listTools"></div></div><div id="list"></div></section>' +
      '</div><aside class="side col" id="side"><div class="goal" id="goal"></div><div class="glass det" id="det"></div></aside></div></div>';

    // biểu đồ
    S.chart = new window.QQChart($('#chartBox'), { onSelect: renderStrip, onNeedMore: loadMore });
    S.chart.setIndicators(S.ind);
    $('#tfSeg').onclick = (e) => { const b = e.target.closest('[data-tf]'); if (!b) return; S.tf = b.dataset.tf; store.set('tf', S.tf); $$('#tfSeg button').forEach((x) => x.classList.toggle('on', x === b)); loadCandles(false); };
    $('#chartAcc').onchange = (e) => { S.chartAcc = e.target.value; loadCandles(false); renderChartTitle(); };
    $('#resetBtn').onclick = () => { S.chart.sel = null; S.chart.resetView(); renderStrip(); };
    $('#indBtn').onclick = (e) => { e.stopPropagation(); const p = $('#indPop'); p.hidden = !p.hidden; if (!p.hidden) renderIndPop(); };
    document.addEventListener('click', closeIndPop);
    renderLegend();

    // danh sách
    $('#chips').onclick = (e) => { const b = e.target.closest('[data-f]'); if (!b) return; S.filter = b.dataset.f; renderChips(); renderList(); };
    $('#list').addEventListener('click', onListClick);
    $('#list').addEventListener('pointerdown', (e) => { const h = e.target.closest('[data-drag]'); if (h) startDrag(e, h.closest('.acc-row')); });
    $('#side').addEventListener('click', onDetClick);
    $('#side').addEventListener('submit', onDetSubmit);

    renderOverview();
    loadCandles(false);
    if (S.sel && accById(S.sel)) openDetail(S.sel); else { S.sel = null; renderSideIdle(); }
  }
  function closeIndPop(e) { const p = $('#indPop'); if (p && !p.hidden && !e.target.closest('#indPop') && !e.target.closest('#indBtn')) p.hidden = true; }

  function renderOverview() {
    renderBanner(); renderStrip(); renderKpis(); renderGoal(); renderChartSelect(); renderChartTitle(); renderChips(); renderListTools(); renderList();
    if (!S.sel) renderSideIdle(); else if (!S.detail || S.detail.acc.id !== S.sel) { const a = accById(S.sel); if (a) detailFill(a); }
  }

  function renderBanner() {
    const d = S.data, el = $('#banner'); if (!el) return;
    let h = '';
    if (!d.accounts.length) {
      h = '<div class="glass card"><h2 style="margin:0 0 6px;font-size:17px">Chưa có acc nào gửi lên</h2><div class="note">' +
        (isOwner ? 'Vào <a href="#caidat">Cài đặt</a> để lấy nội dung file <b>web.txt</b>, chép vào mỗi VPS, rồi bỏ bản mod <b>R491</b> vào thư mục mods. Acc vào game là tự hiện ở đây trong vòng 30 giây. Muốn xem thử trước thì bấm <b>Tạo dữ liệu mẫu</b> trong Cài đặt.' : 'Chủ web chưa nối acc nào.') + '</div></div>';
    } else if (d.hasDemo && isOwner) {
      h = '<div class="strip glass" style="min-height:44px"><span><b style="color:var(--gold)">Đang xem dữ liệu mẫu</b> · ' + d.hasDemo + ' acc mẫu (tên có sẵn, số liệu giả). Khi đã nối acc thật, vào <a href="#caidat">Cài đặt</a> bấm “Xoá dữ liệu mẫu”.</span></div>';
    }
    el.innerHTML = h;
  }

  function renderStrip() {
    const el = $('#strip'); if (!el || !S.data) return;
    const sum = S.data.summary, acc = S.chartAcc !== 'all' ? accById(S.chartAcc) : null;
    const rate = acc ? acc.rate : sum.rate, today = acc ? acc.today : sum.today, total = acc ? acc.bal : sum.total;
    const p = S.chart && S.chart.selected();
    let h = '';
    if (p && p.c) {
      const c = p.c, base = p.prev ? p.prev.c : c.o, ch = c.c - base, pct = base ? ch / base * 100 : 0;
      h = '<span style="font-weight:700">' + fLocal(c.t, S.chart.size) + '</span><span>Mở <b>' + money(c.o) + '</b></span><span>Cao <b>' + money(c.h) + '</b></span><span>Thấp <b>' + money(c.l) + '</b></span><span>Đóng <b>' + money(c.c) + '</b></span>' +
        '<span style="font-weight:700;color:' + upCol(ch) + '">' + smoney(ch) + ' (' + (ch >= 0 ? '+' : '') + pct.toFixed(2) + '%)</span>';
    }
    h += '<span class="grow"></span><span>Profit/giờ <b style="color:var(--gold);font-size:16px">' + money(rate) + '</b></span>' +
      '<span>Hôm nay <b style="font-size:16px;color:' + upCol(today) + '">' + smoney(today) + '</b></span>' +
      '<span>' + (acc ? esc(acc.name) : 'Tổng') + ' <b style="font-size:16px">' + (total === null || total === undefined ? '—' : money(total)) + '</b></span>';
    el.innerHTML = h;
  }

  function renderKpis() {
    const d = S.data, s = d.summary, el = $('#kpis'); if (!el) return;
    const st = s.stat, n = Math.max(1, s.count), vndTxt = d.vndPerM > 0 ? ' · ≈ ' + vnd(s.today / 1e6 * d.vndPerM) : '';
    el.innerHTML =
      '<div class="glass kpi"><span class="l">Tổng tiền tất cả acc</span><span class="v">' + money(s.total) + '</span><span class="s">Hôm qua lời <b style="color:' + upCol(s.yesterday) + '">' + smoney(s.yesterday) + '</b></span></div>' +
      '<div class="glass kpi"><span class="l">Lời hôm nay</span><span class="v" style="color:' + upCol(s.today) + '">' + smoney(s.today) + '</span><span class="s">Tiền bán ' + money(s.todayInc) + vndTxt + '</span></div>' +
      '<div class="glass kpi"><span class="l">Profit / giờ (tất cả acc)</span><span class="v" style="color:var(--gold)">' + money(s.rate) + '</span><span class="s">≈ ' + money(s.rate * 24) + ' / ngày · TB ' + money(s.rate / Math.max(1, s.good)) + ' / acc</span></div>' +
      '<div class="glass kpi"><span class="l">Acc đang chạy</span><span class="v">' + s.good + ' / ' + s.count + '</span>' +
      '<div class="stbar" title="Xanh: đang chạy · Vàng: cần xem · Đỏ: lỗi"><span style="width:' + st.good / n * 100 + '%;background:var(--good)"></span><span style="width:' + st.warn / n * 100 + '%;background:var(--warn)"></span><span style="width:' + st.bad / n * 100 + '%;background:var(--bad)"></span></div>' +
      '<span class="s">' + (st.warn ? '<span class="warn">' + st.warn + ' cần xem</span> · ' : '') + (st.bad ? '<span class="bad">' + st.bad + ' lỗi</span> · ' : '') + d.vpsAlive + ' VPS có watchdog</span></div>';
  }

  function renderGoal() {
    const d = S.data, el = $('#goal'); if (!el) return;
    const goal = d.goal || 1e9, total = d.summary.total, pct = Math.min(100, total / goal * 100), left = Math.max(0, goal - total);
    const perDay = d.summary.rate * 24;
    const eta = left <= 0 ? 'Đã đạt mục tiêu 🎉' : 'còn ' + money(left) + (perDay > 0 ? ' · khoảng ' + Math.max(1, Math.ceil(left / perDay)) + ' ngày nữa' : '');
    el.innerHTML = '<svg aria-hidden="true" viewBox="0 0 390 150" preserveAspectRatio="none"><defs><linearGradient id="gg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2a1550"/><stop offset="0.7" stop-color="#b0507a"/><stop offset="1" stop-color="#f29a62"/></linearGradient></defs>' +
      '<rect width="390" height="150" fill="url(#gg)"/><path d="M0 120 L70 70 L120 100 L190 50 L260 105 L320 75 L390 95 L390 150 L0 150 Z" fill="#3a1b4c"/><path d="M0 140 L90 105 L170 130 L250 100 L330 135 L390 118 L390 150 L0 150 Z" fill="#1d0f2e"/></svg>' +
      '<div class="in"><span class="t">MỤC TIÊU ' + esc(money(goal)) + '</span><span class="v">' + pct.toFixed(1) + '%</span><div class="bar"><span style="width:' + pct + '%"></span></div><span style="font-size:12px;color:#ffe2c4">' + eta + '</span></div>';
  }

  /* biểu đồ nến */
  function renderChartSelect() {
    const sel = $('#chartAcc'); if (!sel) return;
    const accs = S.data.accounts, key = accs.map((a) => a.id + a.name).join();
    if (sel.dataset.key === key) return;
    sel.dataset.key = key;
    if (S.chartAcc !== 'all' && !accById(S.chartAcc)) S.chartAcc = 'all';
    sel.innerHTML = '<option value="all">Tất cả acc (' + accs.length + ')</option>' + accs.map((a) => '<option value="' + a.id + '">' + esc(a.name) + '</option>').join('');
    sel.value = S.chartAcc;
  }
  function renderChartTitle() {
    const el = $('#chartTitle'); if (!el) return;
    const a = S.chartAcc !== 'all' ? accById(S.chartAcc) : null;
    el.innerHTML = a ? '<img src="' + headUrl(a, 64) + '" alt="" width="30" height="30">' + esc(a.name) + ' <span class="faint" style="font-size:13px;font-weight:600">số dư theo thời gian</span>'
      : 'Tổng tiền tất cả acc';
    const sel = $('#chartAcc'); if (sel && sel.value !== String(S.chartAcc)) sel.value = S.chartAcc;
  }
  async function loadCandles(keep) {
    if (!S.chart) return;
    const seq = ++S.cseq, tf = S.tf;
    try {
      const j = await api('candles.php?acc=' + S.chartAcc + '&tf=' + tf + '&limit=' + LIMIT[tf]);
      if (seq !== S.cseq || !S.chart) return;
      let list = j.candles;
      if (keep && S.candles.length && list.length) { const t0 = list[0].t; list = S.candles.filter((c) => c.t < t0).concat(list); }
      S.candles = list; S.more = j.more;
      S.chart.setData(list, j.size, keep);
      renderStrip();
    } catch (e) { if (seq === S.cseq) fail(e); }
  }
  async function loadMore() {
    if (!S.more || !S.candles.length || !S.chart) return;
    const seq = S.cseq;
    try {
      const j = await api('candles.php?acc=' + S.chartAcc + '&tf=' + S.tf + '&limit=300&before=' + S.candles[0].t);
      if (seq !== S.cseq || !S.chart) return;
      const t0 = S.candles[0].t, older = j.candles.filter((c) => c.t < t0);
      S.more = j.more && older.length > 0;
      if (!older.length) return;
      S.candles = older.concat(S.candles);
      S.chart.setData(S.candles, j.size, true);
    } catch (e) { /* bỏ qua, kéo lại là thử lại */ }
  }
  function renderIndPop() {
    const i = S.ind, p = $('#indPop');
    const line = (k, n, label, col) => '<div class="check"><label class="row" style="gap:8px;cursor:pointer"><input type="checkbox" data-on="' + k + '.' + n + '"' + (i[k + 'On'][n] ? ' checked' : '') + '><i style="display:inline-block;width:14px;height:3px;border-radius:2px;background:' + col + '"></i>' + label + '</label>' +
      '<input class="input" type="text" inputmode="numeric" data-p="' + k + '.' + n + '" value="' + i[k][n] + '" aria-label="Số nến ' + label + '"></div>';
    const flag = (k, label) => '<label class="check"><span>' + label + '</span><input type="checkbox" data-flag="' + k + '"' + (i[k] ? ' checked' : '') + '></label>';
    p.innerHTML = '<div class="sec-t"><span>Chỉ báo</span><button class="btn sm icon" data-x aria-label="Đóng">' + ic('x') + '</button></div>' +
      line('ma', 0, 'MA', IND_COL.ma[0]) + line('ma', 1, 'MA', IND_COL.ma[1]) + line('ma', 2, 'MA', IND_COL.ma[2]) + line('ema', 0, 'EMA', IND_COL.ema[0]) + line('ema', 1, 'EMA', IND_COL.ema[1]) +
      flag('boll', 'Bollinger (20, 2)') + flag('vol', 'Cột tiền bán (khối lượng)') + flag('rsi', 'RSI 14') + flag('macd', 'MACD (12, 26, 9)') +
      '<div class="note">MA = giá trung bình N nến gần nhất. EMA giống MA nhưng nghiêng về nến mới. Nến nằm trên đường MA là đang lời đều; cắt xuống dưới là tiền đang chững.</div>';
    p.onclick = (e) => { if (e.target.closest('[data-x]')) p.hidden = true; };
    p.onchange = (e) => {
      const t = e.target;
      if (t.dataset.on) { const [k, n] = t.dataset.on.split('.'); i[k + 'On'][n] = t.checked; }
      if (t.dataset.flag) i[t.dataset.flag] = t.checked;
      if (t.dataset.p) { const [k, n] = t.dataset.p.split('.'); const v = parseInt(t.value, 10); if (v >= 2 && v <= 400) i[k][n] = v; else t.value = i[k][n]; }
      store.set('ind', i); S.chart.setIndicators(i); renderLegend();
    };
  }
  function renderLegend() {
    const el = $('#legend'); if (!el) return; const i = S.ind, out = [];
    i.ma.forEach((p, k) => i.maOn[k] && out.push('<span><i style="background:' + IND_COL.ma[k] + '"></i>MA ' + p + '</span>'));
    i.ema.forEach((p, k) => i.emaOn[k] && out.push('<span><i style="background:' + IND_COL.ema[k] + '"></i>EMA ' + p + '</span>'));
    if (i.boll) out.push('<span><i style="background:#c9b8ff"></i>Bollinger</span>');
    if (i.vol) out.push('<span><i style="background:rgba(94,240,166,.5)"></i>Tiền bán</span>');
    out.push('<span class="faint" style="margin-left:auto">Lăn chuột / 2 ngón để phóng to · kéo để xem lùi · bấm 1 nến để xem chi tiết · nhấp đúp về mặc định</span>');
    el.innerHTML = out.join('');
  }

  /* danh sách acc */
  function filtered() {
    const d = S.data, q = S.q;
    let list = d.accounts.filter((a) => {
      if (S.grp && a.grp !== S.grp) return false;
      if (S.filter === 'good' || S.filter === 'warn' || S.filter === 'bad') { if (a.cls !== S.filter) return false; }
      if (S.filter === 'todo' && a.checked) return false;
      if (q) { const hay = [a.name, a.inst, a.vps, a.ip, a.home, a.grp, a.tags, a.note, a.label].join(' ').toLowerCase(); if (hay.indexOf(q) < 0) return false; }
      return true;
    });
    const rank = { bad: 0, warn: 1, good: 2 };
    const by = {
      order: (a, b) => (a.sort || 1e9) - (b.sort || 1e9) || a.id - b.id,
      today: (a, b) => b.today - a.today, rate: (a, b) => b.rate - a.rate, bal: (a, b) => (b.bal || 0) - (a.bal || 0),
      name: (a, b) => a.name.localeCompare(b.name), status: (a, b) => rank[a.cls] - rank[b.cls] || a.name.localeCompare(b.name),
    };
    return list.sort(by[S.sort] || by.order);
  }
  const ordered = () => S.data.accounts.slice().sort((a, b) => (a.sort || 1e9) - (b.sort || 1e9) || a.id - b.id);

  function renderChips() {
    const el = $('#chips'); if (!el) return; const acc = S.data.accounts, c = (f) => acc.filter(f).length;
    const chips = [['all', 'Tất cả', acc.length], ['good', 'Đang chạy', c((a) => a.cls === 'good')], ['warn', 'Cần xem', c((a) => a.cls === 'warn')], ['bad', 'Lỗi', c((a) => a.cls === 'bad')], ['todo', 'Chưa tick', c((a) => !a.checked)]];
    el.innerHTML = chips.map((x) => '<button class="chip' + (S.filter === x[0] ? ' on' : '') + '" data-f="' + x[0] + '">' + x[1] + ' <b>' + x[2] + '</b></button>').join('');
  }
  function renderListTools() {
    const el = $('#listTools'); if (!el) return;
    const acc = S.data.accounts, done = acc.filter((a) => a.checked).length, groups = S.data.groups;
    if (S.grp && groups.indexOf(S.grp) < 0) S.grp = '';
    const key = [done, acc.length, groups.join('|'), S.sort, S.view, S.grp].join('/');
    if (el.dataset.key === key) return; el.dataset.key = key;
    el.innerHTML =
      '<span class="faint" style="font-size:13px">Đã kiểm tra <b style="color:var(--text)">' + done + '/' + acc.length + '</b> hôm nay</span>' +
      (done < acc.length ? '<button class="btn sm" id="nextBtn">' + ic('next') + 'Acc tiếp chưa tick</button>' : '') +
      '<button class="btn sm" id="tickAll">' + ic('check') + (done === acc.length && acc.length ? 'Bỏ tick hết' : 'Tick hết') + '</button>' +
      (groups.length ? '<select class="input sm" id="grpSel" aria-label="Lọc nhóm"><option value="">Mọi nhóm</option>' + groups.map((g) => '<option' + (g === S.grp ? ' selected' : '') + '>' + esc(g) + '</option>').join('') + '</select>' : '') +
      '<select class="input sm" id="sortSel" aria-label="Sắp xếp">' + [['order', 'Thứ tự kiểm tra'], ['status', 'Lỗi lên trước'], ['today', 'Lời hôm nay'], ['rate', 'Profit / giờ'], ['bal', 'Số dư'], ['name', 'Tên A→Z']].map((o) => '<option value="' + o[0] + '"' + (S.sort === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select>' +
      '<div class="seg"><button data-v="list"' + (S.view === 'list' ? ' class="on"' : '') + '>Danh sách</button><button data-v="grid"' + (S.view === 'grid' ? ' class="on"' : '') + '>Gọn</button></div>';
    const nb = $('#nextBtn'); if (nb) nb.onclick = nextUnchecked;
    $('#tickAll').onclick = async () => {
      const cur = S.data.accounts, all = cur.every((a) => a.checked);
      try { await api('acc.php', { checkAll: !all }); cur.forEach((a) => { a.checked = !all; }); S.rowHtml = {}; renderOverview(); } catch (e) { fail(e); }
    };
    const gs = $('#grpSel'); if (gs) gs.onchange = () => { S.grp = gs.value; store.set('grp', S.grp); renderList(); };
    $('#sortSel').onchange = (e) => { S.sort = e.target.value; store.set('sort', S.sort); renderList(); };
    el.querySelector('.seg').onclick = (e) => { const b = e.target.closest('[data-v]'); if (!b) return; S.view = b.dataset.v; store.set('view', S.view); el.dataset.key = ''; renderListTools(); renderList(); };
  }

  function rowHtml(a, i) {
    const off = a.cls === 'bad';
    const sub = [a.grp, a.inst].filter(Boolean).join(' · ') || '—';
    return '<div class="acc-row' + (S.sel === a.id ? ' sel' : '') + '" data-id="' + a.id + '">' +
      '<span class="n">' + (i + 1) + '</span>' +
      '<button class="drag" data-drag title="Kéo để đổi thứ tự kiểm tra" aria-label="Kéo đổi thứ tự">' + ic('grip') + '</button>' +
      '<span class="hd"><img class="head' + (off ? ' off' : '') + '" src="' + headUrl(a, 64) + '" alt="" width="40" height="40" loading="lazy"></span>' +
      '<div class="nm"><b>' + esc(a.name) + '</b><span>' + esc(sub) + '</span></div>' +
      '<div class="st"><b class="' + a.cls + '"><i class="dot ' + dotCls(a.cls) + '"></i>' + esc(a.label) + '</b><span>' + esc(a.statusNote || a.mode || '') + '</span></div>' +
      '<div class="loc"><div><b>' + esc(a.home || '—') + '</b><span>' + esc(a.vps || 'VPS ?') + ' · ' + esc(a.ip || 'chưa có IP') + '</span></div>' +
      (a.ip ? '<button class="btn sm icon" data-copy="' + esc(a.ip) + '" title="Copy IP ' + esc(a.ip) + '" aria-label="Copy IP">' + ic('copy') + '</button>' : '') + '</div>' +
      '<span class="r bal">' + (a.bal === null ? '—' : money(a.bal)) + '</span>' +
      '<span class="r td ' + upCls(a.today) + '">' + smoney(a.today) + '</span>' +
      '<span class="r rt" style="color:var(--gold)">' + money(a.rate) + '<span class="faint" style="font-weight:500;font-size:11px">/h</span></span>' +
      '<label class="ck" title="Đã kiểm tra hôm nay"><input type="checkbox" data-check' + (a.checked ? ' checked' : '') + ' aria-label="Đã kiểm tra ' + esc(a.name) + '"></label></div>';
  }
  function cmpHtml(a) {
    return '<div class="cmp' + (S.sel === a.id ? ' sel' : '') + '" data-id="' + a.id + '"><img src="' + headUrl(a, 64) + '" alt="" width="32" height="32" loading="lazy"' + (a.cls === 'bad' ? ' style="opacity:.45"' : '') + '>' +
      '<div><b>' + esc(a.name) + '</b><span class="' + a.cls + '">' + esc(a.label) + '</span><span style="color:' + upCol(a.today) + '">' + smoney(a.today) + '</span></div></div>';
  }
  function renderList() {
    const el = $('#list'); if (!el || S.dragging) return;
    const list = filtered();
    if (!list.length) { el.innerHTML = '<div class="empty">' + (S.data.accounts.length ? 'Không có acc nào khớp bộ lọc.' : 'Chưa có acc.') + '</div>'; S.rowOrder = ''; S.rowHtml = {}; return; }
    if (S.view === 'grid') { el.innerHTML = '<div class="compact">' + list.map(cmpHtml).join('') + '</div>'; S.rowOrder = ''; S.rowHtml = {}; return; }
    const order = list.map((a) => a.id).join(',');
    const html = list.map(rowHtml);
    if (order !== S.rowOrder || !el.querySelector('.acc-table')) {
      el.innerHTML = '<div class="acc-table"><div class="acc-row th"><span>#</span><span></span><span></span><span>Acc</span><span>Trạng thái</span><span>Home · VPS · IP</span><span class="r">Số dư</span><span class="r">Hôm nay</span><span class="r">/ giờ</span><span class="ck">✓</span></div>' + html.join('') + '</div>';
      S.rowOrder = order; S.rowHtml = {}; list.forEach((a, i) => { S.rowHtml[a.id] = html[i]; });
      return;
    }
    list.forEach((a, i) => {
      if (S.rowHtml[a.id] === html[i]) return;
      const old = el.querySelector('.acc-row[data-id="' + a.id + '"]'); if (!old) return;
      const tmp = document.createElement('div'); tmp.innerHTML = html[i];
      // giữ ảnh head cũ để khỏi nháy
      const oi = old.querySelector('img'), ni = tmp.firstChild.querySelector('img');
      if (oi && ni && oi.getAttribute('src') === ni.getAttribute('src')) { oi.className = ni.className; ni.replaceWith(oi); }
      old.replaceWith(tmp.firstChild); S.rowHtml[a.id] = html[i];
    });
  }
  function markSel() {
    $$('#list [data-id]').forEach((r) => r.classList.toggle('sel', +r.dataset.id === S.sel));
    S.rowHtml = {}; // lần vẽ sau vẽ lại dòng (class sel thay đổi)
  }

  async function onListClick(e) {
    const cp = e.target.closest('[data-copy]'); if (cp) { e.stopPropagation(); copy(cp.dataset.copy); return; }
    const ck = e.target.closest('[data-check]');
    if (ck) {
      e.stopPropagation(); const id = +ck.closest('[data-id]').dataset.id, a = accById(id);
      try { await api('acc.php', { id, set: { checked: ck.checked } }); a.checked = ck.checked; S.rowHtml[id] = ''; renderChips(); renderListTools(); renderList(); } catch (er) { ck.checked = !ck.checked; fail(er); }
      return;
    }
    if (e.target.closest('.ck') || e.target.closest('[data-drag]')) return;
    const row = e.target.closest('[data-id]'); if (row) openDetail(+row.dataset.id);
  }
  function nextUnchecked() {
    const list = S.sort === 'order' ? filtered() : ordered();
    const startIdx = S.sel ? list.findIndex((a) => a.id === S.sel) : -1;
    const after = list.slice(startIdx + 1).concat(list.slice(0, startIdx + 1));
    const n = after.find((a) => !a.checked && a.id !== S.sel);
    if (n) { openDetail(n.id); const r = $('#list [data-id="' + n.id + '"]'); if (r && !narrow()) r.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    else toast('Đã kiểm tra hết các acc hôm nay 👍', 'ok');
  }

  function startDrag(e, row) {
    if (!row) return;
    if (!canCtl) { toast('Tài khoản chỉ xem không đổi thứ tự được', 'err'); return; }
    if (S.sort !== 'order') { toast('Chọn sắp xếp “Thứ tự kiểm tra” rồi mới kéo đổi thứ tự được'); return; }
    e.preventDefault();
    const box = row.parentNode; S.dragging = true; row.style.opacity = '0.55'; row.style.borderColor = 'rgba(255,213,154,.6)';
    const move = (ev) => {
      for (const r of $$('.acc-row[data-id]', box)) {
        if (r === row) continue; const b = r.getBoundingClientRect();
        if (ev.clientY > b.top && ev.clientY < b.bottom) { box.insertBefore(row, ev.clientY < b.top + b.height / 2 ? r : r.nextSibling); break; }
      }
    };
    const up = async () => {
      window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
      row.style.opacity = ''; row.style.borderColor = ''; S.dragging = false;
      const vis = $$('.acc-row[data-id]', box).map((r) => +r.dataset.id), set = new Set(vis); let k = 0;
      const order = ordered().map((a) => (set.has(a.id) ? vis[k++] : a.id));
      order.forEach((id, i) => { const a = accById(id); if (a) a.sort = i + 1; });
      S.rowOrder = ''; renderList();
      try { await api('acc.php', { order }); } catch (er) { fail(er); }
    };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
  }

  /* khung bên phải khi chưa chọn acc */
  function renderSideIdle() {
    const el = $('#det'); if (!el || S.sel) return;
    const accs = S.data.accounts, bad = accs.filter((a) => a.cls !== 'good').sort((a, b) => (a.cls === 'bad' ? 0 : 1) - (b.cls === 'bad' ? 0 : 1));
    const top = accs.slice().sort((a, b) => b.rate - a.rate).slice(0, 5);
    const item = (a, right) => '<div class="inst" data-open="' + a.id + '" style="cursor:pointer"><img src="' + headUrl(a, 64) + '" alt="" width="28" height="28"><div class="grow"><b>' + esc(a.name) + '</b><span class="' + a.cls + '">' + esc(a.label) + (a.statusNote ? ' · ' + esc(a.statusNote) : '') + '</span></div>' + right + '</div>';
    el.innerHTML = '<div class="sec-t"><span>Cần chú ý</span><span class="faint">' + bad.length + ' acc</span></div>' +
      (bad.length ? bad.slice(0, 8).map((a) => item(a, '<span class="faint" style="font-size:12px">' + ago(a.statusSince) + '</span>')).join('') : '<div class="note">Mọi acc đều đang chạy bình thường.</div>') +
      '<div class="sec-t"><span>Profit / giờ cao nhất</span></div>' + top.map((a) => item(a, '<b style="color:var(--gold)">' + money(a.rate) + '</b>')).join('') +
      '<div class="note">Bấm vào 1 acc trong danh sách để xem chi tiết, chụp màn hình, copy IP và điều khiển từ xa.</div>';
  }

  /* ═════════════ chi tiết 1 acc ═════════════ */
  const narrow = () => window.matchMedia('(max-width: 1180px)').matches;
  const detEl = () => $('#detM') || $('#det');
  let detModal = null;

  function openDetail(id) {
    const a = accById(id); if (!a) return;
    S.sel = id; S.detail = null; markSel();
    if (narrow()) {
      if (!detModal) detModal = modal('<div class="det" id="detM"></div>', { cls: 'sm', onClose: () => { detModal = null; S.sel = null; S.detail = null; markSel(); renderSideIdle(); } });
      detModal.el.addEventListener('click', onDetClick); detModal.el.addEventListener('submit', onDetSubmit);
    }
    detailSkeleton(a); detailFill(a); loadDetail();
  }
  function closeDetail() {
    if (detModal) { detModal.close(); return; }
    S.sel = null; S.detail = null; markSel(); renderSideIdle();
  }
  async function loadDetail() {
    const id = S.sel; if (!id) return; const seq = ++S.detSeq;
    try {
      const j = await api('acc.php?id=' + id);
      if (seq !== S.detSeq || S.sel !== id) return;
      S.detail = j;
      const a = accById(id); if (a) Object.assign(a, j.acc);
      detailFill(j.acc);
    } catch (e) { if (S.sel === id) fail(e); }
  }

  function detailSkeleton(a) {
    const el = detEl(); if (!el) return;
    const sec = (k) => '<div data-sec="' + k + '"' + (k === 'head' ? '' : ' hidden') + '></div>';
    el.innerHTML = sec('head') +
      '<div class="row acts"><button class="btn pri" data-act="shot">' + ic('camera') + 'Xem màn hình</button><button class="btn" data-act="chart">' + ic('candle') + 'Nến acc này</button>' +
      '<button class="btn" data-act="checknext" title="Tick đã kiểm tra rồi mở acc tiếp theo">' + ic('check') + 'Xong · acc tiếp</button></div>' +
      sec('stats') + sec('bars') + sec('kv') +
      (canCtl ? sec('ctrl') +
        '<form class="row" data-form="cmd" style="flex-wrap:nowrap"><select class="input sm" name="t" aria-label="Kiểu"><option value="cmd">Lệnh /</option><option value="chat">Chat</option></select>' +
        '<input class="input" name="a" placeholder="home 1" autocomplete="off" style="min-width:0;flex:1" aria-label="Nội dung lệnh"><button class="btn pri icon" aria-label="Gửi">' + ic('send') + '</button></form>' +
        '<div class="row" data-sec="quick"></div>' : '') +
      sec('cmds') + sec('ev') +
      (canCtl ? '<details data-sec="edit"><summary class="sec-t" style="cursor:pointer;list-style:none"><span>Nhóm · ghi chú · chi phí · tự mở lại ▾</span></summary><div data-edit style="margin-top:12px"></div></details>' : '');
    renderQuick();
    if (canCtl) renderEdit(a);
  }
  function setSec(k, html) { const el = detEl(); const s = el && el.querySelector('[data-sec="' + k + '"]'); if (s) { s.innerHTML = html; s.hidden = !html; } }

  function detailFill(a) {
    const el = detEl(); if (!el || !el.querySelector('[data-sec="head"]')) return;
    const d = S.detail && S.detail.acc.id === a.id ? S.detail : null, st = (d && d.state) || {}, v = d && d.vps;
    const ip = a.ip || (v && v.ip) || '';
    setSec('head', '<div class="det-h"><img src="' + headUrl(a, 128) + '" alt="" width="72" height="72"' + (a.cls === 'bad' ? ' style="opacity:.5"' : '') + '>' +
      '<div style="min-width:0;flex:1"><h2>' + esc(a.name) + '</h2><div class="' + a.cls + '" style="font-size:13px;font-weight:600"><i class="dot ' + dotCls(a.cls) + '"></i> ' + esc(a.label) + (a.statusSince ? ' · ' + dur(S.now - a.statusSince) : '') + '</div>' +
      '<div class="faint" style="font-size:12px">' + esc(a.statusNote || '') + '</div></div>' +
      '<button class="btn sm icon" data-act="close" title="Đóng" aria-label="Đóng">' + ic('x') + '</button></div>');
    const week = d ? d.week : null;
    setSec('stats', '<div class="stats4"><div><div class="l">Số dư</div><div class="v">' + (a.bal === null ? '—' : money(a.bal)) + '</div></div>' +
      '<div><div class="l">Lời hôm nay</div><div class="v ' + upCls(a.today) + '">' + smoney(a.today) + '</div></div>' +
      '<div><div class="l">Profit / giờ</div><div class="v" style="color:var(--gold)">' + money(a.rate) + '</div></div>' +
      '<div><div class="l">7 ngày</div><div class="v ' + (week ? upCls(week.net) : '') + '">' + (week ? smoney(week.net) : '…') + '</div></div></div>');
    if (d) {
      const max = Math.max(1, ...d.days.map((x) => Math.abs(x.net)));
      setSec('bars', '<div class="sec-t"><span>Lời 7 ngày</span><span class="faint">' + week.sells + ' lần bán · tiền bán ' + money(week.inc) + '</span></div><div class="bars7" style="margin-top:8px">' +
        d.days.map((x) => { const dd = new Date(x.t * 1000); return '<div title="' + pad(dd.getUTCDate()) + '/' + pad(dd.getUTCMonth() + 1) + ': ' + smoney(x.net) + ' · ' + x.sells + ' lần bán"><i class="' + (x.net < 0 ? 'neg' : '') + '" style="height:' + Math.max(2, Math.abs(x.net) / max * 70) + 'px"></i>' + pad(dd.getUTCDate()) + '</div>'; }).join('') + '</div>');
    }
    const dim = st.dim ? String(st.dim).replace('minecraft:', '') : '';
    const tok = a.tokenSec === null || a.tokenSec === undefined ? '—' : a.tokenSec > 0 ? 'còn ' + dur(a.tokenSec) : '<span class="bad">hết hạn</span>';
    const machine = [a.ramMb ? 'RAM ' + a.ramMb + (a.ramMax ? '/' + a.ramMax : '') + ' MB' : '', a.fps ? a.fps + ' FPS' : '', a.hp !== null && a.hp !== undefined ? '♥ ' + Math.round(a.hp) : ''].filter(Boolean).join(' · ');
    const mode = (a.mode || '—') + (a.build && a.buildPct !== null && a.buildPct !== undefined ? ' (' + Math.round(a.buildPct) + '%)' : '');
    setSec('kv', '<div class="kv">' +
      '<span>Home</span><span>' + esc(a.home || '—') + '</span>' +
      '<span>Vị trí</span><span class="mono">' + esc(a.pos || '—') + (dim ? ' <span class="faint">· ' + esc(dim) + '</span>' : '') + '</span>' +
      '<span>VPS</span><span class="cp"><span>' + esc(a.vps || '—') + '</span>' + (v ? '<i class="dot ' + (v.alive ? '' : 'bad') + '" title="' + (v.alive ? 'Watchdog đang chạy' : 'Watchdog mất tín hiệu') + '"></i>' : '') + '</span>' +
      '<span>IP</span><span class="cp"><span class="mono">' + esc(ip || '—') + '</span>' + (ip ? '<button class="btn sm icon" data-copy="' + esc(ip) + '" title="Copy IP" aria-label="Copy IP">' + ic('copy') + '</button>' : '') + '</span>' +
      '<span>Instance</span><span>' + esc(a.inst || '—') + '</span>' +
      '<span>Đang chạy</span><span>' + esc(mode) + '</span>' +
      '<span>Server</span><span>' + esc(a.server || '—') + (a.region ? ' · ' + esc(a.region) : '') + '</span>' +
      (machine ? '<span>Máy</span><span>' + esc(machine) + '</span>' : '') +
      '<span>Token</span><span>' + tok + '</span>' +
      '<span>Mod</span><span>' + esc(a.ver || '—') + ' · gửi ' + ago(a.lastSeen) + '</span>' +
      (a.note ? '<span>Ghi chú</span><span>' + esc(a.note) + '</span>' : '') + '</div>');
    if (canCtl) {
      const vOk = !!(v && v.alive), dis = vOk ? '' : ' disabled title="VPS này chưa cài script watchdog (Cài đặt → mục 2)"';
      setSec('ctrl', '<div class="sec-t"><span>Điều khiển</span><span class="faint" style="font-weight:500">' + (d ? (vOk ? 'VPS có watchdog' : 'VPS chưa có watchdog') : '') + '</span></div><div class="ctrl" style="margin-top:8px">' +
        '<button class="btn" data-act="restart"' + dis + '>' + ic('restart') + 'Mở lại game</button>' +
        '<button class="btn danger" data-act="kill"' + dis + '>' + ic('stop') + 'Tắt game</button>' +
        '<button class="btn" data-act="launch"' + dis + '>' + ic('play') + 'Mở game</button>' +
        '<button class="btn" data-act="sell" data-a="' + (a.sellMode ? 'off' : 'on') + '">' + (a.sellMode ? 'Tắt' : 'Bật') + ' AutoSell</button>' +
        '<button class="btn" data-act="build" data-a="' + (a.build ? 'off' : 'on') + '">' + (a.build ? 'Tắt' : 'Bật') + ' AutoBuild</button>' +
        '<button class="btn" data-act="count" data-a="' + (a.counting ? 'off' : 'on') + '">' + (a.counting ? 'Tắt' : 'Bật') + ' đếm tiền</button></div>');
    }
    if (d) {
      const stName = { new: 'chờ', sent: 'đã gửi', done: 'xong', fail: 'lỗi' }, tName = { shot: 'Chụp màn hình', cmd: 'Lệnh', chat: 'Chat', sell: 'AutoSell', build: 'AutoBuild', count: 'Đếm tiền', launch: 'Mở game', kill: 'Tắt game', restart: 'Mở lại game' };
      const cm = d.cmds.filter((c) => c.t !== 'shot').slice(0, 5);
      setSec('cmds', cm.length ? '<div class="sec-t"><span>Lệnh gần đây</span></div><div class="ev" style="margin-top:8px">' + cm.map((c) =>
        '<div><time>' + fShort(+c.created) + '</time><span>' + esc(tName[c.t] || c.t) + (c.t === 'launch' || c.t === 'kill' || c.t === 'restart' ? '' : ' ' + esc(c.t === 'cmd' ? '/' + String(c.a).replace(/^\//, '') : c.a)) +
        (c.result ? ' <span class="faint">· ' + esc(c.result) + '</span>' : '') + '</span><span class="' + (c.status === 'done' ? 'good' : c.status === 'fail' ? 'bad' : 'warn') + '">' + (stName[c.status] || c.status) + '</span></div>').join('') + '</div>' : '');
      const kc = { sell: 'up', alert: 'warn', disc: 'bad', dead: 'bad', cmdfail: 'bad', status: '', join: 'good', cmd: '' };
      setSec('ev', '<div class="sec-t"><span>Hoạt động</span><span class="faint" style="font-weight:500">' + d.events.length + ' gần nhất</span></div><div class="ev" style="margin-top:8px">' +
        (d.events.length ? d.events.map((x) => '<div><time>' + fShort(x.t) + '</time><span class="' + (kc[x.kind] === 'up' ? '' : kc[x.kind] || '') + '">' + esc(x.msg) + '</span><span class="' + (x.val > 0 ? 'up' : x.val < 0 ? 'down' : '') + '" style="font-weight:700">' + (x.val !== null ? smoney(x.val) : '') + '</span></div>').join('') : '<div class="note">Chưa có hoạt động.</div>') + '</div>');
    }
  }

  function renderQuick() {
    const el = detEl(); const q = el && el.querySelector('[data-sec="quick"]'); if (!q) return;
    const list = store.get('quick', ['home 1', 'bal', 'spawn']);
    q.innerHTML = list.slice(0, 8).map((c) => '<button class="chip" type="button" data-quick="' + esc(c) + '">/' + esc(c) + '</button>').join('');
  }
  function renderEdit(a) {
    const el = detEl(); const box = el && el.querySelector('[data-edit]'); if (!box) return;
    const groups = S.data.groups;
    box.innerHTML = '<form class="set-sec" data-form="edit">' +
      '<div class="grid2" style="grid-template-columns:repeat(2,minmax(0,1fr))"><label class="field"><span>Nhóm</span><input class="input" name="grp" list="grpList" value="' + esc(a.grp) + '" placeholder="vd VPS-A, Bán đêm"></label>' +
      '<label class="field"><span>Nhãn</span><input class="input" name="tags" value="' + esc(a.tags) + '" placeholder="vd main, alt"></label>' +
      '<label class="field"><span>Chi phí / tháng (VNĐ)</span><input class="input" name="cost" inputmode="numeric" value="' + (a.cost || '') + '" placeholder="0"></label>' +
      '<label class="field"><span>Hạn VPS</span><input class="input" type="date" name="vpsExpire" value="' + esc(a.vpsExpire || '') + '"></label></div>' +
      '<datalist id="grpList">' + groups.map((g) => '<option value="' + esc(g) + '">').join('') + '</datalist>' +
      '<label class="field"><span>Ghi chú</span><textarea class="input" name="note" rows="2" placeholder="vd acc chính, đừng tắt">' + esc(a.note) + '</textarea></label>' +
      '<label class="check"><input type="checkbox" name="autoRestart"' + (a.autoRestart ? ' checked' : '') + '>Tự mở lại game khi crash (cần watchdog trên VPS)</label>' +
      '<label class="check"><input type="checkbox" name="hide">Ẩn acc này khỏi web (acc đã bán / bỏ)</label>' +
      '<div class="row"><button class="btn pri sm">Lưu</button>' + (isOwner ? '<button class="btn danger sm" type="button" data-act="delete">Xoá acc khỏi web</button>' : '') + '</div></form>';
  }

  async function onDetClick(e) {
    const open = e.target.closest('[data-open]'); if (open) { openDetail(+open.dataset.open); return; }
    const cp = e.target.closest('[data-copy]'); if (cp) { copy(cp.dataset.copy); return; }
    const qk = e.target.closest('[data-quick]');
    if (qk) { const f = detEl().querySelector('[data-form="cmd"]'); f.t.value = 'cmd'; f.a.value = qk.dataset.quick; f.a.focus(); return; }
    const b = e.target.closest('[data-act]'); if (!b || b.disabled) return;
    const a = accById(S.sel); if (!a) return;
    const act = b.dataset.act;
    if (act === 'close') { closeDetail(); return; }
    if (act === 'shot') { shot(a); return; }
    if (act === 'chart') {
      if (S.route !== 'tongquan') return;
      S.chartAcc = String(a.id); renderChartTitle(); loadCandles(false);
      if (detModal) detModal.close();
      $('#chartCard').scrollIntoView({ behavior: 'smooth', block: 'start' }); return;
    }
    if (act === 'checknext') {
      try { if (!a.checked) { await api('acc.php', { id: a.id, set: { checked: true } }); a.checked = true; S.rowHtml[a.id] = ''; renderChips(); renderListTools(); renderList(); } } catch (er) { fail(er); return; }
      nextUnchecked(); return;
    }
    if (act === 'delete') {
      if (!(await confirmBox('Xoá <b>' + esc(a.name) + '</b> khỏi web? Lịch sử tiền của acc này cũng bị xoá. Nếu mod còn chạy, acc sẽ tự hiện lại sau 30 giây.', 'Xoá', true))) return;
      try { await api('acc.php', { id: a.id, delete: 1 }); toast('Đã xoá ' + a.name, 'ok'); closeDetail(); refresh(); } catch (er) { fail(er); }
      return;
    }
    const names = { restart: 'Mở lại game', kill: 'Tắt game', launch: 'Mở game' };
    if (names[act]) {
      if (act !== 'launch' && !(await confirmBox(names[act] + ' của <b>' + esc(a.name) + '</b> trên ' + esc(a.vps) + '?', names[act], act === 'kill'))) return;
      sendCmd(a, act, '', names[act]); return;
    }
    if (['sell', 'build', 'count'].includes(act)) sendCmd(a, act, b.dataset.a, b.textContent.trim());
  }
  async function onDetSubmit(e) {
    const f = e.target; e.preventDefault();
    const a = accById(S.sel); if (!a) return;
    if (f.dataset.form === 'cmd') {
      const text = f.a.value.trim().replace(f.t.value === 'cmd' ? /^\// : /^$/, ''); if (!text) return;
      if (f.t.value === 'cmd') { const q = store.get('quick', ['home 1', 'bal', 'spawn']).filter((x) => x !== text); q.unshift(text); store.set('quick', q.slice(0, 8)); renderQuick(); }
      await sendCmd(a, f.t.value, text, f.t.value === 'cmd' ? '/' + text : 'Chat');
      f.a.value = '';
    }
    if (f.dataset.form === 'edit') {
      const set = { grp: f.grp.value, tags: f.tags.value, cost: f.cost.value || 0, vpsExpire: f.vpsExpire.value, note: f.note.value, autoRestart: f.autoRestart.checked };
      if (f.hide.checked) { if (!(await confirmBox('Ẩn <b>' + esc(a.name) + '</b> khỏi web? (Không xoá dữ liệu; muốn hiện lại thì nhờ chủ web sửa trong cơ sở dữ liệu hoặc tạo lại.)', 'Ẩn'))) return; set.hidden = true; }
      try { await api('acc.php', { id: a.id, set }); toast('Đã lưu', 'ok'); Object.assign(a, set); if (set.hidden) closeDetail(); refresh(); } catch (er) { fail(er); }
    }
  }
  async function sendCmd(a, t, arg, label) {
    try {
      await api('cmd.php', { acc: a.id, t, a: arg });
      toast(label + ' → ' + a.name + ': đã gửi, chờ ' + (['launch', 'kill', 'restart'].includes(t) ? 'VPS nhận (tối đa 1 phút)' : 'mod nhận (vài giây)'), 'ok');
      setTimeout(loadDetail, 800);
    } catch (e) { fail(e); }
  }

  /* chụp màn hình: bấm Thoát là xoá ảnh trên web */
  function shot(a) {
    let alive = true, cmdId = 0;
    const m = modal('<div class="modal-h"><h3>Màn hình ' + esc(a.name) + '</h3><div class="row"><button class="btn sm" data-re>' + ic('camera') + 'Chụp lại</button><button class="btn sm pri" data-close>' + ic('x') + 'Thoát & xoá ảnh</button></div></div>' +
      '<div class="shot" id="shotBox"></div><div class="modal-b"><span class="note">Ảnh chỉ lưu tạm trên web để xem. Bấm “Thoát & xoá ảnh” là xoá ngay (quên thì 15 phút sau tự xoá).</span></div>',
    { onClose: () => { alive = false; api('shot.php', { acc: a.id, del: 1 }).catch(() => {}); } });
    const box = $('#shotBox', m.el);
    const msg = (t, spin) => { box.innerHTML = '<div style="padding:20px">' + (spin ? '<div class="spin" style="margin:0 auto 12px"></div>' : '') + t + '</div>'; };
    async function run() {
      msg('Đang gửi lệnh chụp…', true);
      try {
        const r = await api('cmd.php', { acc: a.id, t: 'shot' }); cmdId = r.id; const my = cmdId, t0 = Date.now();
        while (alive && my === cmdId) {
          const j = await api('shot.php?acc=' + a.id + '&cmd=' + my + '&info=1');
          if (!alive || my !== cmdId) return;
          if (j.ready) { box.innerHTML = '<img src="api/shot.php?acc=' + a.id + '&t=' + Date.now() + '" alt="Ảnh màn hình ' + esc(a.name) + '"><span class="tag">' + esc(a.name) + ' · chụp lúc ' + fHM(j.t) + '</span>'; return; }
          if (j.cmdStatus === 'fail') { msg('Không chụp được: ' + esc(j.result || 'lỗi')); return; }
          const s = Math.round((Date.now() - t0) / 1000);
          if (s > 75) { msg('Acc không trả lời sau ' + s + ' giây. Game có thể đang tắt, treo hoặc mod chưa phải bản R491.'); return; }
          msg(j.cmdStatus === 'sent' ? 'Mod đã nhận lệnh, đang chụp và gửi ảnh…' : 'Đang chờ mod nhận lệnh (mod hỏi web 30 giây / lần, tối đa ~30 giây)… ' + s + 's', true);
          await new Promise((res) => setTimeout(res, 1500));
        }
      } catch (e) { if (alive) msg(esc(e.message)); }
    }
    $('[data-re]', m.el).onclick = run;
    run();
  }

  /* ═════════════════════════════ THỐNG KÊ ═════════════════════════════ */
  async function pageStats(el) {
    let j;
    try { j = await api('stats.php?days=' + S.statDays); } catch (e) { el.innerHTML = '<div class="glass card empty">' + esc(e.message) + '</div>'; return; }
    if (S.route !== 'thongke') return;
    const daily = j.daily, maxD = Math.max(1, ...daily.map((d) => Math.abs(d.net))), sumNet = daily.reduce((s, d) => s + d.net, 0);
    const every = Math.ceil(daily.length / 12);
    const dayLbl = (t) => { const d = new Date(t * 1000); return pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1); };
    const bars = daily.map((d, i) => '<div title="' + dayLbl(d.t) + ': ' + smoney(d.net) + ' · tiền bán ' + money(d.inc) + ' · ' + d.sells + ' lần bán"><i style="height:' + Math.max(2, Math.abs(d.net) / maxD * 120) + 'px' + (d.net < 0 ? ';background:var(--down)' : '') + '"></i>' + (i % every === 0 ? dayLbl(d.t).slice(0, 5) : '&nbsp;') + '</div>').join('');
    const hMax = Math.max(1, ...j.hourly.map((h) => h.avg)), best = j.hourly.slice().sort((a, b) => b.avg - a.avg)[0];
    const hours = j.hourly.map((h) => '<div title="' + h.h + 'h: TB ' + money(h.avg) + '"><i style="height:' + Math.max(2, h.avg / hMax * 120) + 'px"></i>' + (h.h % 3 === 0 ? h.h : '&nbsp;') + '</div>').join('');
    const pr = j.price.filter((p) => p.avg !== null);
    const mo = j.money;
    el.innerHTML =
      '<div class="row" style="justify-content:space-between"><div class="seg" id="dSeg">' + [7, 30, 90].map((n) => '<button data-d="' + n + '"' + (S.statDays === n ? ' class="on"' : '') + '>' + n + ' ngày</button>').join('') + '</div>' +
      '<div class="row"><a class="btn sm" href="api/export.php?days=7" download>' + ic('download') + 'CSV 7 ngày</a><a class="btn sm" href="api/export.php?days=30" download>' + ic('download') + 'CSV 30 ngày</a><a class="btn sm" href="api/export.php?days=90" download>' + ic('download') + 'CSV 90 ngày</a></div></div>' +
      '<section class="glass card"><div class="chart-head"><h2>Lời mỗi ngày (tất cả acc)</h2><span class="muted">' + S.statDays + ' ngày: <b style="color:' + upCol(sumNet) + '">' + smoney(sumNet) + '</b> · TB ' + smoney(sumNet / daily.length) + ' / ngày</span></div>' +
      '<div class="hbars" style="grid-template-columns:repeat(' + daily.length + ',minmax(0,1fr));height:160px">' + bars + '</div></section>' +
      '<div class="grid2" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr))">' +
      '<section class="glass card"><div class="chart-head"><h2>Giờ bán chạy</h2><span class="muted">' + (best && best.avg > 0 ? 'mạnh nhất lúc ' + best.h + 'h (TB ' + money(best.avg) + ')' : 'chưa đủ dữ liệu') + '</span></div><div class="hbars">' + hours + '</div><div class="note" style="margin-top:8px">Tiền bán trung bình mỗi giờ trong ngày, tính 14 ngày gần nhất.</div></section>' +
      '<section class="glass card"><div class="chart-head"><h2>Giá bán trung bình / lần</h2><span class="muted">' + (pr.length ? 'gần nhất ' + money(pr[pr.length - 1].avg) : '') + '</span></div>' + priceSvg(pr) + '<div class="note" style="margin-top:8px">Tiền bán chia số lần bán mỗi ngày. Đường đi xuống = giá chợ đang tụt.</div></section>' +
      '<section class="glass card"><div class="chart-head"><h2>Lời thật 30 ngày</h2></div><div class="stats4">' +
      '<div><div class="l">Lời game 30 ngày</div><div class="v ' + upCls(mo.net30) + '">' + smoney(mo.net30) + '</div></div>' +
      '<div><div class="l">Quy ra VNĐ</div><div class="v">' + (mo.vndPerM > 0 ? vnd(mo.net30 / 1e6 * mo.vndPerM) : '—') + '</div></div>' +
      '<div><div class="l">Chi phí VPS + acc / tháng</div><div class="v">' + vnd(mo.cost) + '</div></div>' +
      '<div><div class="l">Lời thật</div><div class="v ' + (mo.real30 === null ? '' : upCls(mo.real30)) + '">' + (mo.real30 === null ? '—' : vnd(mo.real30)) + '</div></div></div>' +
      '<div class="note" style="margin-top:8px">' + (mo.vndPerM > 0 ? 'Tính theo giá 1M = ' + vnd(mo.vndPerM) + '. ' : 'Điền “giá bán 1M ra VNĐ” trong Cài đặt để tính lời thật. ') + 'Chi phí lấy từ tiền thuê VPS (trang VPS) và chi phí từng acc.</div></section></div>' +
      '<section class="glass card"><div class="chart-head"><h2>Xếp hạng acc (7 ngày)</h2><span class="muted">bấm 1 dòng để mở acc</span></div><div class="scroll-x"><table class="tbl" id="rankTbl"><thead><tr><th>#</th><th>Acc</th><th class="r">Lời 7 ngày</th><th class="r">/ giờ TB</th><th class="r">Tiền bán</th><th class="r">Lần bán</th><th class="r">Thời gian chạy</th><th class="r">Lời 30 ngày</th><th class="r">Chi phí</th><th class="r">Lời thật 30 ngày</th></tr></thead><tbody>' +
      j.rank.map((r, i) => '<tr data-id="' + r.id + '" style="cursor:pointer"><td class="faint">' + (i + 1) + '</td><td><div class="who"><img src="' + headUrl(r, 64) + '" alt="" width="30" height="30"><div><b>' + esc(r.name) + '</b>' + (r.grp ? '<div class="faint" style="font-size:12px">' + esc(r.grp) + '</div>' : '') + '</div></div></td>' +
        '<td class="r ' + upCls(r.net7) + '" style="font-weight:700">' + smoney(r.net7) + '</td><td class="r" style="color:var(--gold)">' + money(r.perHour7) + '</td><td class="r">' + money(r.inc7) + '</td><td class="r">' + r.sells7 + '</td>' +
        '<td class="r">' + Math.round(r.uptime7 * 100) + '%</td><td class="r ' + upCls(r.net30) + '">' + smoney(r.net30) + '</td><td class="r">' + (r.cost ? vnd(r.cost) : '—') + '</td><td class="r ' + (r.real30 === null ? '' : upCls(r.real30)) + '">' + (r.real30 === null ? '—' : vnd(r.real30)) + '</td></tr>').join('') +
      '</tbody></table></div>' + (j.rank.length ? '' : '<div class="empty">Chưa có acc.</div>') + '</section>';
    $('#dSeg').onclick = (e) => { const b = e.target.closest('[data-d]'); if (!b) return; S.statDays = +b.dataset.d; pageStats(el); };
    $('#rankTbl').onclick = (e) => { const tr = e.target.closest('[data-id]'); if (!tr) return; S.sel = +tr.dataset.id; location.hash = '#tongquan'; };
  }
  function priceSvg(pr) {
    if (pr.length < 2) return '<div class="empty">Chưa đủ dữ liệu.</div>';
    const W = 600, H = 150, lo = Math.min(...pr.map((p) => p.avg)), hi = Math.max(...pr.map((p) => p.avg)), span = hi - lo || hi || 1;
    const pts = pr.map((p, i) => [i / (pr.length - 1) * W, H - 12 - (p.avg - lo) / span * (H - 30)]);
    const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" style="width:100%;height:150px;display:block" role="img" aria-label="Giá bán trung bình mỗi lần theo ngày">' +
      '<defs><linearGradient id="pf" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffd59a" stop-opacity=".35"/><stop offset="1" stop-color="#ffd59a" stop-opacity="0"/></linearGradient></defs>' +
      '<path d="' + d + ' L' + W + ' ' + H + ' L0 ' + H + ' Z" fill="url(#pf)"/><path d="' + d + '" fill="none" stroke="#ffd59a" stroke-width="2" vector-effect="non-scaling-stroke"/>' +
      '<text x="4" y="14" fill="#cdbde4" font-size="12">' + money(hi) + '</text><text x="4" y="' + (H - 2) + '" fill="#cdbde4" font-size="12">' + money(lo) + '</text></svg>';
  }

  /* ═════════════════════════════ VPS ═════════════════════════════ */
  async function pageVps(el) {
    let j;
    try { j = await api('vpsadmin.php'); } catch (e) { el.innerHTML = '<div class="glass card empty">' + esc(e.message) + '</div>'; return; }
    if (S.route !== 'vps') return;
    S.now = j.now;
    const totalCost = j.vps.reduce((s, v) => s + (v.cost || 0), 0);
    el.innerHTML = '<div class="strip glass"><span><b>' + j.vps.length + '</b> VPS</span><span><b>' + j.vps.filter((v) => v.alive).length + '</b> có watchdog đang chạy</span><span>Tiền thuê <b>' + vnd(totalCost) + '</b> / tháng</span><span class="grow"></span>' +
      (isOwner ? '<a class="btn sm" href="api/watchdog.php" download>' + ic('download') + 'Tải file cài watchdog</a>' : '') + '</div>' +
      (j.vps.length ? '<div class="vps-grid">' + j.vps.map(vpsCard).join('') + '</div>' : '<div class="glass card empty">Chưa có VPS nào. Acc gửi lên kèm tên VPS (dòng <b>vps=</b> trong web.txt) hoặc cài watchdog là VPS tự hiện ở đây.</div>');
    el.onclick = async (e) => {
      const cp = e.target.closest('[data-copy]'); if (cp) { copy(cp.dataset.copy); return; }
      const b = e.target.closest('[data-vcmd]');
      if (b) {
        const a = { id: +b.dataset.acc, name: b.dataset.name, vps: b.dataset.vps }, t = b.dataset.vcmd, lbl = { restart: 'Mở lại game', kill: 'Tắt game', launch: 'Mở game' }[t];
        if (t !== 'launch' && !(await confirmBox(lbl + ' của <b>' + esc(a.name) + '</b>?', lbl, t === 'kill'))) return;
        sendCmd(a, t, '', lbl); return;
      }
      const del = e.target.closest('[data-vdel]');
      if (del) {
        if (!(await confirmBox('Xoá VPS <b>' + esc(del.dataset.vdel) + '</b> khỏi danh sách? (Watchdog còn chạy thì nó tự hiện lại.)', 'Xoá', true))) return;
        try { await api('vpsadmin.php', { name: del.dataset.vdel, delete: 1 }); pageVps(el); } catch (er) { fail(er); }
      }
      const acc = e.target.closest('[data-goacc]'); if (acc) { S.sel = +acc.dataset.goacc; location.hash = '#tongquan'; }
    };
    el.onsubmit = async (e) => {
      e.preventDefault(); const f = e.target;
      try { await api('vpsadmin.php', { name: f.dataset.vps, set: { cost: f.cost.value || 0, expire: f.expire.value, note: f.note.value } }); toast('Đã lưu ' + f.dataset.vps, 'ok'); pageVps(el); } catch (er) { fail(er); }
    };
  }
  function vpsCard(v) {
    const info = v.info || {}, inst = info.instances || [];
    const linked = new Set(v.accs.map((a) => a.inst));
    const other = inst.filter((i) => !linked.has(i.name));
    const ram = info.totalRamMb ? 'RAM trống ' + (info.freeRamMb / 1024).toFixed(1) + ' / ' + (info.totalRamMb / 1024).toFixed(1) + ' GB' : '';
    const status = v.alive ? '<span class="good">watchdog ' + ago(v.lastSeen) + '</span>' : v.watchdog && v.lastSeen ? '<span class="bad">mất tín hiệu ' + ago(v.lastSeen) + '</span>' : '<span class="faint">chưa cài watchdog</span>';
    const expSoon = v.expire && (new Date(v.expire) - Date.now()) / 864e5 < 4;
    return '<div class="glass vps-card"><div class="row" style="justify-content:space-between"><b style="font-size:16px;display:flex;align-items:center;gap:8px"><i class="dot ' + (v.alive ? '' : v.watchdog ? 'bad' : 'warn') + '"></i>' + esc(v.name) + '</b><span style="font-size:12.5px">' + status + '</span></div>' +
      '<div class="kv"><span>IP</span><span class="cp"><span class="mono">' + esc(v.ip || '—') + '</span>' + (v.ip ? '<button class="btn sm icon" data-copy="' + esc(v.ip) + '" aria-label="Copy IP">' + ic('copy') + '</button>' : '') + '</span>' +
      (v.host ? '<span>Tên máy</span><span>' + esc(v.host) + '</span>' : '') +
      (ram || info.cpu ? '<span>Máy</span><span>' + [ram, info.cpu ? 'CPU ' + info.cpu + '%' : '', info.uptimeSec ? 'bật ' + dur(info.uptimeSec) : ''].filter(Boolean).join(' · ') + '</span>' : '') +
      ((v.cost || v.expire) ? '<span>Thuê</span><span>' + (v.cost ? vnd(v.cost) + ' / tháng' : '') + (v.expire ? ' · hết hạn <span class="' + (expSoon ? 'bad' : '') + '">' + esc(v.expire) + '</span>' : '') + '</span>' : '') +
      (v.note ? '<span>Ghi chú</span><span>' + esc(v.note) + '</span>' : '') + '</div>' +
      '<div class="sec-t"><span>Acc trên VPS</span><span class="faint">' + v.accs.length + '</span></div>' +
      v.accs.map((a) => {
        const run = inst.find((i) => i.name === a.inst);
        return '<div class="inst"><img src="' + headUrl(a, 64) + '" alt="" width="28" height="28"><div class="grow"><b style="cursor:pointer" data-goacc="' + a.id + '">' + esc(a.name) + '</b><span><span class="' + a.cls + '">' + esc(a.label) + '</span> · ' + esc(a.inst || '?') + (run ? ' · ' + (run.running ? (run.ramMb ? Math.round(run.ramMb) + ' MB' : 'đang chạy') : 'game tắt') : '') + '</span></div>' +
          (canCtl && v.alive ? '<div class="row" style="gap:6px"><button class="btn sm" data-vcmd="restart" data-acc="' + a.id + '" data-name="' + esc(a.name) + '" data-vps="' + esc(v.name) + '">Mở lại</button><button class="btn sm danger" data-vcmd="kill" data-acc="' + a.id + '" data-name="' + esc(a.name) + '" data-vps="' + esc(v.name) + '">Tắt</button></div>' : '') + '</div>';
      }).join('') +
      (other.length ? '<div class="note">Instance khác trên máy: ' + other.map((i) => esc(i.name) + (i.running ? ' (đang chạy)' : '')).join(', ') + '</div>' : '') +
      (isOwner ? '<details><summary class="sec-t" style="cursor:pointer;list-style:none"><span>Sửa tiền thuê · hạn · ghi chú ▾</span></summary><form class="set-sec" data-vps="' + esc(v.name) + '" style="margin-top:10px">' +
        '<div class="grid2" style="grid-template-columns:repeat(2,minmax(0,1fr))"><label class="field"><span>Tiền thuê / tháng (VNĐ)</span><input class="input" name="cost" inputmode="numeric" value="' + (v.cost || '') + '"></label>' +
        '<label class="field"><span>Hết hạn</span><input class="input" type="date" name="expire" value="' + esc(v.expire || '') + '"></label></div>' +
        '<label class="field"><span>Ghi chú</span><input class="input" name="note" value="' + esc(v.note || '') + '" placeholder="vd nhà cung cấp, tài khoản"></label>' +
        '<div class="row"><button class="btn pri sm">Lưu</button><button class="btn danger sm" type="button" data-vdel="' + esc(v.name) + '">Xoá VPS</button></div></form></details>' : '') + '</div>';
  }

  /* ═════════════════════════════ CÀI ĐẶT ═════════════════════════════ */
  function passwordCard() {
    return '<form class="glass card set-sec" id="pwForm"><h2>Đổi mật khẩu (' + esc(Q.me.name) + ')</h2><div class="grid2"><label class="field"><span>Mật khẩu cũ</span><input class="input" type="password" name="old" autocomplete="current-password" required></label>' +
      '<label class="field"><span>Mật khẩu mới (ít nhất 6 ký tự)</span><input class="input" type="password" name="new" autocomplete="new-password" minlength="6" required></label>' +
      '<label class="field"><span>Nhập lại mật khẩu mới</span><input class="input" type="password" name="new2" autocomplete="new-password" minlength="6" required></label></div><div class="row"><button class="btn pri">Đổi mật khẩu</button></div></form>';
  }
  function bindPassword() {
    const f = $('#pwForm'); if (!f) return;
    f.onsubmit = async (e) => {
      e.preventDefault(); e.stopPropagation();
      if (f.new.value !== f.new2.value) { toast('Hai lần nhập mật khẩu mới không giống nhau', 'err'); return; }
      try { await api('me.php', { old: f.old.value, new: f.new.value }); toast('Đã đổi mật khẩu', 'ok'); f.reset(); } catch (er) { fail(er); }
    };
  }
  async function pageSettings(el) {
    if (!isOwner) { el.innerHTML = passwordCard() + '<div class="glass card note">Quyền của bạn: <b>' + (Q.me.role === 'control' ? 'Xem + điều khiển' : 'Chỉ xem') + '</b>. Chỉ chủ web mới sửa được cài đặt.</div>'; bindPassword(); return; }
    let j;
    try { j = await api('settings.php'); } catch (e) { el.innerHTML = '<div class="glass card empty">' + esc(e.message) + '</div>'; return; }
    if (S.route !== 'caidat') return;
    const s = j.settings, inf = j.info;
    const inp = (k, label, ph, type) => '<label class="field"><span>' + label + '</span><input class="input" name="' + k + '" value="' + esc(s[k]) + '"' + (ph ? ' placeholder="' + esc(ph) + '"' : '') + (type ? ' type="' + type + '"' : '') + '></label>';
    const chk = (k, label) => '<label class="check"><input type="checkbox" name="' + k + '"' + (s[k] === '1' ? ' checked' : '') + '>' + label + '</label>';
    const grid = 'style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start"';
    el.innerHTML = '<div ' + grid + '>' +
      '<section class="glass card set-sec"><h2>1. Nối mod với web</h2>' +
      '<div class="note">Bản mod <b>R491</b> đọc file <b class="mono">%USERPROFILE%\\.quangquy\\web.txt</b> trên mỗi VPS. Nội dung file như dưới, sửa dòng <b>vps=</b> thành tên VPS (vd VPS-01). Cách nhanh hơn: dùng file cài ở mục 2, nó tự tạo web.txt.</div>' +
      '<pre class="code mono">' + esc(j.webTxt) + '</pre><div class="row"><button class="btn sm" type="button" data-copy="' + esc(j.webTxt) + '">' + ic('copy') + 'Copy nội dung</button><button class="btn sm danger" type="button" id="regenKey">Đổi key</button></div>' +
      '<div class="note">Đổi key khi lỡ để lộ file web.txt. Đổi xong phải sửa lại web.txt trên mọi VPS.</div></section>' +
      '<section class="glass card set-sec"><h2>2. Script trên VPS (mở / tắt game từ xa)</h2>' +
      '<div class="note">Tải file <b>cai-dat-QQ.bat</b>, chép vào từng VPS, nhấp đúp, gõ tên VPS. Nó tạo web.txt và cài 1 tác vụ chạy ngầm mỗi phút: báo VPS còn sống, game nào đang chạy, biết acc bị <b>crash</b> hay VPS bị <b>tắt</b>, và nhận lệnh Mở lại / Tắt game. Tác vụ rất nhẹ (vài MB RAM, chạy 1–2 giây mỗi phút).</div>' +
      '<div class="row"><a class="btn pri" href="api/watchdog.php" download>' + ic('download') + 'Tải cai-dat-QQ.bat</a></div>' +
      '<div class="note">Cần Prism Launcher. Script tự tìm Prism và thư mục instances; tên instance trong Prism phải giữ nguyên.</div></section>' +
      '<form class="glass card set-sec" data-set><h2>3. Báo động Discord</h2>' +
      inp('discord_webhook', 'Link webhook (Cài đặt kênh → Tích hợp → Webhook → Sao chép URL)', 'https://discord.com/api/webhooks/…') +
      inp('discord_mention', 'Tag ai khi báo lỗi (không bắt buộc)', '@everyone hoặc <@id của bạn>') +
      '<div class="grid2" style="gap:0 12px">' + chk('alert_offline', 'Acc offline / mất tín hiệu') + chk('alert_crash', 'Game crash') + chk('alert_idle', 'Đứng yên không bán') + chk('alert_drop', 'Tiền tụt bất thường') +
      chk('alert_token', 'Token sắp hết hạn') + chk('alert_ram', 'RAM gần đầy') + chk('alert_recover', 'Báo khi acc chạy lại') + '</div>' +
      '<div class="grid2">' + inp('alert_repeat_min', 'Nhắc lại nếu chưa sửa (phút)', '30', 'number') + inp('report_time', 'Giờ gửi báo cáo cuối ngày', '22:00') + '</div>' +
      '<div class="row"><button class="btn pri">Lưu</button><button class="btn" type="button" id="testDiscord">Gửi thử</button></div></form>' +
      '<form class="glass card set-sec" data-set><h2>4. Ngưỡng & tự động</h2><div class="grid2">' +
      inp('idle_min', 'Coi là đứng yên sau (phút không có tiền)', '20', 'number') + inp('restart_max_hour', 'Tự mở lại tối đa (lần / giờ / acc)', '3', 'number') +
      inp('drop_pct', 'Báo khi tiền tụt quá (%) trong 10 phút', '20', 'number') + inp('drop_min_m', 'và tụt ít nhất (triệu $)', '5', 'number') + '</div>' +
      '<div class="note">Bật “Tự mở lại game khi crash” cho từng acc trong khung chi tiết acc.</div><div class="row"><button class="btn pri">Lưu</button></div></form>' +
      '<form class="glass card set-sec" data-set><h2>5. Mục tiêu & quy đổi tiền</h2><div class="grid2">' +
      inp('goal_m', 'Mục tiêu tổng tiền (triệu $; 1000 = 1B)', '1000', 'number') + inp('vnd_per_m', 'Giá bán 1M $ ra VNĐ (0 = không tính)', '0', 'number') + '</div><div class="row"><button class="btn pri">Lưu</button></div></form>' +
      '<form class="glass card set-sec" data-set><h2>6. Chung</h2><div class="grid2">' +
      inp('site_name', 'Tên web', 'Trạm theo dõi acc') + '<label class="field"><span>Múi giờ</span><input class="input" name="tz" list="tzList" value="' + esc(s.tz) + '"></label>' +
      inp('default_server', 'Server mặc định khi mở game', 'donutsmp.net') + inp('keep_m1_days', 'Giữ nến 1 phút (ngày)', '14', 'number') + '</div>' +
      '<datalist id="tzList">' + ['Asia/Ho_Chi_Minh', 'Asia/Bangkok', 'Asia/Jakarta', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'UTC'].map((z) => '<option value="' + z + '">').join('') + '</datalist>' +
      '<div class="row"><button class="btn pri">Lưu</button></div></form>' +
      '<section class="glass card set-sec"><h2>Dữ liệu mẫu</h2><div class="note">12 acc mẫu với 30 ngày số liệu giả để xem thử giao diện. Acc mẫu không điều khiển được. Xoá đi khi đã nối acc thật.</div>' +
      '<div class="row"><button class="btn" type="button" data-demo="create">Tạo dữ liệu mẫu</button><button class="btn danger" type="button" data-demo="clear">Xoá dữ liệu mẫu</button></div></section>' +
      '<section class="glass card set-sec"><h2>Hệ thống</h2><div class="kv"><span>Phiên bản</span><span>' + esc(inf.version) + '</span><span>PHP</span><span>' + esc(inf.php) + '</span><span>' + esc(inf.database || 'SQLite') + '</span><span>' + esc(inf.sqlite) + (inf.database === 'PostgreSQL' ? '' : ' · ' + inf.dbMb + ' MB') + '</span>' +
      '<span>Cache tạm</span><span class="' + (inf.writable ? 'good' : 'bad') + '">' + (inf.writable ? 'được' : 'không ghi được') + '</span><span>cURL</span><span class="' + (inf.curl ? 'good' : 'warn') + '">' + (inf.curl ? 'có' : 'không (vẫn chạy, dùng cách khác)') + '</span>' +
      '<span>Địa chỉ</span><span class="mono" style="overflow-wrap:anywhere">' + esc(j.baseUrl) + '</span></div></section>' +
      passwordCard() + '</div>';
    bindPassword();
    el.onclick = async (e) => {
      const cp = e.target.closest('[data-copy]'); if (cp) { copy(cp.dataset.copy); return; }
      if (e.target.closest('#regenKey')) {
        if (!(await confirmBox('Đổi key kết nối? Mọi VPS sẽ <b>ngừng gửi</b> cho tới khi sửa lại web.txt với key mới.', 'Đổi key', true))) return;
        try { await api('settings.php', { regenKey: 1 }); toast('Đã đổi key', 'ok'); pageSettings(el); } catch (er) { fail(er); }
        return;
      }
      if (e.target.closest('#testDiscord')) {
        const f = e.target.closest('form');
        try { await api('settings.php', { set: { discord_webhook: f.discord_webhook.value, discord_mention: f.discord_mention.value } }); await api('settings.php', { testDiscord: 1 }); toast('Đã gửi tin thử vào Discord', 'ok'); } catch (er) { fail(er); }
        return;
      }
      const dm = e.target.closest('[data-demo]');
      if (dm) {
        const c = dm.dataset.demo === 'create';
        if (!(await confirmBox(c ? 'Tạo 12 acc mẫu với số liệu giả?' : 'Xoá toàn bộ acc mẫu?', c ? 'Tạo' : 'Xoá', !c))) return;
        dm.disabled = true;
        try { await api('settings.php', { demo: dm.dataset.demo }); toast(c ? 'Đã tạo dữ liệu mẫu' : 'Đã xoá dữ liệu mẫu', 'ok'); S.data = null; await refresh(); } catch (er) { fail(er); }
        dm.disabled = false;
      }
    };
    el.onsubmit = async (e) => {
      const f = e.target; if (!f.hasAttribute('data-set')) return; e.preventDefault();
      const set = {};
      $$('input[name]', f).forEach((i) => { set[i.name] = i.type === 'checkbox' ? (i.checked ? '1' : '0') : i.value; });
      try { await api('settings.php', { set }); toast('Đã lưu', 'ok'); if (set.site_name) { Q.site = set.site_name; $('#siteName').textContent = set.site_name; } refresh(); } catch (er) { fail(er); }
    };
  }

  /* ═════════════════════════════ NGƯỜI DÙNG ═════════════════════════════ */
  async function pageUsers(el) {
    let j;
    try { j = await api('users.php'); } catch (e) { el.innerHTML = '<div class="glass card empty">' + esc(e.message) + '</div>'; return; }
    if (S.route !== 'nguoidung') return;
    const roles = [['owner', 'Chủ web'], ['control', 'Xem + điều khiển'], ['view', 'Chỉ xem']];
    const roleSel = (r, name) => '<select class="input sm" name="' + name + '">' + roles.map((x) => '<option value="' + x[0] + '"' + (x[0] === r ? ' selected' : '') + '>' + x[1] + '</option>').join('') + '</select>';
    const t = (x) => (x ? fDT(+x) : '—');
    el.innerHTML =
      '<section class="glass card"><h2>Người dùng</h2><div class="scroll-x"><table class="tbl"><thead><tr><th>Tên</th><th>Quyền</th><th>Chỉ thấy nhóm</th><th>Mật khẩu mới</th><th>Đăng nhập gần nhất</th><th></th></tr></thead><tbody>' +
      j.users.map((u) => '<tr data-uid="' + u.id + '"><td><b>' + esc(u.username) + '</b>' + (+u.id === j.me ? ' <span class="faint">(bạn)</span>' : '') + '</td><td>' + roleSel(u.role, 'role') + '</td>' +
        '<td><input class="input sm" name="groups" value="' + esc(u.groups || '') + '" placeholder="trống = mọi acc" style="width:160px"></td><td><input class="input sm" type="password" name="password" placeholder="giữ nguyên" autocomplete="new-password" style="width:130px"></td>' +
        '<td class="faint">' + t(u.last_login) + '</td><td><div class="row" style="gap:6px;flex-wrap:nowrap"><button class="btn sm pri" data-save>Lưu</button>' + (+u.id === j.me ? '' : '<button class="btn sm danger" data-del>Xoá</button>') + '</div></td></tr>').join('') +
      '</tbody></table></div>' +
      '<div class="note" style="margin-top:10px"><b>Chủ web</b>: toàn quyền. <b>Xem + điều khiển</b>: xem, chụp màn hình, gửi lệnh, mở / tắt game, không vào cài đặt. <b>Chỉ xem</b>: chỉ xem số liệu. <b>Chỉ thấy nhóm</b>: điền “A, B” để người này chỉ thấy acc thuộc nhóm A và B.</div></section>' +
      '<form class="glass card set-sec" id="newUser"><h2>Thêm người dùng</h2><div class="grid2"><label class="field"><span>Tên đăng nhập</span><input class="input" name="username" required minlength="3" autocomplete="off"></label>' +
      '<label class="field"><span>Mật khẩu (ít nhất 6 ký tự)</span><input class="input" type="password" name="password" required minlength="6" autocomplete="new-password"></label>' +
      '<label class="field"><span>Quyền</span>' + roleSel('view', 'role').replace('input sm', 'input') + '</label><label class="field"><span>Chỉ thấy nhóm (không bắt buộc)</span><input class="input" name="groups" placeholder="trống = mọi acc"></label></div>' +
      '<div class="row"><button class="btn pri">Thêm</button></div></form>' +
      '<section class="glass card"><h2>Nhật ký thao tác</h2><div class="scroll-x"><table class="tbl"><thead><tr><th>Lúc</th><th>Ai</th><th>Làm gì</th><th>Chi tiết</th></tr></thead><tbody>' +
      j.audit.map((a) => '<tr><td class="faint" style="white-space:nowrap">' + t(a.t) + '</td><td>' + esc(a.user) + '</td><td>' + esc(a.action) + '</td><td class="muted">' + esc(a.detail) + '</td></tr>').join('') +
      '</tbody></table></div>' + (j.audit.length ? '' : '<div class="empty">Chưa có.</div>') + '</section>';
    el.onclick = async (e) => {
      const tr = e.target.closest('[data-uid]'); if (!tr) return; const id = +tr.dataset.uid;
      if (e.target.closest('[data-save]')) {
        const upd = { id, role: tr.querySelector('[name=role]').value, groups: tr.querySelector('[name=groups]').value }; const pw = tr.querySelector('[name=password]').value; if (pw) upd.password = pw;
        try { await api('users.php', { update: upd }); toast('Đã lưu', 'ok'); pageUsers(el); } catch (er) { fail(er); }
      }
      if (e.target.closest('[data-del]')) {
        if (!(await confirmBox('Xoá người dùng <b>' + esc(tr.querySelector('b').textContent) + '</b>?', 'Xoá', true))) return;
        try { await api('users.php', { delete: id }); pageUsers(el); } catch (er) { fail(er); }
      }
    };
    $('#newUser').onsubmit = async (e) => {
      e.preventDefault(); const f = e.target;
      try { await api('users.php', { create: { username: f.username.value, password: f.password.value, role: f.role.value, groups: f.groups.value } }); toast('Đã thêm ' + f.username.value, 'ok'); pageUsers(el); } catch (er) { fail(er); }
    };
  }

  /* ── khởi động ── */
  shell();
  window.addEventListener('hashchange', route);
  refresh().then(route);
  let tick = 0;
  setInterval(() => {
    if (document.hidden) return;
    tick++;
    updateLive();
    if (tick % 10 === 0) refresh();
    if (S.route === 'tongquan' && S.chart && tick % (S.tf === '1m' ? 15 : 30) === 0) loadCandles(true);
    if (S.sel && tick % 5 === 0 && detEl() && detEl().querySelector('[data-sec="head"]')) loadDetail();
  }, 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); if (S.chart) loadCandles(true); } });
  window.addEventListener('resize', () => {
    // màn to ↔ nhỏ: chuyển chi tiết giữa khung bên phải và cửa sổ nổi
    if (!S.sel || S.route !== 'tongquan') return;
    if (narrow() && !detModal && $('#det') && $('#det').querySelector('[data-sec="head"]')) { const id = S.sel; S.sel = null; renderSideIdle(); openDetail(id); }
  });
})();
