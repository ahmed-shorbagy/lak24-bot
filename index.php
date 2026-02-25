<?php
/**
 * مساعد lak24 — الصفحة الرئيسية للبوت
 * هذا الملف معدل ليعمل على Subdomain أو مجلد رئيسي
 */

// 1. تفعيل إظهار الأخطاء (قم بتعطيلها بتحويل 1 إلى 0 بعد التأكد من عمل البوت)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('LAK24_BOT', true);

// 2. ربط الملفات باستخدام __DIR__ لضمان عمل المسارات تلقائياً
require_once __DIR__ . '/classes/Logger.php';

$logger = new Logger(['enabled' => true, 'log_api_calls' => true, 'log_errors' => true]);
$logger->info('Bot Access on: ' . $_SERVER['HTTP_HOST']);

?>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff; /* الخلفية بيضاء دائماً كما طلبت */
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            direction: rtl;
            color: #333;
        }
        .landing {
            text-align: center;
            padding: 40px 20px;
            max-width: 600px;
        }
        .logo { font-size: 80px; margin-bottom: 20px; }
        h1 { color: #2c3e50; margin-bottom: 15px; font-size: 2.5rem; }
        p { color: #7f8c8d; line-height: 1.6; margin-bottom: 30px; font-size: 1.1rem; }
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 40px;
        }
        .feature-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #eee;
        }
        .icon { font-size: 30px; margin-bottom: 10px; }
        .label { font-size: 14px; font-weight: bold; color: #34495e; }
        .cta {
            background: #007bff;
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 18px;
            border-radius: 30px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .cta:hover { transform: scale(1.05); background: #0056b3; }
        
        /* تعليمات الربط للمواقع الأخرى */
        .embed-info {
            margin-top: 50px;
            padding: 20px;
            background: #f1f2f6;
            border-radius: 10px;
            text-align: left;
            direction: ltr;
        }
        .embed-info h3 { margin-bottom: 10px; font-size: 16px; color: #2f3542; text-align: right; }
        code {
            display: block;
            background: #2f3542;
            color: #f1f2f6;
            padding: 15px;
            border-radius: 5px;
            font-size: 13px;
            word-break: break-all;
        }
    </style>
</head>
<body>

    <div class="landing">
        <div class="logo">🤖</div>
        <h1>مساعد lak24</h1>
        <p>مرحباً بك في النسخة المطورة من مساعدك الذكي.<br>يمكنني مساعدتك في البحث عن أفضل العروض، ترجمة المستندات، وصياغة الرسائل الرسمية بالألمانية.</p>

        <div class="features">
            <div class="feature-card">
                <div class="icon">🛒</div>
                <div class="label">أفضل العروض</div>
            </div>
            <div class="feature-card">
                <div class="icon">📄</div>
                <div class="label">ترجمة ملفات</div>
            </div>
            <div class="feature-card">
                <div class="icon">✍️</div>
                <div class="label">صياغة عقود</div>
            </div>
        </div>
<button class="cta" id="startChatBtn">
            ابدأ المحادثة الآن 💬
        </button>
    </div> <script>
        document.getElementById('startChatBtn').addEventListener('click', function() {
            const internalToggle = document.getElementById('lak24Toggle');
            if (internalToggle) {
                internalToggle.click();
            } else if (window.lak24Chat) {
                window.lak24Chat.toggle();
            } else {
                alert('جاري تحميل المحادثة.. يرجى الانتظار ثانية');
            }
        });
    </script>

    <script src="assets/js/chat.js"></script>
    <script src="assets/js/embed.js"></script>
</body>
</html>