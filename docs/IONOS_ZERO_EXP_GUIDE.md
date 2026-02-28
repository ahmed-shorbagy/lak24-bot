# Ultimate Beginner's Guide: Deploying lak24 Bot to IONOS

This guide is tailored specifically for your IONOS setup. Follow these steps to ensure the bot works perfectly on `https://chat.lak24.de`.

---

## Step 1: Prepare the Files (On your Computer)

1.  **Open the project folder** (the one named `Chatbot`).
2.  **Select everything** EXCEPT:
    - `node_modules/`
    - `.git/`
3.  **Right-click** and select **Compress to ZIP file**. Name it `bot-release.zip`.

---

## Step 2: Upload to IONOS

1.  Log in to your **IONOS Control Panel** -> **Hosting** -> **File Manager**.
2.  Navigate to the folder linked to your subdomain (your screenshot shows this is the **`/Bot`** folder).
3.  Click **Upload** at the top and select your zip file.
4.  Once uploaded, **Right-click** the zip file and choose **Extract**.

> [!IMPORTANT]
> All your files (like `config.php`, `index.php`, `chat.php`) should be directly inside the **`/Bot`** folder. If they are inside another subfolder like `/Bot/Chatbot/`, move them out so they are directly in `/Bot`.

---

## Step 3: Specific Folder Fixes (Permissions)

IONOS has special rules for some folders. Follow this exactly:

1.  **If the following folders are missing**, click **"+ New Folder"** at the top to create them:
    - `data/`
    - `sessions/`
    - `cache/`
    - `uploads/`
2.  **The "logs" fix**: IONOS does not allow you to change the `logs` folder. 
    - Click **"+ New Folder"** and create a NEW folder named **`bot_logs`**.
3.  **Set Permissions (CHMOD)**:
    - Right-click each of these: `data`, `sessions`, `cache`, `uploads`, and your new `bot_logs`.
    - Select **Permissions**.
    - Set the value to **755** (or check "Write" for Owner/Group).
    - **Note**: Ignore the folder named `logs` entirely.

---

---

## Step 4: Activate the Product Database (Crucial Step)

The bot needs to download products from lak24, Awin, and Amazon to show them to customers.

1.  Open your browser and type: `https://chat.lak24.de/import-trigger.php`
2.  **Wait**. You will now see real-time updates like: `Progress: Imported 5000 products...`
3.  **Do not close the tab** until you see the final message: `SUCCESS: Imported [Number] products`.
4.  When you see **"SUCCESS: Imported [Number] products"**, you are done!
5.  **SECURITY**: Go back to IONOS File Manager and **DELETE** the file `import-trigger.php` immediately.

---

## Step 5: Setup Automatic Daily Updates (Cron Job)

To make the bot update prices automatically every night:

1.  Log in to **IONOS Control Panel** -> **Hosting** -> **Cron Jobs**.
2.  Click **Create Cron Job**.
3.  **Name**: `Lak24 Search Sync`.
4.  **HTTP GET**: Paste this exact URL:
    `https://chat.lak24.de/import-trigger.php`
5.  **Schedule**: Select **Daily** (e.g., 3:00 AM).
6.  Click **Save**.

---

## Step 6: Test it Live!

1.  Go to `https://chat.lak24.de`.
2.  Ask: `I want a laptop under 700 EUR`.
3.  **Check**: It should show you real products with prices and links.

---

### Troubleshooting:
- **"Import screen stuck"**: I have updated the script to handle long waits. Open the link again and give it 5 full minutes. 
- **"Red Error/Permissions"**: Ensure `bot_logs/` folder exists and is set to **755**.
- **"PHP Version"**: Verify your domain is set to **PHP 8.3** in IONOS.
