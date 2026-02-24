# lak24 AI Chatbot — Deployment & Hosting Guide

This guide provides step-by-step instructions on how to deploy the lak24 AI Chatbot to the live `lak24.de` server and embed it on the website.

---

## 1. Prerequisites

Before deploying, ensure the `lak24.de` web server meets the following requirements:
*   **PHP:** Version 8.1 or higher is recommended.
*   **PHP Extensions:** `curl`, `json`, `mbstring`, `pdo_sqlite`, `sqlite3`.
*   **API Keys:** You will need a valid **OpenAI API Key** with access to the `gpt-4o-mini` model.

---

## 2. Uploading the Project

1.  Take the entire `lak24_bot` project folder.
2.  Upload it to the root directory of your `lak24.de` server via FTP, SFTP, or your hosting control panel.
3.  The path on your server should typically be something like `public_html/lak24_bot/` or `www/lak24_bot/`.
4.  Ensure the folder is accessible via your domain: `https://lak24.de/lak24_bot/`

---

## 3. Setting Folder Permissions

For the chatbot to function correctly, it must be able to read and write to specific directories. You need to set the permissions (CHMOD) for the following folders inside `lak24_bot/` to be writable by the web server (typically `755` or `775`, and in some strict environments `777`):

*   `lak24_bot/sessions/` (Stores active user conversations)
*   `lak24_bot/cache/` (Stores temporary rate-limit and search data)
*   `lak24_bot/logs/` (Stores error logs)
*   `lak24_bot/uploads/` (Stores temporarily uploaded images/PDFs)
*   `lak24_bot/database/` (Stores the local SQLite product database)

*If you are using a command line (SSH), you can run:*
```bash
chmod -R 775 sessions/ cache/ logs/ uploads/ database/
```

---

## 4. Configuration (`config.php`)

Open the `lak24_bot/config.php` file on your live server to configure the essential settings:

1.  **OpenAI API Key**:
    Find the `['openai']['api_key']` line and replace the placeholder with your actual live OpenAI API key (starts with `sk-proj...`).
    ```php
    'api_key' => 'YOUR_LIVE_OPENAI_API_KEY_HERE',
    ```

2.  **API Security (Optional but Recommended)**:
    If you plan to use the mobile app API (`api.php`), change the secret string used to generate the API key to something unique to your server.
    ```php
    'api_key' => 'lak24-bot-api-' . md5('YOUR_NEW_SECRET_PHRASE_HERE'),
    ```

---

## 5. Synchronizing the Awin Affiliate Database

The chatbot uses a local, high-speed SQLite database to search for lak24 products. Before the bot goes live, you must build this database for the first time.

### First-Time Setup
If you have SSH access to your server, navigate to the `lak24_bot` folder and run the import script:
```bash
cd /path/to/your/lak24_bot/
php bin/import_awin.php
```
*Note: This script will download the latest CSV data feed and convert it into a searchable SQLite database in the `lak24_bot/database/` folder.*

### Setting up a Daily Cron Job (Crucial)
To ensure the bot always recommends active products with up-to-date prices, you must schedule the import script to run automatically every night.

Add the following line to your server's crontab (via cPanel "Cron Jobs" or SSH `crontab -e`). This example runs the sync every day at 3:00 AM:
```bash
0 3 * * * /usr/bin/php /absolute/path/to/public_html/lak24_bot/bin/import_awin.php >/dev/null 2>&1
```
*(Make sure to replace `/usr/bin/php` with the actual path to PHP on your hosting, and fix the absolute path to the `lak24_bot` folder).*

---

## 6. Embedding the Chat Widget on lak24.de

To make the chat bubble appear on the website for your customers, you only need to add one line of HTML code to your website.

1.  Open your website's main footer template file (e.g., `footer.php` or the overall footer section in your CMS/Theme).
2.  Paste the following `<script>` tag right **before** the closing `</body>` tag:

```html
<script src="/lak24_bot/assets/js/embed.js"></script>
```

That's it! The `embed.js` script will automatically load all the necessary CSS, JavaScript, and UI components to display the chat widget across your entire website seamlessly.

---

## 7. Troubleshooting & Logs

If the bot is not responding correctly after deployment:
1.  **Check Permissions**: Ensure the `logs`, `cache`, `sessions`, `uploads`, and `database` folders are writable.
2.  **Check Logs**: Open the files inside `lak24_bot/logs/` (e.g., `bot_202X-XX-XX.log`). This file contains detailed error messages.
3.  **Check OpenAI Key**: Verify your OpenAI API key has billing activated and has not exceeded its quota.
4.  **Check Database**: Ensure the file `lak24_bot/database/awin_products.db` exists and has a size greater than 0 bytes.
