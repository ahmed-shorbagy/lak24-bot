# lak24 AI Chatbot — REST API Documentation

> **Version**: 1.0  
> **Base URL**: `https://your-domain.com/lak24_bot/api.php`  
> **Authentication**: API Key via `X-API-Key` header  
> **Content-Type**: `application/json` (text endpoints) / `multipart/form-data` (file upload)

---

## Authentication

All API requests require an API key sent via the `X-API-Key` header:

```
X-API-Key: YOUR_API_KEY_HERE
```

Alternatively, use the `Authorization` header:

```
Authorization: Bearer YOUR_API_KEY_HERE
```

---

## Endpoints

### 1. Send Chat Message

**`POST /api.php?action=chat`**

Send a text message and receive the bot's response.

#### Request Body

```json
{
    "message": "أريد عروض تلفزيون بأقل من 500 يورو",
    "session_id": "optional-session-id"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message` | string | ✅ | User's message text |
| `session_id` | string | ❌ | Existing session ID for conversation continuity. If omitted, a new session is created. |

#### Success Response (200)

```json
{
    "reply": "بالطبع! سأبحث لك عن أفضل عروض التلفزيون بأقل من 500 يورو...",
    "session_id": "a1b2c3d4e5f6...",
    "type": "offer_search",
    "usage": {
        "prompt_tokens": 150,
        "completion_tokens": 200,
        "total_tokens": 350
    }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `reply` | string | Bot's response text (may contain Markdown) |
| `session_id` | string | Session ID — save and send with subsequent requests |
| `type` | string | Intent type: `offer_search`, `translation`, `writing`, or `unknown` |
| `usage` | object | Token usage statistics |

#### cURL Example

```bash
curl -X POST "https://your-domain.com/lak24_bot/api.php?action=chat" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{"message": "أريد عروض تلفزيون", "session_id": null}'
```

---

### 2. Upload File for Translation

**`POST /api.php?action=upload`**

Upload a PDF or image file for translation.

#### Request Body (multipart/form-data)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | ✅ | PDF or image file (max 10MB) |
| `session_id` | string | ❌ | Session ID |
| `prompt` | string | ❌ | Custom translation instruction (default: German → Arabic) |

#### Supported File Types

| Type | Extensions | Max Size |
|------|-----------|----------|
| PDF | `.pdf` | 10MB |
| Images | `.jpg`, `.jpeg`, `.png`, `.webp` | 10MB |

#### Success Response (200)

```json
{
    "reply": "📄 **النص الأصلي:**\n\nSehr geehrte Damen und Herren...\n\n📝 **الترجمة:**\n\nالسيدات والسادة الأعزاء...",
    "session_id": "a1b2c3d4e5f6...",
    "type": "translation",
    "filename": "brief.pdf",
    "usage": {
        "prompt_tokens": 500,
        "completion_tokens": 400,
        "total_tokens": 900
    }
}
```

#### cURL Example

```bash
curl -X POST "https://your-domain.com/lak24_bot/api.php?action=upload" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "file=@document.pdf" \
  -F "session_id=a1b2c3d4e5f6" \
  -F "prompt=ترجم هذا المستند من الألمانية إلى العربية"
```

---

### 3. Get Conversation History

**`GET /api.php?action=history&session_id=SESSION_ID`**

Retrieve the message history for a session.

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `session_id` | string | ✅ | Session ID |

#### Success Response (200)

```json
{
    "session_id": "a1b2c3d4e5f6...",
    "messages": [
        {
            "role": "user",
            "content": "أريد عروض تلفزيون",
            "timestamp": 1708600000
        },
        {
            "role": "assistant",
            "content": "بالطبع! ما هو أقصى سعر تريده؟",
            "timestamp": 1708600005
        }
    ],
    "count": 2
}
```

---

### 4. Clear Conversation

**`POST /api.php?action=clear`**

Clear the message history for a session.

#### Request Body

```json
{
    "session_id": "a1b2c3d4e5f6..."
}
```

#### Success Response (200)

```json
{
    "success": true,
    "session_id": "a1b2c3d4e5f6...",
    "message": "تم مسح المحادثة بنجاح."
}
```

---

### 5. Get Welcome Info

**`GET /api.php?action=welcome`**

Get the bot's welcome message and capabilities (useful for mobile app initialization).

#### Success Response (200)

```json
{
    "bot_name": "مساعد lak24",
    "welcome_message": "مرحباً! 👋 أنا مساعد lak24...",
    "capabilities": {
        "offer_search": true,
        "translation": true,
        "writing": true,
        "file_upload": true
    },
    "allowed_files": ["pdf", "jpg", "jpeg", "png", "webp"],
    "max_file_size": 10485760
}
```

---

## Error Responses

All errors return a JSON object with an `error` field:

| HTTP Code | Meaning | Example |
|-----------|---------|---------|
| 400 | Bad Request | `{"error": "Message is required"}` |
| 401 | Unauthorized | `{"error": "Invalid or missing API key"}` |
| 405 | Method Not Allowed | `{"error": "POST required"}` |
| 429 | Rate Limited | `{"error": "Rate limit exceeded", "reset_at": 1708600060}` |
| 500 | Server Error | `{"error": "حدث خطأ أثناء المعالجة"}` |
| 503 | Service Unavailable | `{"error": "API is currently disabled"}` |

---

## Rate Limiting

- **30 requests per minute** per IP address
- When rate limited, response includes `reset_at` timestamp
- Header: `X-RateLimit-Remaining` (remaining requests in window)

---

## Typical Mobile App Flow

```
1. App starts → GET /api.php?action=welcome
   → Display welcome message, save capabilities

2. User sends message → POST /api.php?action=chat
   → Send { message, session_id }
   → Save session_id from response
   → Display reply

3. User uploads file → POST /api.php?action=upload
   → Send file + session_id via multipart/form-data
   → Display translated text

4. User clears chat → POST /api.php?action=clear
   → Send { session_id }
   → Reset UI
```

---

## Notes

- All text responses may contain **Markdown formatting** (bold, links, lists)
- The bot responds primarily in **Arabic** but supports German and English
- Session expires after **30 minutes** of inactivity
- The bot will **politely refuse** any request outside its 3 functions (offers, translation, writing)
