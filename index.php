<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="مساعد lak24 — بوت الدردشة الذكي للبحث عن العروض والترجمة">
    <title>مساعد lak24 — بوت الدردشة الذكي</title>
    <link rel="stylesheet" href="assets/css/chat.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'IBM Plex Sans Arabic', 'Segoe UI', Tahoma, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            direction: rtl;
        }

        .landing {
            text-align: center;
            color: white;
            padding: 40px 20px;
            max-width: 600px;
        }

        .landing .logo {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .landing h1 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 12px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .landing p {
            font-size: 18px;
            opacity: 0.9;
            line-height: 1.8;
            margin-bottom: 32px;
        }

        .features {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 40px;
        }

        .feature-card {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 16px;
            padding: 24px 20px;
            width: 160px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-4px);
        }

        .feature-card .icon {
            font-size: 36px;
            margin-bottom: 10px;
        }

        .feature-card .label {
            font-size: 14px;
            font-weight: 500;
        }

        .cta {
            display: inline-block;
            padding: 14px 36px;
            background: white;
            color: #667eea;
            border-radius: 28px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }

        .cta:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }

        .embed-info {
            margin-top: 48px;
            background: rgba(0,0,0,0.2);
            border-radius: 12px;
            padding: 20px;
            text-align: left;
            direction: ltr;
        }

        .embed-info h3 {
            font-size: 14px;
            margin-bottom: 8px;
            opacity: 0.8;
        }

        .embed-info code {
            display: block;
            background: rgba(0,0,0,0.3);
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            word-break: break-all;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="landing">
        <div class="logo">🤖</div>
        <h1>مساعد lak24</h1>
        <p>بوت الدردشة الذكي المدعوم بالذكاء الاصطناعي<br>يساعدك في البحث عن العروض والترجمة وكتابة الرسائل</p>

        <div class="features">
            <div class="feature-card">
                <div class="icon">🛒</div>
                <div class="label">البحث عن العروض</div>
            </div>
            <div class="feature-card">
                <div class="icon">🌐</div>
                <div class="label">ترجمة المستندات</div>
            </div>
            <div class="feature-card">
                <div class="icon">✍️</div>
                <div class="label">كتابة الإيميلات</div>
            </div>
        </div>

        <button class="cta" onclick="document.getElementById('lak24Toggle').click()">
            ابدأ المحادثة 💬
        </button>

        <div class="embed-info">
            <h3>📦 Embed this widget on any page:</h3>
            <code>&lt;script src="https://your-domain.com/lak24_bot/assets/js/embed.js"&gt;&lt;/script&gt;</code>
        </div>
    </div>

    <!-- Chat Widget -->
    <script src="assets/js/chat.js"></script>
</body>
</html>
