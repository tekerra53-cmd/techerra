<?php
declare(strict_types=1);

require_once __DIR__ . '/chat-security.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function req_json(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $raw = file_get_contents('php://input');
    if (!$raw) {
        $cache = [];
        return $cache;
    }
    $data = json_decode($raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

function req_param(string $key, $default = null)
{
    $json = req_json();
    if (array_key_exists($key, $json)) {
        return $json[$key];
    }
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function visitor_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', $ip);
}

function ensure_db(): SQLite3
{
    $db = new SQLite3(TECHERRA_CHAT_DB_PATH);
    $db->busyTimeout(3000);

    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec('CREATE TABLE IF NOT EXISTS visitors (
        id TEXT PRIMARY KEY,
        name TEXT,
        email TEXT,
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        last_page TEXT,
        user_agent TEXT,
        ip_hash TEXT,
        status TEXT NOT NULL DEFAULT "open"
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id TEXT NOT NULL,
        sender TEXT NOT NULL CHECK (sender IN ("visitor", "agent")),
        body TEXT NOT NULL,
        created_at TEXT NOT NULL,
        seen_by_agent INTEGER NOT NULL DEFAULT 0,
        seen_by_visitor INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_visitor_id ON messages(visitor_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_unread_agent ON messages(visitor_id, sender, seen_by_agent)');

    $db->exec('CREATE TABLE IF NOT EXISTS alert_state (
        visitor_id TEXT PRIMARY KEY,
        last_alert_at TEXT NOT NULL,
        FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS entry_alert_state (
        visitor_id TEXT PRIMARY KEY,
        last_entry_alert_at TEXT NOT NULL,
        FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
    )');

    return $db;
}

function require_admin(): void
{
    if (!techerra_admin_network_allowed()) {
        json_out(['ok' => false, 'error' => 'Forbidden by network policy'], 403);
    }
    if (!empty($_SESSION['techerra_admin_auth']) && $_SESSION['techerra_admin_auth'] === true) {
        return;
    }
    $key = (string) req_param('admin_key', '');
    if (!hash_equals(TECHERRA_CHAT_ADMIN_KEY, $key)) {
        json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

function admin_password_valid(string $password): bool
{
    $hash = defined('TECHERRA_CHAT_ADMIN_PASSWORD_HASH') ? (string) TECHERRA_CHAT_ADMIN_PASSWORD_HASH : '';
    if ($hash !== '' && str_starts_with($hash, '$2')) {
        return password_verify($password, $hash);
    }
    return hash_equals(TECHERRA_CHAT_ADMIN_KEY, $password);
}

function safe_text($v, int $max = 1000): string
{
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') {
        return '';
    }
    $v = preg_replace('/\s+/', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
}

function now_utc_minus(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() - max(0, $seconds));
}

function blocked_ip_hashes(): array
{
    $raw = defined('TECHERRA_CHAT_BLOCKED_IP_HASHES') ? (string) TECHERRA_CHAT_BLOCKED_IP_HASHES : '';
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function is_ip_hash_blocked(string $ipHash): bool
{
    if ($ipHash === '') {
        return false;
    }
    $blocked = blocked_ip_hashes();
    foreach ($blocked as $item) {
        if (hash_equals($item, $ipHash)) {
            return true;
        }
    }
    return false;
}

function visitor_ip_hash_by_id(SQLite3 $db, string $visitorId): string
{
    $stmt = $db->prepare('SELECT ip_hash FROM visitors WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    return trim((string) ($row['ip_hash'] ?? ''));
}

function can_send_visitor_message(SQLite3 $db, string $visitorId, string $message): array
{
    $ipHash = visitor_ip_hash_by_id($db, $visitorId);
    if (is_ip_hash_blocked($ipHash)) {
        return [false, 'Access blocked.'];
    }

    $window = (int) (defined('TECHERRA_CHAT_VISITOR_MESSAGE_WINDOW_SECONDS')
        ? TECHERRA_CHAT_VISITOR_MESSAGE_WINDOW_SECONDS
        : 20);
    $limit = (int) (defined('TECHERRA_CHAT_MAX_VISITOR_MESSAGES_PER_WINDOW')
        ? TECHERRA_CHAT_MAX_VISITOR_MESSAGES_PER_WINDOW
        : 6);
    $dupCooldown = (int) (defined('TECHERRA_CHAT_DUPLICATE_MESSAGE_COOLDOWN_SECONDS')
        ? TECHERRA_CHAT_DUPLICATE_MESSAGE_COOLDOWN_SECONDS
        : 12);

    if ($window > 0 && $limit > 0 && $ipHash !== '') {
        $stmt = $db->prepare('SELECT COUNT(1) AS c
            FROM messages m
            INNER JOIN visitors v ON v.id = m.visitor_id
            WHERE m.sender = "visitor"
              AND v.ip_hash = :ip_hash
              AND m.created_at >= :from_time');
        $stmt->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':from_time', now_utc_minus($window), SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $count = (int) ($row['c'] ?? 0);
        if ($count >= $limit) {
            return [false, 'Too many messages. Please wait a few seconds.'];
        }
    }

    if ($dupCooldown > 0) {
        $stmt = $db->prepare('SELECT COUNT(1) AS c
            FROM messages
            WHERE visitor_id = :visitor_id
              AND sender = "visitor"
              AND body = :body
              AND created_at >= :from_time');
        $stmt->bindValue(':visitor_id', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':body', $message, SQLITE3_TEXT);
        $stmt->bindValue(':from_time', now_utc_minus($dupCooldown), SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $dupCount = (int) ($row['c'] ?? 0);
        if ($dupCount > 0) {
            return [false, 'Duplicate message detected. Please wait before resending.'];
        }
    }

    return [true, ''];
}

function alert_http_get(string $url): void
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    @file_get_contents($url);
}

function alert_email(string $subject, string $body): void
{
    if (TECHERRA_CHAT_ALERT_EMAIL_TO === '') {
        return;
    }
    $headers = [];
    if (TECHERRA_CHAT_ALERT_EMAIL_FROM !== '') {
        $headers[] = 'From: ' . TECHERRA_CHAT_ALERT_EMAIL_FROM;
    }
    @mail(
        TECHERRA_CHAT_ALERT_EMAIL_TO,
        $subject,
        $body,
        implode("\r\n", $headers)
    );
}

function alert_telegram(string $message): void
{
    if (TECHERRA_CHAT_TELEGRAM_BOT_TOKEN === '' || TECHERRA_CHAT_TELEGRAM_CHAT_ID === '') {
        return;
    }
    $chatIds = array_filter(array_map('trim', explode(',', TECHERRA_CHAT_TELEGRAM_CHAT_ID)));
    foreach ($chatIds as $chatId) {
        if ($chatId === '') {
            continue;
        }
        $url = 'https://api.telegram.org/bot' . rawurlencode(TECHERRA_CHAT_TELEGRAM_BOT_TOKEN)
            . '/sendMessage?chat_id=' . rawurlencode($chatId)
            . '&text=' . rawurlencode($message);
        alert_http_get($url);
    }
}

function alert_whatsapp(string $message): void
{
    if (TECHERRA_CHAT_WHATSAPP_PHONE === '' || TECHERRA_CHAT_WHATSAPP_APIKEY === '') {
        return;
    }
    $url = 'https://api.callmebot.com/whatsapp.php?phone=' . rawurlencode(TECHERRA_CHAT_WHATSAPP_PHONE)
        . '&text=' . rawurlencode($message)
        . '&apikey=' . rawurlencode(TECHERRA_CHAT_WHATSAPP_APIKEY);
    alert_http_get($url);
}

function should_send_alert_now(SQLite3 $db, string $visitorId): bool
{
    $cooldown = (int) TECHERRA_CHAT_ALERT_COOLDOWN_SECONDS;
    if ($cooldown <= 0) {
        return true;
    }

    $stmt = $db->prepare('SELECT last_alert_at FROM alert_state WHERE visitor_id = :id LIMIT 1');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

    if (!$row || empty($row['last_alert_at'])) {
        return true;
    }

    $lastTs = strtotime((string) $row['last_alert_at']);
    if ($lastTs === false) {
        return true;
    }

    return (time() - $lastTs) >= $cooldown;
}

function mark_alert_sent(SQLite3 $db, string $visitorId): void
{
    $stmt = $db->prepare('INSERT INTO alert_state (visitor_id, last_alert_at)
        VALUES (:id, :last_alert_at)
        ON CONFLICT(visitor_id) DO UPDATE SET last_alert_at = excluded.last_alert_at');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':last_alert_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();
}

function should_send_entry_alert_now(SQLite3 $db, string $visitorId): bool
{
    if (!defined('TECHERRA_CHAT_NOTIFY_ON_VISITOR_ENTER') || TECHERRA_CHAT_NOTIFY_ON_VISITOR_ENTER !== true) {
        return false;
    }
    $cooldown = defined('TECHERRA_CHAT_ENTRY_ALERT_COOLDOWN_SECONDS')
        ? (int) TECHERRA_CHAT_ENTRY_ALERT_COOLDOWN_SECONDS
        : 300;
    if ($cooldown <= 0) {
        return true;
    }

    $stmt = $db->prepare('SELECT last_entry_alert_at FROM entry_alert_state WHERE visitor_id = :id LIMIT 1');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

    if (!$row || empty($row['last_entry_alert_at'])) {
        return true;
    }

    $lastTs = strtotime((string) $row['last_entry_alert_at']);
    if ($lastTs === false) {
        return true;
    }
    return (time() - $lastTs) >= $cooldown;
}

function mark_entry_alert_sent(SQLite3 $db, string $visitorId): void
{
    $stmt = $db->prepare('INSERT INTO entry_alert_state (visitor_id, last_entry_alert_at)
        VALUES (:id, :last_entry_alert_at)
        ON CONFLICT(visitor_id) DO UPDATE SET last_entry_alert_at = excluded.last_entry_alert_at');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':last_entry_alert_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();
}

function notify_visitor_enter(SQLite3 $db, string $visitorId): void
{
    if (!should_send_entry_alert_now($db, $visitorId)) {
        return;
    }
    $stmt = $db->prepare('SELECT name, email, last_page FROM visitors WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $visitor = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

    $name = trim((string) ($visitor['name'] ?? ''));
    $email = trim((string) ($visitor['email'] ?? ''));
    $page = trim((string) ($visitor['last_page'] ?? ''));

    $title = 'Visitor entered your site';
    $lines = [
        $title,
        'Time (UTC): ' . now_utc(),
        'Visitor: ' . ($name !== '' ? $name : 'Anonymous'),
        'Visitor ID: ' . $visitorId,
        'Email: ' . ($email !== '' ? $email : 'n/a'),
        'Page: ' . ($page !== '' ? $page : 'n/a')
    ];
    $text = implode("\n", $lines);

    alert_email($title, $text);
    alert_telegram($text);
    alert_whatsapp($text);
    mark_entry_alert_sent($db, $visitorId);
}

function notify_new_visitor_message(SQLite3 $db, string $visitorId, string $message): void
{
    if (!should_send_alert_now($db, $visitorId)) {
        return;
    }

    $stmt = $db->prepare('SELECT name, email, last_page FROM visitors WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $res = $stmt->execute();
    $visitor = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;

    $name = trim((string) ($visitor['name'] ?? ''));
    $email = trim((string) ($visitor['email'] ?? ''));
    $page = trim((string) ($visitor['last_page'] ?? ''));

    $title = 'New chat message from ' . ($name !== '' ? $name : 'Visitor');
    $lines = [
        $title,
        'Time (UTC): ' . now_utc(),
        'Visitor ID: ' . $visitorId,
        'Email: ' . ($email !== '' ? $email : 'n/a'),
        'Page: ' . ($page !== '' ? $page : 'n/a'),
        'Message: ' . $message
    ];
    $text = implode("\n", $lines);

    alert_email($title, $text);
    alert_telegram($text);
    alert_whatsapp($text);
    mark_alert_sent($db, $visitorId);
}

$db = ensure_db();
$action = (string) req_param('action', '');

if ($action === 'admin_status') {
    if (!techerra_admin_network_allowed()) {
        json_out(['ok' => false, 'error' => 'Forbidden by network policy'], 403);
    }
    json_out([
        'ok' => true,
        'authenticated' => !empty($_SESSION['techerra_admin_auth']) && $_SESSION['techerra_admin_auth'] === true
    ]);
}

if ($action === 'admin_login') {
    if (!techerra_admin_network_allowed()) {
        json_out(['ok' => false, 'error' => 'Forbidden by network policy'], 403);
    }
    $password = (string) req_param('password', '');
    if ($password === '' || !admin_password_valid($password)) {
        json_out(['ok' => false, 'error' => 'Invalid credentials'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['techerra_admin_auth'] = true;
    $_SESSION['techerra_admin_login_at'] = now_utc();
    json_out(['ok' => true]);
}

if ($action === 'admin_logout') {
    if (!techerra_admin_network_allowed()) {
        json_out(['ok' => false, 'error' => 'Forbidden by network policy'], 403);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, (string) $params['path'], (string) $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    json_out(['ok' => true]);
}

if ($action === 'init') {
    $visitorId = safe_text(req_param('visitor_id', ''), 64);
    $name = safe_text(req_param('name', ''), 80);
    $email = safe_text(req_param('email', ''), 120);
    $page = safe_text(req_param('page', ''), 300);

    $isNewVisitor = false;
    if ($visitorId === '') {
        $isNewVisitor = true;
        $visitorId = bin2hex(random_bytes(16));
        $stmt = $db->prepare('INSERT INTO visitors (id, name, email, first_seen_at, last_seen_at, last_page, user_agent, ip_hash, status)
            VALUES (:id, :name, :email, :first_seen_at, :last_seen_at, :last_page, :user_agent, :ip_hash, "open")');
        $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':first_seen_at', now_utc(), SQLITE3_TEXT);
        $stmt->bindValue(':last_seen_at', now_utc(), SQLITE3_TEXT);
        $stmt->bindValue(':last_page', $page, SQLITE3_TEXT);
        $stmt->bindValue(':user_agent', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), SQLITE3_TEXT);
        $stmt->bindValue(':ip_hash', visitor_ip_hash(), SQLITE3_TEXT);
        $stmt->execute();
    } else {
        $stmt = $db->prepare('UPDATE visitors SET
            name = CASE WHEN :name <> "" THEN :name ELSE name END,
            email = CASE WHEN :email <> "" THEN :email ELSE email END,
            last_seen_at = :last_seen_at,
            last_page = :last_page
            WHERE id = :id');
        $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':last_seen_at', now_utc(), SQLITE3_TEXT);
        $stmt->bindValue(':last_page', $page, SQLITE3_TEXT);
        $stmt->execute();

        if ($db->changes() === 0) {
            $isNewVisitor = true;
            $stmt = $db->prepare('INSERT INTO visitors (id, name, email, first_seen_at, last_seen_at, last_page, user_agent, ip_hash, status)
                VALUES (:id, :name, :email, :first_seen_at, :last_seen_at, :last_page, :user_agent, :ip_hash, "open")');
            $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':first_seen_at', now_utc(), SQLITE3_TEXT);
            $stmt->bindValue(':last_seen_at', now_utc(), SQLITE3_TEXT);
            $stmt->bindValue(':last_page', $page, SQLITE3_TEXT);
            $stmt->bindValue(':user_agent', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), SQLITE3_TEXT);
            $stmt->bindValue(':ip_hash', visitor_ip_hash(), SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    notify_visitor_enter($db, $visitorId);

    json_out(['ok' => true, 'visitor_id' => $visitorId]);
}

if ($action === 'send') {
    $visitorId = safe_text(req_param('visitor_id', ''), 64);
    $message = safe_text(req_param('message', ''), 1000);

    if ($visitorId === '' || $message === '') {
        json_out(['ok' => false, 'error' => 'Missing visitor_id or message'], 422);
    }

    [$allowed, $reason] = can_send_visitor_message($db, $visitorId, $message);
    if (!$allowed) {
        $status = $reason === 'Access blocked.' ? 403 : 429;
        json_out(['ok' => false, 'error' => $reason], $status);
    }

    $stmt = $db->prepare('INSERT INTO messages (visitor_id, sender, body, created_at, seen_by_agent, seen_by_visitor)
        VALUES (:visitor_id, "visitor", :body, :created_at, 0, 1)');
    $stmt->bindValue(':visitor_id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':body', $message, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();

    $stmt = $db->prepare('UPDATE visitors SET last_seen_at = :last_seen_at WHERE id = :id');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':last_seen_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();

    notify_new_visitor_message($db, $visitorId, $message);

    json_out(['ok' => true, 'message_id' => $db->lastInsertRowID()]);
}

if ($action === 'admin_send') {
    require_admin();

    $visitorId = safe_text(req_param('visitor_id', ''), 64);
    $message = safe_text(req_param('message', ''), 1000);

    if ($visitorId === '' || $message === '') {
        json_out(['ok' => false, 'error' => 'Missing visitor_id or message'], 422);
    }

    $stmt = $db->prepare('INSERT INTO messages (visitor_id, sender, body, created_at, seen_by_agent, seen_by_visitor)
        VALUES (:visitor_id, "agent", :body, :created_at, 1, 0)');
    $stmt->bindValue(':visitor_id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':body', $message, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();

    $stmt = $db->prepare('UPDATE visitors SET last_seen_at = :last_seen_at WHERE id = :id');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':last_seen_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();

    json_out(['ok' => true, 'message_id' => $db->lastInsertRowID()]);
}

if ($action === 'fetch') {
    $visitorId = safe_text(req_param('visitor_id', ''), 64);
    $sinceId = (int) req_param('since_id', 0);
    $asAgent = (string) req_param('role', 'visitor') === 'agent';

    if ($visitorId === '') {
        json_out(['ok' => false, 'error' => 'Missing visitor_id'], 422);
    }

    if ($asAgent) {
        require_admin();
    }

    $stmt = $db->prepare('SELECT id, sender, body, created_at, seen_by_agent, seen_by_visitor
        FROM messages
        WHERE visitor_id = :visitor_id AND id > :since_id
        ORDER BY id ASC');
    $stmt->bindValue(':visitor_id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':since_id', $sinceId, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $messages = [];
    $maxId = $sinceId;
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['id'] = (int) $row['id'];
        $row['seen_by_agent'] = (int) $row['seen_by_agent'];
        $row['seen_by_visitor'] = (int) $row['seen_by_visitor'];
        $messages[] = $row;
        if ($row['id'] > $maxId) {
            $maxId = $row['id'];
        }
    }

    if ($asAgent) {
        $stmt = $db->prepare('UPDATE messages SET seen_by_agent = 1 WHERE visitor_id = :visitor_id AND sender = "visitor"');
        $stmt->bindValue(':visitor_id', $visitorId, SQLITE3_TEXT);
        $stmt->execute();
    } else {
        $stmt = $db->prepare('UPDATE messages SET seen_by_visitor = 1 WHERE visitor_id = :visitor_id AND sender = "agent"');
        $stmt->bindValue(':visitor_id', $visitorId, SQLITE3_TEXT);
        $stmt->execute();
    }

    json_out(['ok' => true, 'messages' => $messages, 'last_id' => $maxId]);
}

if ($action === 'list') {
    require_admin();

    $sql = 'SELECT
        v.id,
        v.name,
        v.email,
        v.first_seen_at,
        v.last_seen_at,
        v.last_page,
        v.status,
        (
            SELECT body FROM messages m2
            WHERE m2.visitor_id = v.id
            ORDER BY m2.id DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT COUNT(1) FROM messages m3
            WHERE m3.visitor_id = v.id
            AND m3.sender = "visitor"
            AND m3.seen_by_agent = 0
        ) AS unread_for_agent,
        (
            SELECT MAX(id) FROM messages m4
            WHERE m4.visitor_id = v.id
        ) AS last_message_id
        FROM visitors v
        ORDER BY v.last_seen_at DESC';

    $res = $db->query($sql);
    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['unread_for_agent'] = (int) $row['unread_for_agent'];
        $row['last_message_id'] = (int) ($row['last_message_id'] ?? 0);
        $items[] = $row;
    }

    json_out(['ok' => true, 'items' => $items]);
}

if ($action === 'stats') {
    require_admin();

    $openChats = (int) ($db->querySingle('SELECT COUNT(1) FROM visitors WHERE status = "open"') ?? 0);
    $totalVisitors = (int) ($db->querySingle('SELECT COUNT(1) FROM visitors') ?? 0);
    $unread = (int) ($db->querySingle('SELECT COUNT(1) FROM messages WHERE sender = "visitor" AND seen_by_agent = 0') ?? 0);

    json_out([
        'ok' => true,
        'stats' => [
            'open_chats' => $openChats,
            'total_visitors' => $totalVisitors,
            'unread_messages' => $unread
        ]
    ]);
}

if ($action === 'set_status') {
    require_admin();

    $visitorId = safe_text(req_param('visitor_id', ''), 64);
    $status = safe_text(req_param('status', ''), 20);

    if (!in_array($status, ['open', 'closed'], true)) {
        json_out(['ok' => false, 'error' => 'Invalid status'], 422);
    }

    $stmt = $db->prepare('UPDATE visitors SET status = :status, last_seen_at = :last_seen_at WHERE id = :id');
    $stmt->bindValue(':id', $visitorId, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':last_seen_at', now_utc(), SQLITE3_TEXT);
    $stmt->execute();

    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'Unknown action'], 404);
