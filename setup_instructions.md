# Chatbot Hosting & Maintenance Instructions

Dear Server Administrator / Hosting Client,

This document contains step-by-step instructions for hosting and maintaining the Lak24 Affiliate Chatbot. Because this bot relies on huge databases of affiliate products (Awin) and strictly regulated third-party APIs (Amazon), **you MUST complete the following steps** for the bot to function correctly and generate revenue.

---

## 1. Setup the Daily Awin Cron Job (CRITICAL)

The bot searches for product deals in a local database (`awin_products.db`) constructed from an Awin CSV feed. Prices change rapidly, and products go out of stock. If this database is not refreshed daily, the bot will give users broken links or incorrect prices.

**Your Action:**
Create a daily Cron Job on your server to run the Awin import script. 
1. Create a simple PHP script on your server (e.g., `update_awin.php`) with the following content:
   ```php
   <?php
   require_once __DIR__ . '/classes/AwinDatabase.php';
   $awin = new AwinDatabase();
   $awin->importFeed();
   echo "Awin feed updated successfully.";
   ```
2. Open your hosting control panel (cPanel, Plesk, or SSH).
3. Navigate to **Cron Jobs**.
4. Add a new Cron Job to run **once a day** (e.g., at 3:00 AM server time).
5. Set the command to execute your script. (Example: `php /path/to/your/htdocs/Chatbot/update_awin.php`).

---

## 2. Fix Amazon PA-API Access (Error 403 Forbidden)

Currently, the Amazon Product Advertising API (PA-API) keys configured in `config.php` are being rejected by Amazon with a `403 Forbidden` error. **The bot cannot show any Amazon products until this is resolved on Amazon's side.**

Amazon strictly limits API access. To use the PA-API, your Amazon Associates account must meet specific criteria.

**Your Action:**
1. Log in to your [Amazon Associates Central](https://partnernet.amazon.de/) account.
2. Verify that your account is fully approved (not pending).
3. Ensure you have driven at least **3 qualifying sales within the last 180 days** via direct affiliate links (not the API). Amazon will revoke API access if your account does not maintain this minimum sales volume.
4. If your keys were revoked or are totally brand new, generate a **new Access Key and Secret Key** from the Associates portal (Tools > Product Advertising API).
5. Open `config.php` in the Chatbot folder and replace the old keys under the `['affiliate']['amazon']` section.
6. **Note:** Newly generated keys can take up to 48 hours to activate and start returning results.

---

## 3. Ensure Optimal Server Specifications

This chatbot utilizes a local SQLite database with Full-Text Search (FTS5) capabilities to search through hundreds of thousands of Awin products in milliseconds. 

**Your Action:**
1. Ensure your hosting environment operates on **SSD (Solid State Drive)** storage. Searching large SQLite databases on slow mechanical hard drives will cause the chat to freeze or lag, resulting in API timeouts with OpenAI.
2. Ensure you have sufficient **RAM (Memory)**. We recommend a VPS or a high-tier shared hosting plan.
3. Monitor the `$awin_filePATH` size configured in `config.php` (it is an uncompressed CSV). Ensure you have enough disk space to accommodate this file growing over time as more products are added to the feed.

---

## 4. Secure API Keys

All sensitive information is stored in `config.php`.

**Your Action:**
1. Never commit `config.php` to a public repository (like GitHub).
2. Ensure the file permissions for `config.php` and the `classes` folder restrict read access from the public web.
3. Keep your OpenAI API Key secure. Do not share it. The current OpenAI key dictates the billing for every chat request. If requests fail with `500 Server Error`, the first step is to verify the OpenAI API key is still active and has sufficient billing credits.
