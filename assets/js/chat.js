/**
 * lak24 AI Chatbot — Chat Widget Controller (Final Stable Version)
 * - Fixed: External button toggle support
 * - Fixed: Automatic baseUrl detection for subdomains
 * - Improved: Asset path resolution
 */

(function () {
  'use strict';

  // ─── Default Configuration ─────────────────────────────────────
  const DEFAULT_CONFIG = {
    mode: 'web', // 'web' => chat.php, 'api' => api.php?action=...
    baseUrl: '',
    chatEndpoint: './chat.php',
    apiBaseEndpoint: './api.php',
    apiKey: '',

    maxFileSize: 10 * 1024 * 1024, // 10MB
    allowedTypes: ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

    sessionKey: 'lak24_bot_session',
    historyKey: 'lak24_bot_history',

    welcomeMessage:
      'مرحباً! 👋 أنا مساعد lak24. يمكنني مساعدتك في:\n\n🛒 البحث عن أفضل العروض من المتاجر الألمانية\n🌐 ترجمة الرسائل والمستندات من الألمانية\n✍️ كتابة الردود والإيميلات\n\nكيف يمكنني مساعدتك اليوم؟',

    suggestions: ['أريد عروض تلفزيون 📺', 'ترجم رسالة من الألمانية 🌐', 'ساعدني في كتابة إيميل ✍️'],
  };

  // Merge external config (from embed.js) if present
  const CONFIG = Object.assign({}, DEFAULT_CONFIG, (window.LAK24_BOT_CONFIG || {}));

  // ─── State ─────────────────────────────────────────────────────
  let isOpen = false;
  let isLoading = false;
  let sessionId = localStorage.getItem(CONFIG.sessionKey) || null;
  let selectedFile = null;
  let elements = {};

  // ─── Initialize ────────────────────────────────────────────────
  function init() {
    // تصحيح المسار تلقائياً ليعمل على السبدومين أو أي مجلد
    if (!CONFIG.baseUrl) {
      // نعتمد على موقع الصفحة الحالي لضمان الوصول للملفات في نفس المجلد
      CONFIG.baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
    }

    // Resolve endpoints from baseUrl
    if (CONFIG.mode === 'api') {
      CONFIG.apiBaseEndpoint = CONFIG.baseUrl + 'api.php';
    } else {
      CONFIG.chatEndpoint = CONFIG.baseUrl + 'chat.php';
    }

    injectHTML();
    bindEvents();
    loadHistory();
  }

  // ─── Inject Chat Widget HTML ───────────────────────────────────
  function injectHTML() {
    const container = document.createElement('div');
    container.id = 'lak24-chatbot';
    container.innerHTML = `
      <button class="lak24-chat-toggle" id="lak24Toggle" aria-label="فتح المحادثة">
        <svg class="icon-chat" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
        <svg class="icon-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
      </button>

      <div class="lak24-chat-window" id="lak24Window">
        <div class="lak24-drop-overlay" id="lak24DropOverlay">
          <div class="drop-icon">📄</div>
          <div class="drop-text">أفلت الملف هنا للترجمة</div>
        </div>

        <div class="lak24-chat-header">
          <div class="avatar">🤖</div>
          <div class="info">
            <div class="name">مساعد lak24</div>
            <div class="status"><span class="dot"></span> متصل الآن</div>
          </div>
          <div class="actions">
            <button id="lak24Clear" title="مسح المحادثة">🗑️</button>
            <button id="lak24Minimize" title="تصغير">✕</button>
          </div>
        </div>

        <div class="lak24-chat-messages" id="lak24Messages">
          <div class="lak24-welcome">
            <div class="welcome-icon">🤖</div>
            <div class="welcome-title">مساعد lak24</div>
            <div class="welcome-text">${escapeHtml(CONFIG.welcomeMessage)}</div>
          </div>
        </div>

        <div class="lak24-suggestions" id="lak24Suggestions">
          ${CONFIG.suggestions
        .map((s) => `<button class="chip" data-message="${escapeHtml(s)}">${escapeHtml(s)}</button>`)
        .join('')}
        </div>

        <div class="lak24-upload-preview" id="lak24UploadPreview">
          <div class="file-icon" id="lak24FileIcon">📄</div>
          <div class="file-info">
            <div class="file-name" id="lak24FileName"></div>
            <div class="file-size" id="lak24FileSize"></div>
          </div>
          <button class="remove-file" id="lak24RemoveFile">✕</button>
        </div>

        <div class="lak24-chat-input">
          <div class="input-wrapper">
            <textarea id="lak24Input" placeholder="اكتب رسالتك هنا..." rows="1"></textarea>
            <button class="btn-attach" id="lak24Attach" title="إرفاق ملف">📎</button>
          </div>
          <input type="file" id="lak24FileInput" accept=".pdf,.jpg,.jpeg,.png,.webp">
          <button class="btn-send" id="lak24Send" title="إرسال">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(container);

    elements = {
      toggle: document.getElementById('lak24Toggle'),
      window: document.getElementById('lak24Window'),
      messages: document.getElementById('lak24Messages'),
      input: document.getElementById('lak24Input'),
      send: document.getElementById('lak24Send'),
      attach: document.getElementById('lak24Attach'),
      fileInput: document.getElementById('lak24FileInput'),
      uploadPreview: document.getElementById('lak24UploadPreview'),
      fileName: document.getElementById('lak24FileName'),
      fileSize: document.getElementById('lak24FileSize'),
      fileIcon: document.getElementById('lak24FileIcon'),
      removeFile: document.getElementById('lak24RemoveFile'),
      suggestions: document.getElementById('lak24Suggestions'),
      dropOverlay: document.getElementById('lak24DropOverlay'),
      clear: document.getElementById('lak24Clear'),
      minimize: document.getElementById('lak24Minimize'),
    };
  }

  // ─── Event Binding ─────────────────────────────────────────────
  function bindEvents() {
    elements.toggle.addEventListener('click', toggleChat);
    elements.minimize.addEventListener('click', toggleChat);

    elements.send.addEventListener('click', sendMessage);
    elements.input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    elements.input.addEventListener('input', () => {
      elements.input.style.height = 'auto';
      elements.input.style.height = Math.min(elements.input.scrollHeight, 120) + 'px';
    });

    elements.attach.addEventListener('click', () => elements.fileInput.click());
    elements.fileInput.addEventListener('change', handleFileSelect);
    elements.removeFile.addEventListener('click', removeFile);

    const win = elements.window;
    win.addEventListener('dragover', (e) => {
      e.preventDefault();
      elements.dropOverlay.classList.add('visible');
    });
    win.addEventListener('dragleave', (e) => {
      if (e.target === elements.dropOverlay || e.target === win) {
        elements.dropOverlay.classList.remove('visible');
      }
    });
    win.addEventListener('drop', (e) => {
      e.preventDefault();
      elements.dropOverlay.classList.remove('visible');
      if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0]);
    });

    elements.suggestions.addEventListener('click', (e) => {
      const chip = e.target.closest('.chip');
      if (chip) {
        elements.input.value = chip.dataset.message;
        sendMessage();
      }
    });

    elements.clear.addEventListener('click', clearChat);
  }

  function toggleChat() {
    isOpen = !isOpen;
    elements.window.classList.toggle('open', isOpen);
    elements.toggle.classList.toggle('active', isOpen);

    if (isOpen) {
      elements.input.focus();
      scrollToBottom();
    }
  }

  // ─── Sending ───────────────────────────────────────────────────
  async function sendMessage() {
    if (isLoading) return;

    const text = elements.input.value.trim();
    if ((!text && !selectedFile)) return;

    // قفل مبكر لمنع الضغط المزدوج (المصدر الوحيد للقفل)
    isLoading = true;
    elements.send.disabled = true;

    elements.suggestions.style.display = 'none';

    try {
      if (selectedFile) {
        addMessage('user', text || `📄 ${selectedFile.name}`);
        elements.input.value = '';
        elements.input.style.height = 'auto';
        await uploadFile(text);
      } else {
        addMessage('user', text);
        elements.input.value = '';
        elements.input.style.height = 'auto';
        await sendTextMessage(text);
      }
    } finally {
      // إعادة الفتح هنا فقط (منع تداخل القفل بين الدوال)
      isLoading = false;
      elements.send.disabled = false;
      saveHistory();
    }
  }

  function buildChatUrl() {
    if (CONFIG.mode === 'api') return `${CONFIG.apiBaseEndpoint}?action=chat`;
    return CONFIG.chatEndpoint;
  }

  function buildClearUrl() {
    if (CONFIG.mode === 'api') return `${CONFIG.apiBaseEndpoint}?action=clear`;
    return null; // web mode: local clear only
  }

  function apiHeaders(extra = {}) {
    const h = Object.assign({}, extra);
    if (CONFIG.mode === 'api' && CONFIG.apiKey) {
      h['X-API-Key'] = CONFIG.apiKey;
    }
    return h;
  }

  async function sendTextMessage(text) {
    // (تم حذف القفل/الفتح من هنا — المصدر الوحيد في sendMessage)
    showTypingIndicator();

    try {
      const response = await fetch(buildChatUrl(), {
        method: 'POST',
        headers: apiHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          message: text,
          session_id: sessionId,
          stream: false,
        }),
      });

      const data = await safeJson(response);

      hideTypingIndicator();

      if (response.ok && data && data.reply) {
        sessionId = data.session_id || sessionId;
        if (sessionId) localStorage.setItem(CONFIG.sessionKey, sessionId);
        addMessage('bot', data.reply);
      } else {
        addMessage('bot', (data && data.error) || 'عذراً، حدث خطأ. يرجى المحاولة مرة أخرى.');
      }
    } catch (error) {
      hideTypingIndicator();
      addMessage('bot', 'عذراً، تعذر الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
      console.error('lak24 Bot Error:', error);
    }
  }

  async function uploadFile(prompt) {
    // (تم حذف القفل/الفتح من هنا — المصدر الوحيد في sendMessage)
    showTypingIndicator();

    const formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('session_id', sessionId || '');
    if (prompt) formData.append('message', prompt);

    try {
      const response = await fetch(buildChatUrl(), {
        method: 'POST',
        headers: apiHeaders(), // IMPORTANT: do NOT set Content-Type with FormData
        body: formData,
      });

      const data = await safeJson(response);

      hideTypingIndicator();

      if (response.ok && data && data.reply) {
        sessionId = data.session_id || sessionId;
        if (sessionId) localStorage.setItem(CONFIG.sessionKey, sessionId);
        addMessage('bot', data.reply);
      } else {
        addMessage('bot', (data && data.error) || 'عذراً، فشلت معالجة الملف. يرجى المحاولة مرة أخرى.');
      }
    } catch (error) {
      hideTypingIndicator();
      addMessage('bot', 'عذراً، تعذر رفع الملف. يرجى المحاولة مرة أخرى.');
      console.error('lak24 Upload Error:', error);
    }

    removeFile();
  }

  // ─── File Handling ─────────────────────────────────────────────
  function handleFileSelect(e) {
    if (e.target.files.length > 0) handleFile(e.target.files[0]);
  }

  function handleFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();

    if (!CONFIG.allowedTypes.includes(ext)) {
      showError('نوع الملف غير مدعوم. الأنواع المسموحة: ' + CONFIG.allowedTypes.join(', '));
      return;
    }

    if (file.size > CONFIG.maxFileSize) {
      showError('حجم الملف كبير جداً. الحد الأقصى: 10 ميقابايت');
      return;
    }

    selectedFile = file;

    elements.fileIcon.textContent = ext === 'pdf' ? '📄' : '🖼️';
    elements.fileName.textContent = file.name;
    elements.fileSize.textContent = formatFileSize(file.size);
    elements.uploadPreview.classList.add('visible');

    elements.input.placeholder = 'أضف تعليمات للترجمة (اختياري)...';
    elements.input.focus();
  }

  function removeFile() {
    selectedFile = null;
    elements.fileInput.value = '';
    elements.uploadPreview.classList.remove('visible');
    elements.input.placeholder = 'اكتب رسالتك هنا...';
  }

  // ─── Rendering ────────────────────────────────────────────────
  function addMessage(role, content) {
    const welcome = elements.messages.querySelector('.lak24-welcome');
    if (welcome) welcome.remove();

    const time = new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });

    const msgDiv = document.createElement('div');
    msgDiv.className = `lak24-message ${role}`;

    if (role === 'bot') {
      msgDiv.innerHTML = `
        <div class="msg-avatar">🤖</div>
        <div class="bubble">
          ${renderMarkdown(content)}
          <span class="time">${time}</span>
        </div>
      `;
    } else {
      msgDiv.innerHTML = `
        <div class="bubble">
          ${escapeHtml(content)}
          <span class="time">${time}</span>
        </div>
      `;
    }

    elements.messages.appendChild(msgDiv);
    scrollToBottom();
  }

  function renderMarkdown(text) {
    if (!text) return '';
    let html = escapeHtml(text);

    // Unescape specific HTML tags that we want to allow (like links and bold)
    html = html.replace(/&lt;a href=&quot;(.*?)&quot; target=&quot;_blank&quot;&gt;(.*?)&lt;\/a&gt;/g, '<a href="$1" target="_blank" rel="noopener">$2</a>');
    html = html.replace(/&lt;b&gt;(.*?)&lt;\/b&gt;/g, '<strong>$1</strong>');

    // Markdown parsers
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/__(.*?)__/g, '<strong>$1</strong>');
    html = html.replace(/\*(?!\s)(.*?)(?<!\s)\*/g, '<em>$1</em>');
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\n/g, '<br>');

    return html;
  }

  // ─── Typing ───────────────────────────────────────────────────
  function showTypingIndicator() {
    const existing = elements.messages.querySelector('.lak24-typing');
    if (existing) return;

    const typing = document.createElement('div');
    typing.className = 'lak24-typing';
    typing.innerHTML = `
      <div class="msg-avatar">🤖</div>
      <div class="dots">
        <div class="dot"></div>
        <div class="dot"></div>
        <div class="dot"></div>
      </div>
    `;
    elements.messages.appendChild(typing);
    scrollToBottom();
  }

  function hideTypingIndicator() {
    const typing = elements.messages.querySelector('.lak24-typing');
    if (typing) typing.remove();
  }

  function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'lak24-error';
    errorDiv.innerHTML = `⚠️ ${escapeHtml(message)}`;
    elements.messages.appendChild(errorDiv);
    scrollToBottom();
    setTimeout(() => errorDiv.remove(), 5000);
  }

  // ─── History ──────────────────────────────────────────────────
  function saveHistory() {
    const messages = elements.messages.querySelectorAll('.lak24-message');
    const history = [];

    messages.forEach((msg) => {
      const role = msg.classList.contains('user') ? 'user' : 'bot';
      const bubble = msg.querySelector('.bubble');
      if (bubble) {
        history.push({
          role,
          content: bubble.textContent.trim(),
          html: bubble.innerHTML,
        });
      }
    });

    try {
      localStorage.setItem(CONFIG.historyKey, JSON.stringify(history.slice(-50)));
    } catch (e) {
      localStorage.removeItem(CONFIG.historyKey);
    }
  }

  function loadHistory() {
    try {
      const stored = localStorage.getItem(CONFIG.historyKey);
      if (!stored) return;

      const history = JSON.parse(stored);
      if (!Array.isArray(history) || history.length === 0) return;

      const welcome = elements.messages.querySelector('.lak24-welcome');
      if (welcome) welcome.remove();

      elements.suggestions.style.display = 'none';

      history.forEach((msg) => {
        const msgDiv = document.createElement('div');
        msgDiv.className = `lak24-message ${msg.role}`;

        if (msg.role === 'bot') {
          msgDiv.innerHTML = `
            <div class="msg-avatar">🤖</div>
            <div class="bubble">${msg.html}</div>
          `;
        } else {
          msgDiv.innerHTML = `<div class="bubble">${msg.html}</div>`;
        }

        elements.messages.appendChild(msgDiv);
      });
    } catch (e) {
      localStorage.removeItem(CONFIG.historyKey);
    }
  }

  // ─── Clear Chat ───────────────────────────────────────────────
  async function clearChat() {
    if (!confirm('هل تريد مسح المحادثة؟')) return;

    // If API mode: ask server to clear (optional)
    const clearUrl = buildClearUrl();
    if (clearUrl) {
      try {
        await fetch(clearUrl, {
          method: 'POST',
          headers: apiHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ session_id: sessionId || null }),
        });
      } catch (e) {
        // ignore server clear errors; still clear locally
      }
    }

    elements.messages.innerHTML = `
      <div class="lak24-welcome">
        <div class="welcome-icon">🤖</div>
        <div class="welcome-title">مساعد lak24</div>
        <div class="welcome-text">${escapeHtml(CONFIG.welcomeMessage)}</div>
      </div>
    `;

    elements.suggestions.style.display = '';

    localStorage.removeItem(CONFIG.historyKey);
    localStorage.removeItem(CONFIG.sessionKey);
    sessionId = null;
  }

  // ─── Utilities ────────────────────────────────────────────────
  function scrollToBottom() {
    requestAnimationFrame(() => {
      elements.messages.scrollTop = elements.messages.scrollHeight;
    });
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  async function safeJson(response) {
    try {
      return await response.json();
    } catch {
      return null;
    }
  }

  // ─── Auto-Initialize ───────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // ─── Export for External Usage ──────────────────────────────
  // هذا الجزء يسمح للزر في index.php بتشغيل الدالة
  window.lak24Chat = {
    toggle: toggleChat,
    open: function () { if (!isOpen) toggleChat(); },
    close: function () { if (isOpen) toggleChat(); }
  };

})();