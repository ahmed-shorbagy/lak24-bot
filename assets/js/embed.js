/**
 * lak24 AI Chatbot — Embed Script (Improved)
 *
 * Usage:
 * <script src="https://your-domain.com/lak24_bot/assets/js/embed.js"
 *         data-position="bottom-right"
 *         data-mode="web"></script>
 *
 * API mode:
 * <script src=".../embed.js"
 *         data-mode="api"
 *         data-api-key="lak24-bot-api-xxxx"></script>
 */

(function () {
  'use strict';

  const currentScript =
    document.currentScript ||
    (function () {
      const scripts = document.querySelectorAll('script[src*="embed.js"]');
      return scripts[scripts.length - 1];
    })();

  const scriptSrc = currentScript ? currentScript.src : '';
  const baseUrl = scriptSrc.replace(/assets\/js\/embed\.js.*$/, '');

  const config = {
    position: (currentScript && currentScript.getAttribute('data-position')) || 'bottom-right',
    mode: (currentScript && currentScript.getAttribute('data-mode')) || 'web', // web | api
    apiKey: (currentScript && currentScript.getAttribute('data-api-key')) || '',
  };

  // Expose config to chat.js
  window.LAK24_BOT_CONFIG = {
    baseUrl: baseUrl,
    mode: config.mode === 'api' ? 'api' : 'web',
    apiKey: config.apiKey || '',
  };

  // Load CSS
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = baseUrl + 'assets/css/chat.css';
  document.head.appendChild(link);

  // Apply position override
  if (config.position === 'bottom-left') {
    const style = document.createElement('style');
    style.textContent = `
      .lak24-chat-toggle { left: 24px; right: auto; }
      .lak24-chat-window { left: 24px; right: auto; }
    `;
    document.head.appendChild(style);
  }

  // Load main chat script
  const script = document.createElement('script');
  script.src = baseUrl + 'assets/js/chat.js';
  script.defer = true;
  document.head.appendChild(script);
})();