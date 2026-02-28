(function () {
  'use strict';

  const root = document.getElementById('shopmata-chat-root');
  if (!root) return;

  const proxyUrl = root.dataset.proxyUrl || '/apps/shopmata-assistant';
  const accentColor = root.dataset.accentColor || '#1a1a2e';
  const position = root.dataset.position || 'right';
  const welcomeMessage = root.dataset.welcomeMessage || 'Hi! How can I help you today?';
  const assistantName = root.dataset.assistantName || 'Assistant';
  const shop = root.dataset.shop || '';

  document.documentElement.style.setProperty('--smc-accent', accentColor);

  function getVisitorId() {
    let id = localStorage.getItem('smc_visitor_id');
    if (!id) {
      id = crypto.randomUUID ? crypto.randomUUID() : 'v-' + Date.now() + '-' + Math.random().toString(36).slice(2);
      localStorage.setItem('smc_visitor_id', id);
    }
    return id;
  }

  function getSessionId() {
    const data = localStorage.getItem('smc_session');
    if (!data) return null;
    try {
      const parsed = JSON.parse(data);
      if (parsed.expires_at && new Date(parsed.expires_at) < new Date()) {
        localStorage.removeItem('smc_session');
        return null;
      }
      return parsed.id;
    } catch { return null; }
  }

  function setSessionId(id, expiresAt) {
    localStorage.setItem('smc_session', JSON.stringify({ id, expires_at: expiresAt }));
  }

  const visitorId = getVisitorId();
  let sessionId = getSessionId();
  let isOpen = false;
  let isStreaming = false;
  let messages = [];

  const toggleBtn = document.createElement('button');
  toggleBtn.className = 'smc-toggle smc-' + position;
  toggleBtn.setAttribute('aria-label', 'Open chat');
  toggleBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';

  const panel = document.createElement('div');
  panel.className = 'smc-panel smc-' + position;

  const header = document.createElement('div');
  header.className = 'smc-header';

  const headerTitle = document.createElement('h3');
  headerTitle.className = 'smc-header-title';
  headerTitle.textContent = assistantName;

  const closeBtn = document.createElement('button');
  closeBtn.className = 'smc-header-close';
  closeBtn.setAttribute('aria-label', 'Close chat');
  closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';

  header.appendChild(headerTitle);
  header.appendChild(closeBtn);

  const messagesEl = document.createElement('div');
  messagesEl.className = 'smc-messages';

  const welcomeEl = document.createElement('div');
  welcomeEl.className = 'smc-welcome';
  welcomeEl.textContent = welcomeMessage;
  messagesEl.appendChild(welcomeEl);

  const inputArea = document.createElement('div');
  inputArea.className = 'smc-input-area';

  const input = document.createElement('textarea');
  input.className = 'smc-input';
  input.placeholder = 'Type your message...';
  input.rows = 1;

  const sendBtn = document.createElement('button');
  sendBtn.className = 'smc-send-btn';
  sendBtn.setAttribute('aria-label', 'Send');
  sendBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';

  inputArea.appendChild(input);
  inputArea.appendChild(sendBtn);
  panel.appendChild(header);
  panel.appendChild(messagesEl);
  panel.appendChild(inputArea);
  root.appendChild(toggleBtn);
  root.appendChild(panel);

  toggleBtn.addEventListener('click', function () {
    isOpen = !isOpen;
    panel.classList.toggle('smc-open', isOpen);
    if (isOpen) { initSession(); input.focus(); }
  });

  closeBtn.addEventListener('click', function () {
    isOpen = false;
    panel.classList.remove('smc-open');
  });

  sendBtn.addEventListener('click', sendMessage);

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });

  input.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 80) + 'px';
  });

  async function initSession() {
    if (sessionId) return;
    try {
      const res = await fetch(proxyUrl + '/chat/session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visitor_id: visitorId, session_id: sessionId }),
      });
      if (!res.ok) return;
      const data = await res.json();
      sessionId = data.session_id;
      setSessionId(data.session_id, data.expires_at);
    } catch { /* retry on next message */ }
  }

  async function sendMessage() {
    const text = input.value.trim();
    if (!text || isStreaming) return;

    input.value = '';
    input.style.height = 'auto';
    if (welcomeEl.parentNode) welcomeEl.parentNode.removeChild(welcomeEl);

    addMessage('user', text);
    isStreaming = true;
    sendBtn.disabled = true;
    const typingEl = showTyping();

    try {
      const res = await fetch(proxyUrl + '/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, visitor_id: visitorId, session_id: sessionId }),
      });

      if (!res.ok) {
        removeTyping(typingEl);
        addMessage('assistant', 'Sorry, I\'m having trouble connecting. Please try again.');
        return;
      }

      removeTyping(typingEl);
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let curEl = null;
      let aText = '';

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';
        let evt = '';
        for (const line of lines) {
          if (line.startsWith('event: ')) { evt = line.slice(7).trim(); }
          else if (line.startsWith('data: ')) {
            try { handleSSE(evt, JSON.parse(line.slice(6))); } catch {}
          }
        }
      }

      function handleSSE(type, data) {
        switch (type) {
          case 'token':
            if (!curEl) { curEl = createBubble('assistant'); messagesEl.appendChild(curEl); }
            aText += data.content || '';
            curEl.innerHTML = renderMd(aText);
            scrollDown();
            break;
          case 'tool_use': showToolStatus(data.status || 'Processing...'); break;
          case 'tool_result': removeToolStatus(); break;
          case 'done': if (data.session_id) sessionId = data.session_id; break;
          case 'error':
            if (!curEl) addMessage('assistant', 'Sorry, something went wrong. Please try again.');
            break;
        }
      }
    } catch {
      removeTyping(typingEl);
      addMessage('assistant', 'Sorry, I\'m having trouble connecting. Please try again.');
    } finally {
      isStreaming = false;
      sendBtn.disabled = false;
      input.focus();
    }
  }

  function addMessage(role, text) {
    const el = createBubble(role);
    el.innerHTML = role === 'assistant' ? renderMd(text) : escHtml(text);
    messagesEl.appendChild(el);
    scrollDown();
    messages.push({ role, text });
  }

  function createBubble(role) {
    const el = document.createElement('div');
    el.className = 'smc-msg smc-msg-' + role;
    return el;
  }

  function showTyping() {
    const el = document.createElement('div');
    el.className = 'smc-typing';
    el.innerHTML = '<div class="smc-typing-dot"></div><div class="smc-typing-dot"></div><div class="smc-typing-dot"></div>';
    messagesEl.appendChild(el);
    scrollDown();
    return el;
  }

  function removeTyping(el) { if (el && el.parentNode) el.parentNode.removeChild(el); }

  let toolStatusEl = null;

  function showToolStatus(text) {
    removeToolStatus();
    toolStatusEl = document.createElement('div');
    toolStatusEl.className = 'smc-tool-status';
    toolStatusEl.textContent = text;
    messagesEl.appendChild(toolStatusEl);
    scrollDown();
  }

  function removeToolStatus() {
    if (toolStatusEl && toolStatusEl.parentNode) { toolStatusEl.parentNode.removeChild(toolStatusEl); toolStatusEl = null; }
  }

  function scrollDown() { messagesEl.scrollTop = messagesEl.scrollHeight; }

  function renderMd(text) {
    let h = escHtml(text);
    h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    h = h.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
    h = h.replace(/\n/g, '<br>');
    return h;
  }

  function escHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
  }
})();
