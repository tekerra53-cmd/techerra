import hashlib
import ipaddress
import os
import secrets
import smtplib
import sqlite3
from email.message import EmailMessage
from datetime import datetime, timedelta, timezone
from functools import wraps
from urllib.error import URLError
from urllib.parse import urlencode
from urllib.request import urlopen

from flask import Flask, abort, jsonify, redirect, render_template, request, session, url_for
from werkzeug.security import check_password_hash


BASE_DIR = os.path.dirname(os.path.abspath(__file__))


def load_env_file(path):
    if not os.path.exists(path):
        return
    with open(path, "r", encoding="utf-8") as env_file:
        for raw_line in env_file:
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            key = key.strip()
            value = value.strip().strip('"').strip("'")
            if key and key not in os.environ:
                os.environ[key] = value


load_env_file(os.path.join(BASE_DIR, ".env"))
DB_PATH = os.getenv("TECHERRA_CHAT_DB_PATH", os.path.join(BASE_DIR, "chat.sqlite"))
ADMIN_KEY = os.getenv("TECHERRA_CHAT_ADMIN_KEY", "change-this-admin-key")
ADMIN_PASSWORD = os.getenv("TECHERRA_CHAT_ADMIN_PASSWORD", ADMIN_KEY)
ADMIN_PASSWORD_HASH = os.getenv("TECHERRA_CHAT_ADMIN_PASSWORD_HASH", "")
ADMIN_ENFORCE_NETWORK_RESTRICTION = os.getenv("TECHERRA_CHAT_ADMIN_ENFORCE_NETWORK_RESTRICTION", "true").lower() == "true"
ADMIN_ALLOWED_HOSTS = {
    item.strip().lower()
    for item in os.getenv(
        "TECHERRA_CHAT_ADMIN_ALLOWED_HOSTS",
        "localhost,127.0.0.1,techerra.free.nf,www.techerra.free.nf",
    ).split(",")
    if item.strip()
}
ADMIN_ALLOWED_IPS = {
    item.strip()
    for item in os.getenv("TECHERRA_CHAT_ADMIN_ALLOWED_IPS", "127.0.0.1,::1").split(",")
    if item.strip()
}
ALERT_COOLDOWN_SECONDS = int(os.getenv("TECHERRA_CHAT_ALERT_COOLDOWN_SECONDS", "30"))
ENTRY_ALERT_COOLDOWN_SECONDS = int(os.getenv("TECHERRA_CHAT_ENTRY_ALERT_COOLDOWN_SECONDS", "300"))
NOTIFY_ON_VISITOR_ENTER = os.getenv("TECHERRA_CHAT_NOTIFY_ON_VISITOR_ENTER", "true").lower() == "true"
MAX_VISITOR_MESSAGES_PER_WINDOW = int(os.getenv("TECHERRA_CHAT_MAX_VISITOR_MESSAGES_PER_WINDOW", "6"))
VISITOR_MESSAGE_WINDOW_SECONDS = int(os.getenv("TECHERRA_CHAT_VISITOR_MESSAGE_WINDOW_SECONDS", "20"))
DUPLICATE_MESSAGE_COOLDOWN_SECONDS = int(os.getenv("TECHERRA_CHAT_DUPLICATE_MESSAGE_COOLDOWN_SECONDS", "12"))
BLOCKED_IP_HASHES = {
    item.strip()
    for item in os.getenv("TECHERRA_CHAT_BLOCKED_IP_HASHES", "").split(",")
    if item.strip()
}
SITE_NAME = os.getenv("TECHERRA_SITE_NAME", "TechErra")
CHAT_AGENT_NAME = os.getenv("TECHERRA_CHAT_AGENT_NAME", "Support")
CHAT_AGENT_TITLE = os.getenv("TECHERRA_CHAT_AGENT_TITLE", "Usually replies in a few minutes")
CHAT_BRAND_COLOR = os.getenv("TECHERRA_CHAT_BRAND_COLOR", "#0f4c81")
CHAT_ALERT_SOUND = os.getenv("TECHERRA_CHAT_ALERT_SOUND", "sounds/alert.mp3")
ALERT_EMAIL_TO = os.getenv("TECHERRA_CHAT_ALERT_EMAIL_TO", "")
ALERT_EMAIL_FROM = os.getenv("TECHERRA_CHAT_ALERT_EMAIL_FROM", "")
SMTP_HOST = os.getenv("TECHERRA_SMTP_HOST", "")
SMTP_PORT = int(os.getenv("TECHERRA_SMTP_PORT", "587"))
SMTP_USERNAME = os.getenv("TECHERRA_SMTP_USERNAME", "")
SMTP_PASSWORD = os.getenv("TECHERRA_SMTP_PASSWORD", "")
SMTP_USE_TLS = os.getenv("TECHERRA_SMTP_USE_TLS", "true").lower() == "true"
TELEGRAM_BOT_TOKEN = os.getenv("TECHERRA_CHAT_TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_IDS = [
    item.strip()
    for item in os.getenv("TECHERRA_CHAT_TELEGRAM_CHAT_ID", "").split(",")
    if item.strip()
]
WHATSAPP_PHONE = os.getenv("TECHERRA_CHAT_WHATSAPP_PHONE", "")
WHATSAPP_APIKEY = os.getenv("TECHERRA_CHAT_WHATSAPP_APIKEY", "")
INITIAL_REPLY_MESSAGE = os.getenv(
    "TECHERRA_CHAT_INITIAL_REPLY_MESSAGE",
    "Thanks for reaching out. We will be with you shortly. Please leave your email so we can follow up with you if you step away.",
)
DEFAULT_CHAT_SETTINGS = {
    "brandColor": CHAT_BRAND_COLOR,
    "agentName": CHAT_AGENT_NAME,
    "agentTitle": CHAT_AGENT_TITLE,
    "welcomeTitle": "We are here to help",
    "welcomeText": "Ask about a project, pricing, or support. Your messages go straight to our team.",
    "promptTitle": "Need help?",
    "promptText": "We can help with your project or answer questions.",
    "alignment": "right",
}

