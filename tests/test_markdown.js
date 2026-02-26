
function escapeHtml(text) {
    return text.replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function renderMarkdown(text) {
    if (!text) return '';

    let html = escapeHtml(text);

    // Bold: **text** or __text__
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/__(.*?)__/g, '<strong>$1</strong>');

    // Italic: *text* or _text_
    // This is the suspect regex from chat.js
    try {
        html = html.replace(/\*(?!\s)(.*?)(?<!\s)\*/g, '<em>$1</em>');
    } catch (e) {
        return "REGEX_CRASH: " + e.message;
    }

    // Links: [text](url)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    // Inline code: `code`
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Line breaks
    html = html.replace(/\n/g, '<br>');

    return html;
}

// Mock long string (similar to 1800 tokens)
const longText = "This is a test message. " + "*word* ".repeat(2000);
console.time('renderMarkdown');
const result = renderMarkdown(longText);
console.timeEnd('renderMarkdown');
console.log("Result length:", result.length);
if (result.startsWith("REGEX_CRASH")) {
    console.log(result);
}
