/**
 * lak24 AI Chatbot — Embed Script
 * 
 * Include this single script on any page to auto-inject the chat widget.
 * Usage: <script src="https://your-domain.com/lak24_bot/assets/js/embed.js"></script>
 * 
 * Configuration via data attributes:
 *   data-position="bottom-right" (default) | "bottom-left"
 *   data-theme="light" (default) | "dark"
 *   data-lang="ar" (default) | "de" | "en"
 */

(function () {
    'use strict';

    // Find this script tag to get configuration
    const currentScript = document.currentScript || (function () {
        const scripts = document.querySelectorAll('script[src*="embed.js"]');
        return scripts[scripts.length - 1];
    })();

    // Get base URL from script src
    const scriptSrc = currentScript ? currentScript.src : '';
    const baseUrl = scriptSrc.replace(/assets\/js\/embed\.js.*$/, '');

    // Read configuration from data attributes
    const config = {
        position: currentScript?.getAttribute('data-position') || 'bottom-right',
        theme: currentScript?.getAttribute('data-theme') || 'light',
        lang: currentScript?.getAttribute('data-lang') || 'ar',
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
