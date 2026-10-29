/* Trạm theo dõi acc — biểu đồ nến (canvas, không cần thư viện ngoài).
 * Nến + khối lượng (tiền bán) + MA / EMA / Bollinger + khung RSI / MACD.
 * Lăn chuột = phóng to / thu nhỏ, kéo = cuộn, chạm 2 ngón = phóng to, nhấp đúp = về mặc định. */
(function () {
  'use strict';

  const C = {
    up: '#5ef0a6', down: '#ff5d7d', grid: 'rgba(255,255,255,0.07)', axis: '#cdbde4', faint: 'rgba(205,189,228,0.55)',
    cross: 'rgba(255,255,255,0.45)', ma: ['#ffd59a', '#ff9ad5', '#9ad0ff'], ema: ['#c6a6ff', '#7ef0e0'], boll: '#c9b8ff',
    rsi: '#ffd59a', macd: '#9ad0ff', signal: '#ff9ad5', label: '#140c2c', font: '11px "Plus Jakarta Sans", system-ui, sans-serif',
  };
  const AXIS_W = 78, TIME_H = 24, GAP = 8;

  /* ── chỉ báo ── */
  function sma(v, p) {
    const out = new Array(v.length).fill(null); let s = 0;
    for (let i = 0; i < v.length; i++) { s += v[i]; if (i >= p) s -= v[i - p]; if (i >= p - 1) out[i] = s / p; }
    return out;
  }
  function ema(v, p) {
    const out = new Array(v.length).fill(null); const k = 2 / (p + 1); let e = null;
    for (let i = 0; i < v.length; i++) {
      if (e === null) { if (i === p - 1) { let s = 0; for (let j = 0; j < p; j++) s += v[j]; e = s / p; out[i] = e; } continue; }
      e = v[i] * k + e * (1 - k); out[i] = e;
    }
    return out;
  }
  function boll(v, p, m) {
    const mid = sma(v, p), up = new Array(v.length).fill(null), lo = new Array(v.length).fill(null);
    for (let i = p - 1; i < v.length; i++) {
      let s = 0; for (let j = i - p + 1; j <= i; j++) s += (v[j] - mid[i]) ** 2;
      const sd = Math.sqrt(s / p); up[i] = mid[i] + m * sd; lo[i] = mid[i] - m * sd;
    }
    return { mid, up, lo };
  }
  function rsi(v, p) {
    const out = new Array(v.length).fill(null); let g = 0, l = 0;
    for (let i = 1; i < v.length; i++) {
      const d = v[i] - v[i - 1], up = Math.max(d, 0), dn = Math.max(-d, 0);
      if (i <= p) { g += up; l += dn; if (i === p) { g /= p; l /= p; out[i] = l === 0 ? 100 : 100 - 100 / (1 + g / l); } continue; }
      g = (g * (p - 1) + up) / p; l = (l * (p - 1) + dn) / p; out[i] = l === 0 ? 100 : 100 - 100 / (1 + g / l);
    }
    return out;
  }
  function macd(v, f, s, sig) {
    const ef = ema(v, f), es = ema(v, s), line = v.map((_, i) => ef[i] !== null && es[i] !== null ? ef[i] - es[i] : null);
    const first = line.findIndex((x) => x !== null), sl = new Array(v.length).fill(null);
    if (first >= 0) { const e = ema(line.slice(first), sig); for (let i = 0; i < e.length; i++) sl[first + i] = e[i]; }
    return { line, sig: sl, hist: line.map((x, i) => x !== null && sl[i] !== null ? x - sl[i] : null) };
  }

  function money(v, short) {
    const a = Math.abs(v), s = v < 0 ? '-' : '';
    if (a >= 1e9) return s + '$' + (a / 1e9).toFixed(short ? 2 : 3).replace(/\.?0+$/, '') + 'B';
    if (a >= 1e6) return s + '$' + (a / 1e6).toFixed(a >= 1e8 ? 1 : 2).replace(/\.?0+$/, '') + 'M';
    if (a >= 1e3) return s + '$' + (a / 1e3).toFixed(a >= 1e5 ? 0 : 1).replace(/\.?0+$/, '') + 'K';
    return s + '$' + Math.round(a);
  }
  function pad(n) { return String(n).padStart(2, '0'); }
  function tlabel(t, size, full) {
    const d = new Date(t * 1000);
    const hm = pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()), dm = pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1);
    if (full) return size >= 86400 ? dm + '/' + d.getUTCFullYear() : dm + ' ' + hm;
    if (size >= 86400) return dm;
    if (d.getUTCHours() === 0 && d.getUTCMinutes() === 0) return dm;
    return hm;
  }

  class QQChart {
    constructor(box, opts) {
      this.box = box; this.opts = opts || {};
      this.cv = document.createElement('canvas'); box.appendChild(this.cv);
      this.ctx = this.cv.getContext('2d');
      this.tip = document.createElement('div'); this.tip.className = 'chart-tip'; this.tip.hidden = true; box.appendChild(this.tip);
      this.data = []; this.size = 3600; this.ind = { ma: [7, 25, 99], maOn: [true, true, false], ema: [9, 21], emaOn: [false, false], boll: false, vol: true, rsi: false, macd: false };
      this.count = 90; this.end = 0; this.hover = null; this.sel = null; this.calc = {};
      this.dpr = Math.max(1, window.devicePixelRatio || 1);
      this._bind();
      this._ro = new ResizeObserver(() => this.resize()); this._ro.observe(box);
      this.resize();
    }

    setData(candles, size, keepView) {
      const old = this.data.length;
      const wasEnd = this.end >= old - 1.5;
      const prepended = keepView && old && candles.length > old && this.data[0] && candles[candles.length - old] && candles[candles.length - old].t === this.data[0].t;
      this.data = candles || []; this.size = size || this.size;
      if (!keepView || !old) { this.count = Math.min(Math.max(30, this.data.length), this._fitCount()); this.end = this.data.length - 1; this.sel = null; }
      else if (prepended) { this.end += candles.length - old; if (this.sel !== null) this.sel += candles.length - old; }
      else if (wasEnd) { this.end = this.data.length - 1; }
      this._compute(); this.draw();
    }
    setIndicators(ind) { this.ind = Object.assign({}, this.ind, ind); this._compute(); this.draw(); }
    resetView() { this.count = Math.min(Math.max(30, this.data.length), this._fitCount()); this.end = this.data.length - 1; this.draw(); }
    selected() { const i = this.sel !== null ? this.sel : this.data.length - 1; return i >= 0 ? { i, c: this.data[i], prev: this.data[i - 1] } : null; }
    destroy() { this._ro.disconnect(); this.box.innerHTML = ''; }

    _fitCount() { return Math.max(30, Math.floor((this.w - AXIS_W) / 9)); }

    _compute() {
      const cl = this.data.map((c) => c.c), r = {};
      r.ma = this.ind.ma.map((p, k) => this.ind.maOn[k] && p > 1 ? sma(cl, p) : null);
      r.ema = this.ind.ema.map((p, k) => this.ind.emaOn[k] && p > 1 ? ema(cl, p) : null);
      r.boll = this.ind.boll ? boll(cl, 20, 2) : null;
      r.rsi = this.ind.rsi ? rsi(cl, 14) : null;
      r.macd = this.ind.macd ? macd(cl, 12, 26, 9) : null;
      this.calc = r;
    }

    resize() {
      const r = this.box.getBoundingClientRect();
      this.w = Math.max(200, r.width); this.h = Math.max(200, r.height);
      this.cv.width = Math.round(this.w * this.dpr); this.cv.height = Math.round(this.h * this.dpr);
      this.draw();
    }

    _layout() {
      const plotW = this.w - AXIS_W, rest = this.h - TIME_H;
      const subs = (this.ind.rsi ? 1 : 0) + (this.ind.macd ? 1 : 0);
      const subH = subs ? Math.round(rest * (subs === 2 ? 0.18 : 0.22)) : 0;
      const mainH = rest - subs * (subH + GAP);
      const L = { plotW, main: { y: 0, h: mainH } };
      let y = mainH + GAP;
      if (this.ind.rsi) { L.rsi = { y, h: subH }; y += subH + GAP; }
      if (this.ind.macd) { L.macd = { y, h: subH }; y += subH + GAP; }
      L.timeY = this.h - TIME_H;
      return L;
    }

    _range() {
      const n = this.data.length;
      this.count = Math.max(10, Math.min(Math.max(10, n), this.count));
      this.end = Math.max(Math.min(this.count - 1, n - 1), Math.min(n - 1 + this.count * 0.3, this.end));
      const first = Math.max(0, Math.floor(this.end - this.count + 1)), last = Math.min(n - 1, Math.ceil(this.end));
      return { first, last };
    }
    _x(i, L) { const slot = L.plotW / this.count; return L.plotW - (this.end - i + 0.5) * slot; }
    _idx(x, L) { const slot = L.plotW / this.count; return Math.round(this.end + 0.5 - (L.plotW - x) / slot); }

    draw() {
      if (!this.ctx || !this.w) return;
      const g = this.ctx, d = this.dpr; g.setTransform(d, 0, 0, d, 0, 0); g.clearRect(0, 0, this.w, this.h);
      g.font = C.font; g.textBaseline = 'middle';
      if (!this.data.length) { g.fillStyle = C.axis; g.textAlign = 'center'; g.fillText('Chưa có dữ liệu cho khung này', this.w / 2, this.h / 2); return; }
      const L = this._layout(), { first, last } = this._range(), slot = L.plotW / this.count;
      const bw = Math.max(1, Math.min(18, slot * 0.68));

      // thang giá
      let lo = Infinity, hi = -Infinity;
      for (let i = first; i <= last; i++) { const c = this.data[i]; if (c.l < lo) lo = c.l; if (c.h > hi) hi = c.h; }
      if (this.calc.boll) for (let i = first; i <= last; i++) { const u = this.calc.boll.up[i], l2 = this.calc.boll.lo[i]; if (u !== null) { hi = Math.max(hi, u); lo = Math.min(lo, l2); } }
      if (!isFinite(lo)) { lo = 0; hi = 1; }
      if (hi - lo < Math.max(1, hi * 0.0005)) { const m = (hi + lo) / 2; hi = m + Math.max(1, m * 0.001); lo = m - Math.max(1, m * 0.001); }
      const padP = (hi - lo) * 0.08; hi += padP; lo -= padP;
      const volH = this.ind.vol ? L.main.h * 0.2 : 0, priceH = L.main.h - volH;
      const Y = (v) => L.main.y + 6 + (hi - v) / (hi - lo) * (priceH - 12);
      this._Y = Y; this._L = L; this._lo = lo; this._hi = hi;

      // lưới + nhãn giá
      g.strokeStyle = C.grid; g.lineWidth = 1; g.fillStyle = C.axis; g.textAlign = 'left';
      const steps = Math.max(3, Math.floor(priceH / 60));
      for (let k = 0; k <= steps; k++) {
        const v = lo + (hi - lo) * k / steps, y = Math.round(Y(v)) + 0.5;
        g.beginPath(); g.moveTo(0, y); g.lineTo(L.plotW, y); g.stroke();
        g.fillText(money(v, true), L.plotW + 8, y);
      }
      // lưới + nhãn thời gian
      const every = Math.max(1, Math.ceil(80 / slot));
      g.textAlign = 'center';
      for (let i = first; i <= last; i++) {
        if (i % every) continue;
        const x = Math.round(this._x(i, L)) + 0.5;
        g.strokeStyle = C.grid; g.beginPath(); g.moveTo(x, 0); g.lineTo(x, L.timeY); g.stroke();
        g.fillStyle = C.axis; g.fillText(tlabel(this.data[i].t, this.size), x, L.timeY + TIME_H / 2);
      }

      g.save(); g.beginPath(); g.rect(0, 0, L.plotW, L.timeY); g.clip();
      // khối lượng
      if (this.ind.vol) {
        let vmax = 0; for (let i = first; i <= last; i++) vmax = Math.max(vmax, this.data[i].v || 0);
        if (vmax > 0) for (let i = first; i <= last; i++) {
          const c = this.data[i], vh = (c.v || 0) / vmax * (volH - 4); if (vh <= 0) continue;
          g.fillStyle = c.c >= c.o ? 'rgba(94,240,166,0.28)' : 'rgba(255,93,125,0.28)';
          g.fillRect(this._x(i, L) - bw / 2, L.main.y + L.main.h - vh, bw, vh);
        }
      }
      // Bollinger
      if (this.calc.boll) {
        const b = this.calc.boll;
        g.fillStyle = 'rgba(201,184,255,0.08)'; g.beginPath(); let st = false;
        for (let i = first; i <= last; i++) { if (b.up[i] === null) continue; const x = this._x(i, L); if (!st) { g.moveTo(x, Y(b.up[i])); st = true; } else g.lineTo(x, Y(b.up[i])); }
        for (let i = last; i >= first; i--) { if (b.lo[i] === null) continue; g.lineTo(this._x(i, L), Y(b.lo[i])); }
        g.closePath(); g.fill();
        this._line(b.up, first, last, L, Y, C.boll, 1, [4, 3]); this._line(b.lo, first, last, L, Y, C.boll, 1, [4, 3]); this._line(b.mid, first, last, L, Y, C.boll, 1);
      }
      // nến
      for (let i = first; i <= last; i++) {
        const c = this.data[i], x = this._x(i, L), up = c.c >= c.o, col = up ? C.up : C.down;
        g.strokeStyle = col; g.lineWidth = 1;
        g.beginPath(); g.moveTo(Math.round(x) + 0.5, Y(c.h)); g.lineTo(Math.round(x) + 0.5, Y(c.l)); g.stroke();
        const y1 = Y(Math.max(c.o, c.c)), y2 = Y(Math.min(c.o, c.c));
        g.fillStyle = col; g.fillRect(x - bw / 2, y1, bw, Math.max(1, y2 - y1));
        if (this.sel === i) { g.strokeStyle = '#ffffff'; g.lineWidth = 1.5; g.strokeRect(x - bw / 2 - 1.5, y1 - 1.5, bw + 3, Math.max(1, y2 - y1) + 3); }
      }
      // MA / EMA
      (this.calc.ma || []).forEach((s, k) => s && this._line(s, first, last, L, Y, C.ma[k], 1.6));
      (this.calc.ema || []).forEach((s, k) => s && this._line(s, first, last, L, Y, C.ema[k], 1.6, [6, 3]));
      g.restore();

      // giá cuối
      const lastC = this.data[this.data.length - 1], ly = Y(lastC.c), lcol = lastC.c >= lastC.o ? C.up : C.down;
      if (ly > 0 && ly < priceH) {
        g.setLineDash([2, 3]); g.strokeStyle = lcol; g.globalAlpha = 0.7; g.beginPath(); g.moveTo(0, ly); g.lineTo(L.plotW, ly); g.stroke(); g.setLineDash([]); g.globalAlpha = 1;
        this._tag(L.plotW + 2, ly, money(lastC.c, true), lcol);
      }

      if (this.ind.rsi && this.calc.rsi) this._rsi(L, first, last);
      if (this.ind.macd && this.calc.macd) this._macd(L, first, last, bw);

      // thước ngắm
      if (this.hover) {
        const { x, y } = this.hover, i = this._idx(x, L);
        if (i >= first && i <= last && x < L.plotW) {
          const cx = Math.round(this._x(i, L)) + 0.5;
          g.strokeStyle = C.cross; g.setLineDash([3, 4]); g.lineWidth = 1;
          g.beginPath(); g.moveTo(cx, 0); g.lineTo(cx, L.timeY); g.stroke();
          if (y < priceH) { g.beginPath(); g.moveTo(0, Math.round(y) + 0.5); g.lineTo(L.plotW, Math.round(y) + 0.5); g.stroke(); }
          g.setLineDash([]);
          if (y < priceH) this._tag(L.plotW + 2, y, money(hi - (y - 6 - L.main.y) / (priceH - 12) * (hi - lo), true), '#ffffff');
          const tl = tlabel(this.data[i].t, this.size, true); const tw = g.measureText(tl).width + 14;
          g.fillStyle = '#ffffff'; g.fillRect(cx - tw / 2, L.timeY + 3, tw, TIME_H - 6); g.fillStyle = C.label; g.textAlign = 'center'; g.fillText(tl, cx, L.timeY + TIME_H / 2);
          this._tip(i, cx, y);
        } else this.tip.hidden = true;
      } else this.tip.hidden = true;
    }

    _line(s, first, last, L, Y, color, w, dash) {
      const g = this.ctx; g.strokeStyle = color; g.lineWidth = w; g.setLineDash(dash || []); g.beginPath(); let st = false;
      for (let i = Math.max(0, first - 1); i <= Math.min(s.length - 1, last + 1); i++) { if (s[i] === null) { st = false; continue; } const x = this._x(i, L), y = Y(s[i]); if (!st) { g.moveTo(x, y); st = true; } else g.lineTo(x, y); }
      g.stroke(); g.setLineDash([]);
    }
    _tag(x, y, text, bg) {
      const g = this.ctx; g.font = C.font; const w = g.measureText(text).width + 12;
      g.fillStyle = bg; g.beginPath(); if (g.roundRect) g.roundRect(x, y - 10, Math.min(w, AXIS_W - 4), 20, 5); else g.rect(x, y - 10, Math.min(w, AXIS_W - 4), 20); g.fill();
      g.fillStyle = C.label; g.textAlign = 'left'; g.fillText(text, x + 6, y);
    }
    _sub(L, p, title, lo, hi, fmt) {
      const g = this.ctx; g.strokeStyle = C.grid; g.strokeRect(0.5, p.y + 0.5, L.plotW - 1, p.h - 1);
      g.fillStyle = C.faint; g.textAlign = 'left'; g.fillText(title, 6, p.y + 10);
      g.fillStyle = C.axis; g.fillText(fmt(hi), L.plotW + 8, p.y + 8); g.fillText(fmt(lo), L.plotW + 8, p.y + p.h - 8);
      return (v) => p.y + 4 + (hi - v) / (hi - lo || 1) * (p.h - 8);
    }
    _rsi(L, first, last) {
      const p = L.rsi, s = this.calc.rsi, Y = this._sub(L, p, 'RSI 14', 0, 100, (v) => String(Math.round(v)));
      const g = this.ctx; g.setLineDash([3, 4]); g.strokeStyle = 'rgba(255,255,255,0.18)';
      [30, 70].forEach((v) => { g.beginPath(); g.moveTo(0, Y(v)); g.lineTo(L.plotW, Y(v)); g.stroke(); }); g.setLineDash([]);
      g.save(); g.beginPath(); g.rect(0, p.y, L.plotW, p.h); g.clip(); this._line(s, first, last, L, Y, C.rsi, 1.5); g.restore();
    }
    _macd(L, first, last, bw) {
      const p = L.macd, m = this.calc.macd; let lo = 0, hi = 0;
      for (let i = first; i <= last; i++) [m.line[i], m.sig[i], m.hist[i]].forEach((v) => { if (v !== null) { lo = Math.min(lo, v); hi = Math.max(hi, v); } });
      const Y = this._sub(L, p, 'MACD 12 26 9', lo, hi, (v) => money(v, true)), g = this.ctx;
      g.save(); g.beginPath(); g.rect(0, p.y, L.plotW, p.h); g.clip();
      const z = Y(0);
      for (let i = first; i <= last; i++) { const v = m.hist[i]; if (v === null) continue; g.fillStyle = v >= 0 ? 'rgba(94,240,166,0.55)' : 'rgba(255,93,125,0.55)'; const y = Y(v); g.fillRect(this._x(i, L) - bw / 2, Math.min(y, z), bw, Math.max(1, Math.abs(z - y))); }
      this._line(m.line, first, last, L, Y, C.macd, 1.4); this._line(m.sig, first, last, L, Y, C.signal, 1.4); g.restore();
    }
    _tip(i, cx, y) {
      const c = this.data[i], prev = this.data[i - 1], base = prev ? prev.c : c.o, ch = c.c - base, pct = base ? ch / base * 100 : 0;
      const rows = [
        '<b>' + tlabel(c.t, this.size, true) + '</b>',
        'Mở <b>' + money(c.o) + '</b> &nbsp;Cao <b>' + money(c.h) + '</b>',
        'Thấp <b>' + money(c.l) + '</b> &nbsp;Đóng <b>' + money(c.c) + '</b>',
        '<span style="color:' + (ch >= 0 ? C.up : C.down) + '">' + (ch >= 0 ? '+' : '') + money(ch) + ' (' + (ch >= 0 ? '+' : '') + pct.toFixed(2) + '%)</span>',
      ];
      if (c.v) rows.push('Tiền bán <b>' + money(c.v) + '</b>' + (c.s ? ' · ' + c.s + ' lần' : ''));
      const ma = this.calc.ma || [];
      ma.forEach((s, k) => { if (s && s[i] !== null) rows.push('<span style="color:' + C.ma[k] + '">MA ' + this.ind.ma[k] + '</span> ' + money(s[i])); });
      (this.calc.ema || []).forEach((s, k) => { if (s && s[i] !== null) rows.push('<span style="color:' + C.ema[k] + '">EMA ' + this.ind.ema[k] + '</span> ' + money(s[i])); });
      if (this.calc.rsi && this.calc.rsi[i] !== null) rows.push('RSI ' + this.calc.rsi[i].toFixed(1));
      this.tip.innerHTML = rows.join('<br>'); this.tip.hidden = false;
      const tw = this.tip.offsetWidth, th = this.tip.offsetHeight;
      let left = cx + 14; if (left + tw > this._L.plotW) left = cx - tw - 14; if (left < 0) left = 4;
      let top = y - th / 2; top = Math.max(4, Math.min(this._L.timeY - th - 4, top));
      this.tip.style.left = left + 'px'; this.tip.style.top = top + 'px';
    }

    _bind() {
      const el = this.cv; let drag = null, pinch = null, moved = false;
      const pos = (e) => { const r = el.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
      el.addEventListener('mousemove', (e) => {
        const p = pos(e);
        if (drag) { const L = this._layout(), slot = L.plotW / this.count; const dx = p.x - drag.x; if (Math.abs(dx) > 3) moved = true; this.end = drag.end - dx / slot; this.hover = null; this._maybeMore(); this.draw(); return; }
        this.hover = p; this.draw();
      });
      el.addEventListener('mouseleave', () => { this.hover = null; drag = null; this.draw(); });
      el.addEventListener('mousedown', (e) => { drag = { x: pos(e).x, end: this.end }; moved = false; });
      window.addEventListener('mouseup', (e) => {
        if (drag && !moved && e.target === el) { const L = this._layout(), i = this._idx(pos(e).x, L); if (i >= 0 && i < this.data.length) { this.sel = i; this.draw(); this.opts.onSelect && this.opts.onSelect(this.selected()); } }
        drag = null;
      });
      el.addEventListener('dblclick', () => { this.sel = null; this.resetView(); this.opts.onSelect && this.opts.onSelect(this.selected()); });
      el.addEventListener('wheel', (e) => {
        e.preventDefault(); const L = this._layout(), p = pos(e), at = this.end - (L.plotW - p.x) / (L.plotW / this.count);
        const k = e.deltaY > 0 ? 1.12 : 1 / 1.12; const n = Math.max(15, Math.min(Math.max(30, this.data.length * 1.3), this.count * k));
        this.end = at + (this.end - at) * n / this.count; this.count = n; this._maybeMore(); this.draw();
      }, { passive: false });
      el.addEventListener('touchstart', (e) => {
        if (e.touches.length === 1) { const t = e.touches[0]; drag = { x: pos(t).x, end: this.end }; moved = false; this.hover = pos(t); this.draw(); }
        else if (e.touches.length === 2) { drag = null; pinch = { d: Math.abs(e.touches[0].clientX - e.touches[1].clientX), count: this.count }; }
      }, { passive: true });
      el.addEventListener('touchmove', (e) => {
        if (pinch && e.touches.length === 2) { const d = Math.abs(e.touches[0].clientX - e.touches[1].clientX) || 1; this.count = Math.max(15, Math.min(Math.max(30, this.data.length * 1.3), pinch.count * pinch.d / d)); this.draw(); e.preventDefault(); return; }
        if (drag && e.touches.length === 1) {
          const p = pos(e.touches[0]), L = this._layout(), dx = p.x - drag.x;
          if (Math.abs(dx) > 6) { moved = true; this.end = drag.end - dx / (L.plotW / this.count); this.hover = null; this._maybeMore(); }
          else this.hover = p;
          this.draw(); e.preventDefault();
        }
      }, { passive: false });
      el.addEventListener('touchend', () => {
        if (drag && !moved && this.hover) { const L = this._layout(), i = this._idx(this.hover.x, L); if (i >= 0 && i < this.data.length) { this.sel = i; this.opts.onSelect && this.opts.onSelect(this.selected()); } }
        drag = null; pinch = null; setTimeout(() => { this.hover = null; this.draw(); }, 1800);
      });
    }
    _maybeMore() {
      if (this.opts.onNeedMore && this.end - this.count < 5 && !this._asking) {
        this._asking = true; Promise.resolve(this.opts.onNeedMore()).finally(() => { this._asking = false; });
      }
    }
  }

  window.QQChart = QQChart;
  window.QQMoney = money;
})();
