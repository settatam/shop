(function () {
  'use strict';
  var r = document.getElementById('shopmata-chat-root');
  if (!r) return;
  var ce = function (t) { return document.createElement(t); };
  var pu = r.dataset.proxyUrl || '/apps/shopmata-assistant';
  var ac = r.dataset.accentColor || '#1a1a2e';
  var pos = r.dataset.position || 'right';
  var dm = r.dataset.displayMode || 'popup';
  var ao = r.dataset.autoOpen === 'true';
  var sq = r.dataset.suggestedQuestions || '';
  var wm = r.dataset.welcomeMessage || 'Hi! How can I help you today?';
  var an = r.dataset.assistantName || 'Assistant';
  var sh = r.dataset.shop || '';
  document.documentElement.style.setProperty('--smc-accent', ac);
  if (dm === 'panel') r.classList.add('smc-mode-panel');

  function gvid() {
    var id = localStorage.getItem('smc_visitor_id');
    if (!id) {
      id = crypto.randomUUID ? crypto.randomUUID() : 'v-' + Date.now() + '-' + Math.random().toString(36).slice(2);
      localStorage.setItem('smc_visitor_id', id);
    }
    return id;
  }
  function gsid() {
    var d = localStorage.getItem('smc_session');
    if (!d) return null;
    try {
      var p = JSON.parse(d);
      if (p.expires_at && new Date(p.expires_at) < new Date()) { localStorage.removeItem('smc_session'); return null; }
      return p.id;
    } catch { return null; }
  }
  function ssid(id, ex) { localStorage.setItem('smc_session', JSON.stringify({ id: id, expires_at: ex })); }

  var vid = gvid(), sid = gsid(), io = false, str = false, msgs = [];

  var tb = ce('button');
  tb.className = 'smc-toggle smc-' + pos;
  tb.setAttribute('aria-label', 'Open chat');
  tb.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';

  var pn = ce('div');
  pn.className = 'smc-panel smc-' + pos;
  var hd = ce('div');
  hd.className = 'smc-header';
  var ht = ce('h3');
  ht.className = 'smc-header-title';
  ht.textContent = an;
  var cb = ce('button');
  cb.className = 'smc-header-close';
  cb.setAttribute('aria-label', 'Close chat');
  cb.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';
  hd.appendChild(ht);
  hd.appendChild(cb);

  var me = ce('div');
  me.className = 'smc-messages';
  var we = ce('div');
  we.className = 'smc-welcome';
  we.textContent = wm;
  me.appendChild(we);

  var sg = null;
  if (sq) {
    sg = ce('div'); sg.className = 'smc-suggestions';
    sq.split(',').forEach(function (q) {
      q = q.trim(); if (!q) return;
      var c = ce('button'); c.className = 'smc-chip'; c.textContent = q;
      c.addEventListener('click', function () { inp.value = q; send(); rmEl(sg); sg = null; });
      sg.appendChild(c);
    });
  }

  var ia = ce('div');
  ia.className = 'smc-input-area';
  var inp = ce('textarea');
  inp.className = 'smc-input';
  inp.placeholder = 'Type your message...';
  inp.rows = 1;

  var mb = ce('button');
  mb.className = 'smc-mic-btn';
  mb.setAttribute('aria-label', 'Voice mode');
  mb.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1-9c0-.55.45-1 1-1s1 .45 1 1v6c0 .55-.45 1-1 1s-1-.45-1-1V5zm6 6c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>';

  var sb = ce('button');
  sb.className = 'smc-send-btn';
  sb.setAttribute('aria-label', 'Send');
  sb.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

  ia.appendChild(inp);
  ia.appendChild(mb);
  ia.appendChild(sb);
  pn.appendChild(hd);
  if (sg) pn.appendChild(sg);
  pn.appendChild(me);
  pn.appendChild(ia);

  var bd = null;
  if (dm === 'panel') {
    bd = ce('div'); bd.className = 'smc-backdrop';
    r.appendChild(bd);
    bd.addEventListener('click', closePanel);
  }
  r.appendChild(pn);
  r.appendChild(tb);

  function openPanel() {
    io = true; pn.classList.add('smc-open');
    if (bd) bd.classList.add('smc-visible');
    initSess(); inp.focus();
  }
  function closePanel() {
    io = false; pn.classList.remove('smc-open');
    if (bd) bd.classList.remove('smc-visible');
  }

  tb.addEventListener('click', function () { if (io) closePanel(); else openPanel(); });
  cb.addEventListener('click', closePanel);

  var va = false, vm = null;
  mb.addEventListener('click', async function () {
    if (va) { stopVc(); return; }
    try {
      if (!vm) {
        var s = ce('script');
        s.src = r.dataset.voiceJsUrl || (pu.replace(/\/[^/]*$/, '') + '/shopmata-voice.js');
        await new Promise(function (ok, no) { s.onload = ok; s.onerror = no; document.head.appendChild(s); });
        vm = window.ShopmataChatVoice;
      }
      if (!vm) return;
      va = true;
      mb.classList.add('smc-mic-active');
      rmEl(we);
      vm.start({
        gatewayUrl: r.dataset.voiceGatewayUrl || 'https://voice.shopmata.com',
        shop: sh, visitorId: vid, sessionId: sid,
        onTranscript: function (t) { addMsg('user', t); },
        onResponse: function (t) { addMsg('assistant', t); },
        onAddToCart: function (d) {
          fetch('/cart/add.js', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: [{ id: parseInt(d.variant_id), quantity: d.quantity || 1 }] }),
          }).catch(function () {});
        },
        onEnd: function () { stopVc(); },
      });
    } catch (e) { console.error('[smc] Voice error:', e); stopVc(); }
  });
  function stopVc() { va = false; mb.classList.remove('smc-mic-active'); if (vm) vm.stop(); }

  sb.addEventListener('click', send);
  inp.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
  inp.addEventListener('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 80) + 'px'; });

  async function initSess() {
    if (sid) return;
    try {
      var res = await fetch(pu + '/chat/session', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visitor_id: vid, session_id: sid }),
      });
      if (!res.ok) return;
      var d = await res.json();
      sid = d.session_id;
      ssid(d.session_id, d.expires_at);
    } catch {}
  }

  async function send() {
    var txt = inp.value.trim();
    if (!txt || str) return;
    inp.value = '';
    inp.style.height = 'auto';
    rmEl(we);
    if (sg) { rmEl(sg); sg = null; }
    addMsg('user', txt);
    str = true;
    sb.disabled = true;
    var typ = showTyp();
    try {
      var res = await fetch(pu + '/chat', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: txt, visitor_id: vid, session_id: sid }),
      });
      if (!res.ok) { rmEl(typ); addMsg('assistant', 'Sorry, I\'m having trouble connecting. Please try again.'); return; }
      rmEl(typ);
      var rd = res.body.getReader(), dec = new TextDecoder(), buf = '', cur = null, at = '', evt = '';
      while (true) {
        var ch = await rd.read();
        if (ch.done) break;
        buf += dec.decode(ch.value, { stream: true });
        var ln = buf.split('\n');
        buf = ln.pop() || '';
        for (var i = 0; i < ln.length; i++) {
          if (ln[i].startsWith('event: ')) { evt = ln[i].slice(7).trim(); }
          else if (ln[i].startsWith('data: ')) {
            try {
              var d = JSON.parse(ln[i].slice(6));
              if (evt === 'token') {
                if (!cur) { cur = mkBub('assistant'); me.appendChild(cur); }
                at += d.content || '';
                cur.innerHTML = rMd(at);
                scr();
              } else if (evt === 'tool_use') { showTool(d.status || 'Processing...'); }
              else if (evt === 'tool_result') { rmTool(); }
              else if (evt === 'done') { if (d.session_id) sid = d.session_id; }
              else if (evt === 'error' && !cur) { addMsg('assistant', 'Sorry, something went wrong. Please try again.'); }
            } catch {}
          }
        }
      }
    } catch { rmEl(typ); addMsg('assistant', 'Sorry, I\'m having trouble connecting. Please try again.'); }
    finally { str = false; sb.disabled = false; inp.focus(); }
  }

  function addMsg(rl, txt) {
    var el = mkBub(rl);
    el.innerHTML = rl === 'assistant' ? rMd(txt) : escH(txt);
    me.appendChild(el);
    scr();
    msgs.push({ role: rl, text: txt });
  }
  function mkBub(rl) { var el = ce('div'); el.className = 'smc-msg smc-msg-' + rl; return el; }
  function showTyp() {
    var el = ce('div');
    el.className = 'smc-typing';
    el.innerHTML = '<div class="smc-typing-dot"></div><div class="smc-typing-dot"></div><div class="smc-typing-dot"></div>';
    me.appendChild(el);
    scr();
    return el;
  }
  function rmEl(el) { if (el && el.parentNode) el.parentNode.removeChild(el); }

  var tse = null;
  function showTool(t) { rmTool(); tse = ce('div'); tse.className = 'smc-tool-status'; tse.textContent = t; me.appendChild(tse); scr(); }
  function rmTool() { if (tse && tse.parentNode) { tse.parentNode.removeChild(tse); tse = null; } }
  function scr() { me.scrollTop = me.scrollHeight; }
  function rMd(t) {
    var h = escH(t);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    h = h.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
    h = h.replace(/\n/g, '<br>');
    return h;
  }
  function escH(t) { var d = ce('div'); d.textContent = t; return d.innerHTML; }

  if (ao && !sessionStorage.getItem('smc_ao')) {
    sessionStorage.setItem('smc_ao', '1');
    openPanel();
  }
})();
