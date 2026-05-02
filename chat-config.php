<?php
// Change this key before deploying.
define('TECHERRA_CHAT_ADMIN_KEY', 'change-this-admin-key');
// Password hash for admin login page.
// Current password is: change-this-admin-key
// Generate a new hash with:
// php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
define('TECHERRA_CHAT_ADMIN_PASSWORD_HASH', '$2y$12$buviFdp3UeLD/MENO8H9b.OStjeVuLmx4JMGeNeSLlFNP8MG6a3MS');

// Restrict admin access by host/IP (applies to admin pages and admin API actions).
// For local development this allows localhost only.
define('TECHERRA_CHAT_ADMIN_ENFORCE_NETWORK_RESTRICTION', true);
define('TECHERRA_CHAT_ADMIN_ALLOWED_HOSTS', 'localhost,127.0.0.1,techerra.free.nf,www.techerra.free.nf');
define('TECHERRA_CHAT_ADMIN_ALLOWED_IPS', '127.0.0.1,::1');

define('TECHERRA_CHAT_DB_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'chat.sqlite');

// Optional alert targets for new visitor messages.
// Leave blank to disable a channel.
define('TECHERRA_CHAT_ALERT_EMAIL_TO', '');
define('TECHERRA_CHAT_ALERT_EMAIL_FROM', '');

define('TECHERRA_CHAT_TELEGRAM_BOT_TOKEN', '8342136901:AAHuqIGNvKrWw1iZEJ4qODyUA4YiA2L7-1k');
// You can provide one ID or comma-separated IDs.
define('TECHERRA_CHAT_TELEGRAM_CHAT_ID', '5044607846,1737837326');

// WhatsApp alert via CallMeBot (https://www.callmebot.com/).
define('TECHERRA_CHAT_WHATSAPP_PHONE', '2347052441423');
define('TECHERRA_CHAT_WHATSAPP_APIKEY', '');

// Minimum time between alerts per visitor (seconds).
define('TECHERRA_CHAT_ALERT_COOLDOWN_SECONDS', 30);

// Send notification when a visitor enters the site.
define('TECHERRA_CHAT_NOTIFY_ON_VISITOR_ENTER', true);
// Minimum time between "visitor entered" alerts per visitor (seconds).
define('TECHERRA_CHAT_ENTRY_ALERT_COOLDOWN_SECONDS', 300);

// Basic anti-spam controls for visitor messages.
define('TECHERRA_CHAT_MAX_VISITOR_MESSAGES_PER_WINDOW', 6);
define('TECHERRA_CHAT_VISITOR_MESSAGE_WINDOW_SECONDS', 20);
define('TECHERRA_CHAT_DUPLICATE_MESSAGE_COOLDOWN_SECONDS', 12);
// Comma-separated SHA256 hashes of blocked IPs.
define('TECHERRA_CHAT_BLOCKED_IP_HASHES', '');