app = Flask(__name__, template_folder="templates", static_folder=".", static_url_path="")
app.secret_key = os.getenv("FLASK_SECRET_KEY", "change-this-flask-secret")


def now_utc():
    return datetime.now(timezone.utc)


def now_utc_str():
    return now_utc().strftime("%Y-%m-%d %H:%M:%S")


def utc_minus(seconds):
    return (now_utc() - timedelta(seconds=max(0, seconds))).strftime("%Y-%m-%d %H:%M:%S")


def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA foreign_keys = ON")
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS visitors (
            id TEXT PRIMARY KEY,
            name TEXT,
            email TEXT,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            last_page TEXT,
            user_agent TEXT,
            ip_hash TEXT,
            status TEXT NOT NULL DEFAULT 'open'
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_id TEXT NOT NULL,
            sender TEXT NOT NULL CHECK (sender IN ('visitor', 'agent')),
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            seen_by_agent INTEGER NOT NULL DEFAULT 0,
            seen_by_visitor INTEGER NOT NULL DEFAULT 0,
            is_auto INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_messages_visitor_id ON messages(visitor_id);
        CREATE INDEX IF NOT EXISTS idx_messages_unread_agent ON messages(visitor_id, sender, seen_by_agent);

        CREATE TABLE IF NOT EXISTS alert_state (
            visitor_id TEXT PRIMARY KEY,
            last_alert_at TEXT NOT NULL,
            FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS entry_alert_state (
            visitor_id TEXT PRIMARY KEY,
            last_entry_alert_at TEXT NOT NULL,
            FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS chat_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        """
    )
    message_columns = {row["name"] for row in conn.execute("PRAGMA table_info(messages)").fetchall()}
    if "is_auto" not in message_columns:
        conn.execute("ALTER TABLE messages ADD COLUMN is_auto INTEGER NOT NULL DEFAULT 0")
    return conn


def chat_settings(conn):
    rows = conn.execute("SELECT key, value FROM chat_settings").fetchall()
    settings = dict(DEFAULT_CHAT_SETTINGS)
    for row in rows:
        key = (row["key"] or "").strip()
        if key:
            settings[key] = row["value"] or ""
    return settings


def persist_chat_settings(conn, updates):
    allowed = set(DEFAULT_CHAT_SETTINGS.keys())
    clean = {}
    for key, value in (updates or {}).items():
        if key not in allowed:
            continue
        clean[key] = safe_text(value, 400)
    for key, value in clean.items():
        conn.execute(
            """
            INSERT INTO chat_settings (key, value)
            VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
            """,
            (key, value),
        )
    conn.commit()


def safe_text(value, max_len=1000):
    text = " ".join(str(value or "").strip().split())
    return text[:max_len]


def safe_multiline_text(value, max_len=1000):
    text = str(value or "").replace("\r\n", "\n").replace("\r", "\n")
    lines = [" ".join(line.strip().split()) for line in text.split("\n")]
    cleaned = "\n".join(line for line in lines if line)
    return cleaned[:max_len]


def request_data():
    data = request.get_json(silent=True)
    if isinstance(data, dict):
        return data
    return {}


def req_param(key, default=None):
    data = request_data()
    if key in data:
        return data.get(key)
    if key in request.form:
        return request.form.get(key)
    if key in request.args:
        return request.args.get(key)
    return default


def current_host():
    forwarded_host = (request.headers.get("X-Forwarded-Host") or "").split(",", 1)[0].strip()
    host_value = forwarded_host or (request.host or "")
    host = host_value.split(":", 1)[0].strip().lower()
    return host


def client_ip():
    forwarded_for = (request.headers.get("X-Forwarded-For") or "").split(",", 1)[0].strip()
    real_ip = (request.headers.get("X-Real-IP") or "").strip()
    return forwarded_for or real_ip or (request.remote_addr or "").strip()


def ip_allowed(ip_value, allowed_entries):
    ip_text = str(ip_value or "").strip()
    if not ip_text:
        return False
    try:
        address = ipaddress.ip_address(ip_text)
    except ValueError:
        return False

    for entry in allowed_entries:
        candidate = str(entry or "").strip()
        if not candidate:
            continue
        if "/" in candidate:
            try:
                network = ipaddress.ip_network(candidate, strict=False)
            except ValueError:
                continue
            if address in network:
                return True
            continue
        if candidate == ip_text:
            return True
    return False


def admin_network_allowed():
    if not ADMIN_ENFORCE_NETWORK_RESTRICTION:
        return True
    host_restricted = bool(ADMIN_ALLOWED_HOSTS)
    ip_restricted = bool(ADMIN_ALLOWED_IPS)
    host_ok = current_host() in ADMIN_ALLOWED_HOSTS if host_restricted else False
    ip_ok = ip_allowed(client_ip(), ADMIN_ALLOWED_IPS) if ip_restricted else False
    if host_restricted and ip_restricted:
        return host_ok or ip_ok
    if host_restricted:
        return host_ok
    if ip_restricted:
        return ip_ok
    return True


def block_if_not_allowed():
    if not admin_network_allowed():
        abort(403)


def json_error(message, status=400):
    return jsonify({"ok": False, "error": message}), status


def public_base_url():
    return request.host_url.rstrip("/")


def absolute_page_url(page):
    page = str(page or "").strip()
    if not page:
        return public_base_url()
    if page.startswith("http://") or page.startswith("https://"):
        return page
    if not page.startswith("/"):
        page = "/" + page
    return public_base_url() + page


def require_admin_api():
    if not admin_network_allowed():
        return json_error("Forbidden by network policy", 403)
    if session.get("techerra_admin_auth") is True:
        return None
    key = str(req_param("admin_key", "") or "")
    if secrets.compare_digest(ADMIN_KEY, key):
        return None
    return json_error("Unauthorized", 401)


def admin_page_required(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        block_if_not_allowed()
        return view(*args, **kwargs)

    return wrapped


def admin_password_valid(password):
    password = str(password or "")
    if ADMIN_PASSWORD_HASH:
        return check_password_hash(ADMIN_PASSWORD_HASH, password)
    return secrets.compare_digest(ADMIN_PASSWORD, password)


def visitor_ip_hash():
    return hashlib.sha256((client_ip() or "0.0.0.0").encode("utf-8")).hexdigest()


def visitor_id_from_ip_hash(ip_hash):
    digest = (ip_hash or "").strip().lower()
    if not digest:
        digest = hashlib.sha256(b"0.0.0.0").hexdigest()
    numeric = int(digest[:12], 16) % 10000000
    return f"v-{numeric:07d}"


def visitor_label_from_id(visitor_id):
    text = str(visitor_id or "").strip()
    if not text:
        return "Visitor"
    if text.startswith("v-") and len(text) == 9:
        return f"Visitor {text[2:]}"
    return text


def display_visitor_name(name, visitor_id):
    clean_name = safe_text(name or "", 80)
    if clean_name:
        return clean_name
    return visitor_label_from_id(visitor_id)


def find_visitor_id_by_ip_hash(conn, ip_hash):
    row = conn.execute(
        """
        SELECT id
        FROM visitors
        WHERE ip_hash = ?
        ORDER BY last_seen_at DESC
        LIMIT 1
        """,
        (ip_hash,),
    ).fetchone()
    return (row["id"] if row else "") or ""


def visitor_ip_hash_by_id(conn, visitor_id):
    row = conn.execute("SELECT ip_hash FROM visitors WHERE id = ? LIMIT 1", (visitor_id,)).fetchone()
    return (row["ip_hash"] if row else "") or ""


def is_ip_hash_blocked(ip_hash):
    return bool(ip_hash and ip_hash in BLOCKED_IP_HASHES)


def can_send_visitor_message(conn, visitor_id, message):
    ip_hash = visitor_ip_hash_by_id(conn, visitor_id)
    if is_ip_hash_blocked(ip_hash):
        return False, "Access blocked."

    if VISITOR_MESSAGE_WINDOW_SECONDS > 0 and MAX_VISITOR_MESSAGES_PER_WINDOW > 0 and ip_hash:
        row = conn.execute(
            """
            SELECT COUNT(1) AS c
            FROM messages m
            INNER JOIN visitors v ON v.id = m.visitor_id
            WHERE m.sender = 'visitor'
              AND v.ip_hash = ?
              AND m.created_at >= ?
            """,
            (ip_hash, utc_minus(VISITOR_MESSAGE_WINDOW_SECONDS)),
        ).fetchone()
        if int(row["c"] if row else 0) >= MAX_VISITOR_MESSAGES_PER_WINDOW:
            return False, "Too many messages. Please wait a few seconds."

    if DUPLICATE_MESSAGE_COOLDOWN_SECONDS > 0:
        row = conn.execute(
            """
            SELECT COUNT(1) AS c
            FROM messages
            WHERE visitor_id = ?
              AND sender = 'visitor'
              AND body = ?
              AND created_at >= ?
            """,
            (visitor_id, message, utc_minus(DUPLICATE_MESSAGE_COOLDOWN_SECONDS)),
        ).fetchone()
        if int(row["c"] if row else 0) > 0:
            return False, "Duplicate message detected. Please wait before resending."

    return True, ""


def send_http_get(url):
    try:
        with urlopen(url, timeout=8):
            return True
    except (URLError, TimeoutError, ValueError):
        return False


def send_email_alert(subject, body):
    if not ALERT_EMAIL_TO or not ALERT_EMAIL_FROM or not SMTP_HOST:
        return False, "Email alert settings are incomplete."

    message = EmailMessage()
    message["Subject"] = subject
    message["From"] = ALERT_EMAIL_FROM
    message["To"] = ALERT_EMAIL_TO
    message.set_content(body)

    try:
        with smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=12) as smtp:
            if SMTP_USE_TLS:
                smtp.starttls()
            if SMTP_USERNAME:
                smtp.login(SMTP_USERNAME, SMTP_PASSWORD)
            smtp.send_message(message)
        return True, ""
    except Exception as exc:
        return False, str(exc)


def send_telegram_alert(message):
    if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_IDS:
        return False
    sent = False
    for chat_id in TELEGRAM_CHAT_IDS:
        params = urlencode({"chat_id": chat_id, "text": message})
        url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage?{params}"
        sent = send_http_get(url) or sent
    return sent


def send_whatsapp_alert(message):
    if not WHATSAPP_PHONE or not WHATSAPP_APIKEY:
        return False
    params = urlencode(
        {
            "phone": WHATSAPP_PHONE,
            "text": message,
            "apikey": WHATSAPP_APIKEY,
        }
    )
    return send_http_get(f"https://api.callmebot.com/whatsapp.php?{params}")


def visitor_snapshot(conn, visitor_id):
    row = conn.execute(
        """
        SELECT id, name, email, first_seen_at, last_seen_at, last_page, user_agent, status
        FROM visitors
        WHERE id = ?
        LIMIT 1
        """,
        (visitor_id,),
    ).fetchone()
    return dict(row) if row else {}


def visitor_status(conn, visitor_id):
    row = conn.execute("SELECT status FROM visitors WHERE id = ? LIMIT 1", (visitor_id,)).fetchone()
    return ((row["status"] if row else "") or "open").strip().lower()


def maybe_send_initial_auto_reply(conn, visitor_id):
    visitor_count = conn.execute(
        "SELECT COUNT(1) FROM messages WHERE visitor_id = ? AND sender = 'visitor'",
        (visitor_id,),
    ).fetchone()[0]
    agent_count = conn.execute(
        "SELECT COUNT(1) FROM messages WHERE visitor_id = ? AND sender = 'agent'",
        (visitor_id,),
    ).fetchone()[0]
    if int(visitor_count or 0) != 1 or int(agent_count or 0) != 0:
        return False
    conn.execute(
        """
        INSERT INTO messages (visitor_id, sender, body, created_at, seen_by_agent, seen_by_visitor, is_auto)
        VALUES (?, 'agent', ?, ?, 1, 0, 1)
        """,
        (visitor_id, INITIAL_REPLY_MESSAGE, now_utc_str()),
    )
    conn.commit()
    return True


def build_alert_lines(title, visitor, extra_lines=None):
    details = [
        title,
        f"Site: {SITE_NAME}",
        f"Time (UTC): {now_utc_str()}",
        f"Visitor: {visitor.get('name') or 'Anonymous'}",
        f"Visitor ID: {visitor.get('id') or 'n/a'}",
        f"Email: {visitor.get('email') or 'n/a'}",
        f"Status: {visitor.get('status') or 'open'}",
        f"Page: {absolute_page_url(visitor.get('last_page') or '')}",
    ]
    if extra_lines:
        details.extend(extra_lines)
    return "\n".join(details)


def dispatch_alert(title, body):
    send_email_alert(title, body)
    send_telegram_alert(body)
    send_whatsapp_alert(body)


def send_test_email_alert():
    subject = f"{SITE_NAME}: test email alert"
    body = "\n".join(
        [
            "This is a test email from your TechErra chat system.",
            f"Site: {SITE_NAME}",
            f"Agent: {CHAT_AGENT_NAME}",
            f"Time (UTC): {now_utc_str()}",
            "If you received this, SMTP alerts are configured correctly.",
        ]
    )
    return send_email_alert(subject, body)


def send_test_telegram_alert():
    body = "\n".join(
        [
            f"{SITE_NAME}: test Telegram alert",
            "",
            "This is a test Telegram alert from your TechErra chat system.",
            f"Site: {SITE_NAME}",
            f"Agent: {CHAT_AGENT_NAME}",
            f"Time (UTC): {now_utc_str()}",
        ]
    )
    return send_telegram_alert(body)


def should_send_alert_now(conn, visitor_id):
    if ALERT_COOLDOWN_SECONDS <= 0:
        return True
    row = conn.execute(
        "SELECT last_alert_at FROM alert_state WHERE visitor_id = ? LIMIT 1",
        (visitor_id,),
    ).fetchone()
    if not row or not row["last_alert_at"]:
        return True
    last_alert = datetime.strptime(row["last_alert_at"], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    return (now_utc() - last_alert).total_seconds() >= ALERT_COOLDOWN_SECONDS


def mark_alert_sent(conn, visitor_id):
    conn.execute(
        """
        INSERT INTO alert_state (visitor_id, last_alert_at)
        VALUES (?, ?)
        ON CONFLICT(visitor_id) DO UPDATE SET last_alert_at = excluded.last_alert_at
        """,
        (visitor_id, now_utc_str()),
    )
    conn.commit()


def should_send_entry_alert_now(conn, visitor_id):
    if not NOTIFY_ON_VISITOR_ENTER:
        return False
    if ENTRY_ALERT_COOLDOWN_SECONDS <= 0:
        return True
    row = conn.execute(
        "SELECT last_entry_alert_at FROM entry_alert_state WHERE visitor_id = ? LIMIT 1",
        (visitor_id,),
    ).fetchone()
    if not row or not row["last_entry_alert_at"]:
        return True
    last_alert = datetime.strptime(row["last_entry_alert_at"], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    return (now_utc() - last_alert).total_seconds() >= ENTRY_ALERT_COOLDOWN_SECONDS


def mark_entry_alert_sent(conn, visitor_id):
    conn.execute(
        """
        INSERT INTO entry_alert_state (visitor_id, last_entry_alert_at)
        VALUES (?, ?)
        ON CONFLICT(visitor_id) DO UPDATE SET last_entry_alert_at = excluded.last_entry_alert_at
        """,
        (visitor_id, now_utc_str()),
    )
    conn.commit()


def notify_visitor_enter(conn, visitor_id):
    if should_send_entry_alert_now(conn, visitor_id):
        visitor = visitor_snapshot(conn, visitor_id)
        body = build_alert_lines(
            "Visitor entered your site",
            visitor,
            [
                f"Agent Target: {CHAT_AGENT_NAME}",
            ],
        )
        dispatch_alert(f"{SITE_NAME}: visitor entered your site", body)
        mark_entry_alert_sent(conn, visitor_id)


def notify_new_visitor_message(conn, visitor_id, message):
    if should_send_alert_now(conn, visitor_id):
        visitor = visitor_snapshot(conn, visitor_id)
        body = build_alert_lines(
            "New chat message from visitor",
            visitor,
            [
                f"Assigned Agent: {CHAT_AGENT_NAME}",
                f"Message: {message}",
            ],
        )
        dispatch_alert(f"{SITE_NAME}: new visitor message", body)
        mark_alert_sent(conn, visitor_id)


@app.route("/index.html")
@app.route("/")
def home():
    conn = get_db()
    try:
        settings = chat_settings(conn)
    finally:
        conn.close()
    return render_template(
        "index.html",
        chat_agent_name=settings["agentName"],
        chat_agent_title=settings["agentTitle"],
        chat_brand_color=settings["brandColor"],
        chat_alert_sound=CHAT_ALERT_SOUND,
        site_name=SITE_NAME,
        chat_settings=settings,
    )


@app.route("/health")
def health():
    return jsonify({"ok": True, "status": "healthy", "site": SITE_NAME}), 200


@app.route("/admin/network-check")
def admin_network_check():
    return jsonify(
        {
            "ok": True,
            "allowed": admin_network_allowed(),
            "current_host": current_host(),
            "client_ip": client_ip(),
            "allowed_hosts": sorted(ADMIN_ALLOWED_HOSTS),
            "allowed_ips": sorted(ADMIN_ALLOWED_IPS),
            "restriction_enabled": ADMIN_ENFORCE_NETWORK_RESTRICTION,
        }
    ), 200


@app.route("/policy.html")
@app.route("/privacy-policy")
def privacy_policy():
    conn = get_db()
    try:
        settings = chat_settings(conn)
    finally:
        conn.close()
    return render_template(
        "policy.html",
        chat_agent_name=settings["agentName"],
        chat_agent_title=settings["agentTitle"],
        chat_brand_color=settings["brandColor"],
        chat_alert_sound=CHAT_ALERT_SOUND,
        site_name=SITE_NAME,
        chat_settings=settings,
    )


@app.route("/admin/login")
@app.route("/chat-admin-login.php")
@admin_page_required
def admin_login_page():
    return render_template(
        "chat_admin_login.html",
        site_name=SITE_NAME,
        chat_agent_name=CHAT_AGENT_NAME,
        chat_alert_sound=CHAT_ALERT_SOUND,
    )


@app.route("/admin/chat")
@app.route("/chat-admin.php")
@admin_page_required
def admin_chat_page():
    return render_template(
        "chat_admin.html",
        site_name=SITE_NAME,
        chat_agent_name=CHAT_AGENT_NAME,
        chat_alert_sound=CHAT_ALERT_SOUND,
    )


@app.route("/api/chat", methods=["GET", "POST", "OPTIONS"])
@app.route("/chat-api.php", methods=["GET", "POST", "OPTIONS"])
def chat_api():
    if request.method == "OPTIONS":
        return ("", 204)

    action = str(req_param("action", "") or "")
    conn = get_db()

    try:
        if action == "admin_status":
            if not admin_network_allowed():
                return json_error("Forbidden by network policy", 403)
            return jsonify(
                {
                    "ok": True,
                    "authenticated": session.get("techerra_admin_auth") is True,
                    "agent_name": CHAT_AGENT_NAME,
                    "site_name": SITE_NAME,
                }
            )

        if action == "admin_login":
            if not admin_network_allowed():
                return json_error("Forbidden by network policy", 403)
            password = str(req_param("password", "") or "")
            if not password or not admin_password_valid(password):
                return json_error("Invalid credentials", 401)
            session.clear()
            session["techerra_admin_auth"] = True
            session["techerra_admin_login_at"] = now_utc_str()
            return jsonify({"ok": True})

        if action == "admin_logout":
            if not admin_network_allowed():
                return json_error("Forbidden by network policy", 403)
            session.clear()
            return jsonify({"ok": True})

        if action == "send_test_email":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error
            if not ALERT_EMAIL_TO or not ALERT_EMAIL_FROM or not SMTP_HOST:
                return json_error("Email alerts are not configured in .env", 422)
            sent, error_message = send_test_email_alert()
            if not sent:
                detail = error_message or "Check SMTP settings."
                return json_error(f"Test email failed to send. {detail}", 500)
            return jsonify({"ok": True, "message": f"Test email sent to {ALERT_EMAIL_TO}"})

        if action == "send_test_telegram":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error
            if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_IDS:
                return json_error("Telegram alerts are not configured in .env", 422)
            sent = send_test_telegram_alert()
            if not sent:
                return json_error("Test Telegram alert failed to send. Check bot token and chat ID.", 500)
            return jsonify({"ok": True, "message": f"Test Telegram alert sent to {', '.join(TELEGRAM_CHAT_IDS)}"})

        if action == "get_chat_settings":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error
            return jsonify({"ok": True, "settings": chat_settings(conn)})

        if action == "update_chat_settings":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error
            persist_chat_settings(conn, request_data().get("settings") or {})
            return jsonify({"ok": True, "settings": chat_settings(conn)})

        if action == "init":
            _visitor_id = safe_text(req_param("visitor_id", ""), 64)
            raw_name = safe_text(req_param("name", ""), 80)
            email = safe_text(req_param("email", ""), 120)
            page = safe_text(req_param("page", ""), 300)
            ip_hash = visitor_ip_hash()
            is_new = False

            existing_id = find_visitor_id_by_ip_hash(conn, ip_hash)
            visitor_id = existing_id or visitor_id_from_ip_hash(ip_hash)
            is_new = not bool(existing_id)

            name = display_visitor_name(raw_name, visitor_id)

            cursor = conn.execute(
                """
                UPDATE visitors
                SET name = CASE
                        WHEN ? <> '' AND (? = '' OR name = 'Visitor' OR name LIKE 'Visitor %') THEN ?
                        ELSE name
                    END,
                    email = CASE WHEN ? <> '' THEN ? ELSE email END,
                    last_seen_at = ?,
                    last_page = ?,
                    ip_hash = ?
                WHERE id = ?
                """,
                (name, raw_name, name, email, email, now_utc_str(), page, ip_hash, visitor_id),
            )
            conn.commit()
            if cursor.rowcount == 0:
                is_new = True
                conn.execute(
                    """
                    INSERT INTO visitors (id, name, email, first_seen_at, last_seen_at, last_page, user_agent, ip_hash, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')
                    """,
                    (
                        visitor_id,
                        name,
                        email,
                        now_utc_str(),
                        now_utc_str(),
                        page,
                        (request.headers.get("User-Agent", "") or "")[:300],
                        ip_hash,
                    ),
                )
                conn.commit()

            if is_new:
                notify_visitor_enter(conn, visitor_id)
            return jsonify(
                {
                    "ok": True,
                    "visitor_id": visitor_id,
                    "is_new_visitor": is_new,
                    "status": visitor_status(conn, visitor_id),
                    "agent": {
                        "name": CHAT_AGENT_NAME,
                        "title": CHAT_AGENT_TITLE,
                    },
                    "site_name": SITE_NAME,
                }
            )

        if action == "send":
            visitor_id = safe_text(req_param("visitor_id", ""), 64)
            message = safe_multiline_text(req_param("message", ""), 1000)
            if not visitor_id or not message:
                return json_error("Missing visitor_id or message", 422)

            allowed, reason = can_send_visitor_message(conn, visitor_id, message)
            if not allowed:
                return json_error(reason, 403 if reason == "Access blocked." else 429)

            cur = conn.execute(
                """
                INSERT INTO messages (visitor_id, sender, body, created_at, seen_by_agent, seen_by_visitor, is_auto)
                VALUES (?, 'visitor', ?, ?, 0, 1, 0)
                """,
                (visitor_id, message, now_utc_str()),
            )
            conn.execute(
                "UPDATE visitors SET last_seen_at = ?, status = 'open' WHERE id = ?",
                (now_utc_str(), visitor_id),
            )
            conn.commit()
            maybe_send_initial_auto_reply(conn, visitor_id)
            notify_new_visitor_message(conn, visitor_id, message)
            return jsonify({"ok": True, "message_id": cur.lastrowid, "status": "open"})

        if action == "admin_send":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error

            visitor_id = safe_text(req_param("visitor_id", ""), 64)
            message = safe_multiline_text(req_param("message", ""), 1000)
            if not visitor_id or not message:
                return json_error("Missing visitor_id or message", 422)

            cur = conn.execute(
                """
                INSERT INTO messages (visitor_id, sender, body, created_at, seen_by_agent, seen_by_visitor, is_auto)
                VALUES (?, 'agent', ?, ?, 1, 0, 0)
                """,
                (visitor_id, message, now_utc_str()),
            )
            conn.execute(
                "UPDATE visitors SET last_seen_at = ? WHERE id = ?",
                (now_utc_str(), visitor_id),
            )
            conn.commit()
            return jsonify({"ok": True, "message_id": cur.lastrowid})

        if action == "fetch":
            visitor_id = safe_text(req_param("visitor_id", ""), 64)
            since_id = int(req_param("since_id", 0) or 0)
            as_agent = str(req_param("role", "visitor") or "visitor") == "agent"
            if not visitor_id:
                return json_error("Missing visitor_id", 422)
            if as_agent:
                auth_error = require_admin_api()
                if auth_error:
                    return auth_error

            rows = conn.execute(
                """
                SELECT id, sender, body, created_at, seen_by_agent, seen_by_visitor, is_auto
                FROM messages
                WHERE visitor_id = ? AND id > ?
                ORDER BY id ASC
                """,
                (visitor_id, since_id),
            ).fetchall()

            messages = []
            last_id = since_id
            for row in rows:
                item = dict(row)
                item["id"] = int(item["id"])
                item["seen_by_agent"] = int(item["seen_by_agent"])
                item["seen_by_visitor"] = int(item["seen_by_visitor"])
                item["is_auto"] = int(item["is_auto"] or 0)
                messages.append(item)
                last_id = max(last_id, item["id"])

            if as_agent:
                conn.execute(
                    "UPDATE messages SET seen_by_agent = 1 WHERE visitor_id = ? AND sender = 'visitor'",
                    (visitor_id,),
                )
            else:
                conn.execute(
                    "UPDATE messages SET seen_by_visitor = 1 WHERE visitor_id = ? AND sender = 'agent'",
                    (visitor_id,),
                )
            conn.commit()
            return jsonify({"ok": True, "messages": messages, "last_id": last_id, "status": visitor_status(conn, visitor_id)})

        if action == "end_chat":
            visitor_id = safe_text(req_param("visitor_id", ""), 64)
            if not visitor_id:
                return json_error("Missing visitor_id", 422)
            conn.execute(
                "UPDATE visitors SET status = 'closed', last_seen_at = ? WHERE id = ?",
                (now_utc_str(), visitor_id),
            )
            conn.commit()
            return jsonify({"ok": True, "status": "closed"})

        if action == "list":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error

            rows = conn.execute(
                """
                SELECT
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
                          AND m3.sender = 'visitor'
                          AND m3.seen_by_agent = 0
                    ) AS unread_for_agent,
                    (
                        SELECT MAX(id) FROM messages m4
                        WHERE m4.visitor_id = v.id
                    ) AS last_message_id
                FROM visitors v
                ORDER BY v.last_seen_at DESC
                """
            ).fetchall()

            items = []
            for row in rows:
                item = dict(row)
                item["name"] = display_visitor_name(item.get("name"), item.get("id"))
                item["unread_for_agent"] = int(item["unread_for_agent"] or 0)
                item["last_message_id"] = int(item["last_message_id"] or 0)
                item["page_url"] = absolute_page_url(item.get("last_page") or "")
                items.append(item)
            return jsonify({"ok": True, "items": items})

        if action == "stats":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error
            open_chats = conn.execute("SELECT COUNT(1) FROM visitors WHERE status = 'open'").fetchone()[0]
            total_visitors = conn.execute("SELECT COUNT(1) FROM visitors").fetchone()[0]
            unread = conn.execute(
                "SELECT COUNT(1) FROM messages WHERE sender = 'visitor' AND seen_by_agent = 0"
            ).fetchone()[0]
            return jsonify(
                {
                    "ok": True,
                    "stats": {
                        "open_chats": int(open_chats or 0),
                        "total_visitors": int(total_visitors or 0),
                        "unread_messages": int(unread or 0),
                    },
                }
            )

        if action == "set_status":
            auth_error = require_admin_api()
            if auth_error:
                return auth_error
            visitor_id = safe_text(req_param("visitor_id", ""), 64)
            status = safe_text(req_param("status", ""), 20)
            if status not in {"open", "closed", "solved"}:
                return json_error("Invalid status", 422)
            conn.execute(
                "UPDATE visitors SET status = ?, last_seen_at = ? WHERE id = ?",
                (status, now_utc_str(), visitor_id),
            )
            conn.commit()
            return jsonify({"ok": True})

        return json_error("Unknown action", 404)
    finally:
        conn.close()


@app.errorhandler(403)
def forbidden(_error):
    return "Access denied.", 403


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 10000))
    app.run(host="0.0.0.0", port=port, debug=True)
