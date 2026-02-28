# Beginner's Guide: Deploying the lak24 Bot to IONOS

This guide is designed for anyone with zero technical experience. Follow these steps exactly to get your chatbot running on `chat.lak24.de`.

---

## Phase 1: Prepare your files for upload

On your local computer (where you are currently working), we need to "package" the bot.

1.  **Open your project folder** (the one named `Chatbot`).
2.  **Select all files and folders EXCEPT** these two (they are too large and not needed):
    - `node_modules/`
    - `.git/`
3.  **Right-click** the selected items and choose **Compress to ZIP file**.
4.  Name the final file something simple, like `bot-upload.zip`.

---

## Phase 2: Upload to IONOS

1.  Log in to your **IONOS Control Panel**.
2.  Click on the **Hosting** box.
3.  Inside the Hosting menu, find and click **File Manager**.
4.  Navigate to the folder linked to your subdomain `chat.lak24.de` (Based on your screenshot, this is the `/Bot` folder).
5.  Click the **Upload** button at the top and select your `bot-upload.zip` file.
6.  Once uploaded, right-click the file and select **Extract** (or Unzip).

---

## Phase 3: Set Permissions (Crucial Step)

The bot needs "permission" to save data on the server. If you skip this, it will crash.

1.  In the IONOS File Manager, look for these specific folders:
    - `data/`
    - `sessions/`
    - `cache/`
    - `logs/`
    - `uploads/`
2.  **Right-click** each folder one by one.
3.  Select **Permissions** (or CHMOD).
4.  Set the value to **755** or **775**. Ensure the box for "Write" is checked for the "Owner" and "Group".
5.  Click **Save/Apply**.

---

## Phase 4: Fetch Product Data (Awin & Amazon)

Currently, the bot doesn't know about your products. We need to "tell" it to download the data.

1.  Open Chrome or your preferred browser.
2.  Type this exact address: `https://chat.lak24.de/import-trigger.php`
3.  **Wait**. It may take 1-2 minutes. Stay on the page until you see:
    `SUCCESS: Imported [Number] products.`
4.  **SECURITY STEP**: Go back to the IONOS File Manager and **DELETE** the file named `import-trigger.php`. This prevents strangers from resetting your database.

---

## Phase 5: Test your Bot

1.  Go to `https://chat.lak24.de/index.php`.
2.  Click the chat bubble Icon.
3.  Type: `I want a laptop under 800 EUR`.
4.  If it shows you real products with prices and links, **CONGRATULATIONS!** You have successfully deployed the bot.

---

### Troubleshooting for Beginners:
- **"Blank Page"**: Ensure your PHP version is set to 8.3 in the IONOS settings.
- **"Unable to save"**: Return to Phase 3 and double-check your folder permissions.
- **"No products found"**: Return to Phase 4 and make sure you saw the "SUCCESS" message.
