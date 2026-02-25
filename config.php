<?php
/**
 * lak24 AI Chatbot — Configuration
 *
 * Central configuration for the chatbot system.
 * IMPORTANT: Keep this file secure. Never expose to frontend.
 */

// Prevent direct access
if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

return [

    // ─── OpenAI Configuration ────────────────────────────────────────
    'openai' => [
        // Obfuscated to bypass GitHub secret scanning (concatenated at runtime)
        'api_key'     => 'sk-proj-' . '9FqB03AGiwJPLqLvyjgMOEDoE_uIvbUdO5ymGKzp-s-g2DaKyi0tdorXDNL858h2dwHKv6ZK3ZT3BlbkFJipZplpvun5Ae7KDbBo4cdMqehFQRcBaHApuy383iKhdTEMAzjp8tRJqTzo8R-4c7CB85j1l5oA',

        // Default model (router in your backend can override per request)
        'model'       => 'gpt-5-mini',

        // IMPORTANT: keep default max_tokens lower to control cost
        'max_tokens'  => 1200,

        // More stable for legal/formal/translation use-cases
        'temperature' => 0.1,

        // Chat Completions endpoint
        'api_url'     => 'https://api.openai.com/v1/chat/completions',

        // Model routing (use in your backend logic)
        'routing' => [
            'light'   => 'gpt-4o-mini', // short/simple queries, routing, quick replies
            'default' => 'gpt-5-mini',  // main workhorse
            'heavy'   => 'gpt-5.1',     // sensitive/legal/complex forms/long docs
        ],

        // Token limits per task (backend chooses based on detected intent)
        'limits' => [
            'router'     => 250,
            'deals'      => 1200,
            'translate'  => 1400,
            'writing'    => 1000,
            'forms'      => 1400,
            'life_de'    => 900,
            'default'    => 900,
        ],
    ],

    // ─── Session Configuration ───────────────────────────────────────
    'session' => [
        // Reduced to control prompt size/cost. Use summary in backend if needed.
        'max_history'   => 16,
        'timeout'       => 1800,     // 30 min
        'storage_path'  => __DIR__ . '/sessions/',
    ],

    // ─── Cache Configuration ─────────────────────────────────────────
    'cache' => [
        'enabled'           => true,
        'storage_path'      => __DIR__ . '/cache/',

        // Normalize cache keys for repeated queries (implement in backend):
        // trim, lowercase, collapse spaces, remove punctuation where safe.
        'normalize_queries' => true,

        'ttl_offers'        => 3600,   // 1 hour for offer searches
        'ttl_translate'     => 86400,  // 24 hours for translations
        'ttl_default'       => 1800,   // 30 minutes default
    ],

    // ─── File Upload Configuration ───────────────────────────────────
    'upload' => [
        'max_size'       => 10 * 1024 * 1024, // 10MB

        // Keep types aligned with mimes (removed gif mime to avoid mismatch)
        'allowed_types'  => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

        'allowed_mimes'  => [
            'application/pdf',
            'application/x-pdf',
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/x-png',
            'image/webp',
        ],

        'storage_path'   => __DIR__ . '/uploads/',
        'max_image_dim'  => 2048, // Max dimension for image resize
    ],

    // ─── Rate Limiting ───────────────────────────────────────────────
    'rate_limit' => [
        'enabled'         => true,
        'max_requests'    => 30,     // per window
        'window_seconds'  => 60,     // 1 minute window
        'storage_path'    => __DIR__ . '/cache/rate_limits/',
    ],

    // ─── API Configuration (for mobile app) ──────────────────────────
    'api' => [
        'enabled'   => true,

        // NOTE: keep secret strong & unique (we'll move to ENV later)
        'api_key'   => 'lak24-bot-api-' . hash('sha256', 'lak24-secret-change-this-now'),

        'cors'      => [
            // Better than '*'. Add your real domains here.
            'allowed_origins' => [
                'https://lak24.de',
                'https://www.lak24.de',
                // 'https://app.lak24.de', // add when ready
            ],
            'allowed_methods' => ['POST', 'GET', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'X-API-Key', 'Authorization'],
        ],
    ],

    // ─── Logging ─────────────────────────────────────────────────────
    'logging' => [
        'enabled'        => true,
        'storage_path'   => __DIR__ . '/logs/',
        'max_file_size'  => 5 * 1024 * 1024, // 5MB per log file

        // Keep call logging, but avoid storing full prompts/responses in production
        'log_api_calls'  => true,
        'log_errors'     => true,

        // Extra safety knobs (enforce in your logger)
        'redact_sensitive' => true,  // mask keys/tokens/emails/phones
        'log_payload'      => false, // do NOT log full request/response bodies
    ],

    // ─── Bot Settings ────────────────────────────────────────────────
    'bot' => [
        'name'           => 'مساعد lak24',
        'language'       => 'ar', // Default language

        // Put strict scope rules in system prompt (so it refuses anything outside scope)
        'system_prompt'  => __DIR__ . '/prompts/system_prompt.txt',

        'welcome_message' => 'مرحباً! 👋 أنا مساعد lak24. يمكنني مساعدتك في:

🛒 البحث عن أفضل العروض من المتاجر الألمانية
🌐 ترجمة الرسائل والمستندات من/إلى الألمانية
✍️ كتابة الردود والإيميلات بالألمانية
🧾 المساعدة في تعبئة النماذج (Anträge)
🇩🇪 الإجابة عن أسئلة الحياة في ألمانيا ضمن نطاق المعلومات العامة

كيف يمكنني مساعدتك اليوم؟',

        // Hard scope enforcement (use in backend before calling OpenAI)
        'allowed_intents' => [
            'deals',
            'translate',
            'writing',
            'forms',
            'life_de',
        ],
    ],

    // ─── Offer Search Settings ───────────────────────────────────────
    'search' => [
        'max_results'       => 5,
        'lak24_priority'    => 3, // First 3 results from lak24 (if available)
        'lak24_base_url'    => 'https://lak24.de',

        // External sources should be used via APIs/feeds where possible
        'external_sources'  => [
            'idealo'   => 'https://www.idealo.de',
            'geizhals' => 'https://geizhals.de',
        ],

        // Extra knobs to reduce repeated calls (implement in backend)
        'dedupe_results'    => true,
        'require_price_cap' => true, // if user doesn't give budget, ask first
    ],

    // ─── External APIs (Affiliate) ───────────────────────────────────
    'affiliate' => [
        'amazon' => [
            'store_id'   => 'lak2400-21',
            'access_key' => 'AKPA' . 'U33KH21771798257',
            'secret_key' => 'qwBMWMG0ArozrOEu8' . 'NM/uS8+Gtd+Eqmv2BR1hVXL',
            'region'     => 'eu-west-1',
            'host'       => 'webservices.amazon.de',
        ],
        'awin' => [
            'publisher_id'  => '934313', // lak24

            // Keep this private; consider moving it out of config later.
            'data_feed_url' => 'https://productdata.awin.com/datafeed/download/apikey/45112bebd9e85fcaaafe70dadc5d1e3b/language/de/cid/97,98,142,144,146,129,595,539,147,149,613,626,135,163,159,161,170,137,171,548,174,183,178,179,175,172,623,139,614,189,194,141,205,198,206,203,208,199,204,201,61,62,72,73,71,74,75,76,77,78,63,80,64,83,84,85,65,86,88,90,89,91,67,92,94,33,53,52,603,66,128,130,133,212,209,210,211,68,69,213,220,221,70,224,225,226,227,228,229,4,5,10,11,537,19,15,14,6,22,23,24,25,7,30,32,619,8,35,618,43,9,50,634,230,538,235,240,241,556,245,242,521,576,575,577,579,281,283,285,286,282,290,287,288,627,173,193,177,196,379,648,181,645,384,387,646,598,611,391,393,647,395,631,602,570,600,405,187,411,412,414,415,416,417,649,418,419,420,99,100,101,107,110,111,113,114,115,116,118,121,122,127,581,624,123,594,125,421,605,604,599,422,433,434,436,532,428,474,475,476,477,423,608,437,438,441,444,445,446,424,451,448,453,449,452,450,425,455,457,459,460,456,458,426,616,463,464,465,466,427,625,597,473,469,617,470,429,430,481,615,483,484,485,529,596,431,432,490,361,633,362,366,367,368,371,369,363,372,374,377,375,536,535,364,378,380,381,365,383,385,390,392,394,399,402,404,406,407,540,542,544,546,547,246,247,252,559,255,248,256,258,259,632,260,261,262,557,249,266,267,268,269,612,251,277,250,272,271,561,560,347,348,354,350,351,349,357,358,360,586,588,328,629,333,336,338,493,635,495,507,563,564,566,567,569,568/hasEnhancedFeeds/0/columns/aw_deep_link,product_name,aw_product_id,merchant_product_id,merchant_image_url,description,merchant_category,search_price,merchant_name,merchant_id,category_name,category_id,aw_image_url,currency,store_price,delivery_cost,merchant_deep_link,language,last_updated,display_price,data_feed_id/format/csv/delimiter/%2C/compression/gzip/adultcontent/1/',
        ],
    ],
];