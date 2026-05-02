<?php
require_once __DIR__ . '/chat-security.php';
techerra_block_if_not_allowed();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TechErra Chat Admin</title>
  <style>
    :root {
      --bg: #f1f5f9;
      --panel: #ffffff;
      --line: #dbe4ee;
      --text: #122033;
      --muted: #64748b;
      --brand: #0f4c81;
      --danger: #d93025;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", sans-serif;
      color: var(--text);
      background: var(--bg);
    }
    .topbar {
      background: var(--panel);
      border-bottom: 1px solid var(--line);
      padding: 12px 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }
    .stats { display: flex; gap: 10px; flex-wrap: wrap; }
    .pill {
      background: #e8eff7;
      color: #13304f;
      border-radius: 14px;
      padding: 6px 10px;
      font-size: 12px;
    }
    .alert-btn {
      border: 1px solid #c6d5e4;
      background: #fff;
      color: #12304f;
      border-radius: 8px;
      padding: 6px 10px;
      font-size: 12px;
      cursor: pointer;
    }
    .alert-btn.active {
      background: #e8eff7;
      border-color: #a9c1da;
    }
    .layout {
      display: grid;
      grid-template-columns: 320px 1fr;
      height: calc(100vh - 58px);
    }
    .sidebar {
      border-right: 1px solid var(--line);
      background: var(--panel);
      overflow: auto;
    }
    .chat-item {
      padding: 12px;
      border-bottom: 1px solid var(--line);
      cursor: pointer;
    }
    .chat-item.active { background: #eef5fc; }
    .chat-item h4 {
      margin: 0 0 6px;
      font-size: 14px;
      display: flex;
      justify-content: space-between;
      gap: 8px;
    }
    .chat-item p {
      margin: 0;
      color: var(--muted);
      font-size: 12px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .badge {
      background: var(--danger);
      color: #fff;
      border-radius: 10px;
      min-width: 20px;
      text-align: center;
      font-size: 11px;
      padding: 2px 6px;
    }
    .main {
      display: grid;
      grid-template-rows: auto 1fr auto;
      min-height: 0;
    }
    .main-head {
      background: var(--panel);
      border-bottom: 1px solid var(--line);
      padding: 12px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .messages {
      padding: 12px;
      overflow: auto;
      min-height: 0;
    }
    .bubble {
      max-width: 78%;
      padding: 10px;
      border-radius: 11px;
      margin-bottom: 10px;
      white-space: pre-wrap;
      font-size: 13px;
    }
    .bubble.visitor {
      background: #fff;
      border: 1px solid var(--line);
      border-bottom-left-radius: 4px;
    }
    .bubble.agent {
      margin-left: auto;
      background: var(--brand);
      color: #fff;
      border-bottom-right-radius: 4px;
    }
    .send {
      display: flex;
      gap: 8px;
      border-top: 1px solid var(--line);
      background: var(--panel);
      padding: 10px;
    }
    .send input {
      flex: 1;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 10px;
      font-size: 14px;
    }
    .send button {
      border: 0;
      border-radius: 8px;
      background: var(--brand);
      color: #fff;
      padding: 0 16px;
      cursor: pointer;
    }
    .muted { color: var(--muted); font-size: 12px; }
    @media (max-width: 860px) {
      .layout { grid-template-columns: 1fr; }
      .sidebar { height: 38vh; border-right: 0; border-bottom: 1px solid var(--line); }
      .main { height: 62vh; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <strong>TechErra Chat Admin</strong>
    <div class="stats">
      <span class="pill" id="statOpen">Open: 0</span>
      <span class="pill" id="statVisitors">Visitors: 0</span>
      <span class="pill" id="statUnread">Unread: 0</span>
      <button class="alert-btn" id="enableAlertsBtn" type="button">Enable Alerts</button>
      <button class="alert-btn" id="muteBtn" type="button">Mute Sound</button>
      <button class="alert-btn" id="testAlertBtn" type="button">Test Alert</button>
      <button class="alert-btn" id="logoutBtn" type="button">Logout</button>
    </div>
  </div>

  <div class="layout">
    <aside class="sidebar" id="chatList"></aside>
    <section class="main">
      <div class="main-head">
        <div>
          <strong id="activeTitle">Select a conversation</strong><br />
          <span class="muted" id="activeMeta"></span>
        </div>
        <button id="toggleStatus" style="display:none">Close Chat</button>
      </div>
      <div class="messages" id="messages"></div>
      <div class="send">
        <input id="replyInput" type="text" placeholder="Type a reply" />
        <button id="replyBtn">Send</button>
      </div>
    </section>
  </div>

  <script>
    (function () {
      var API = "chat-api.php";

      var state = {
        chats: [],
        activeId: "",
        lastMessageId: 0,
        previousUnread: 0,
        unreadByVisitor: {},
        bootstrapped: false,
        soundMuted: localStorage.getItem("techerra_admin_sound_muted") === "1"
      };

      var chatList = document.getElementById("chatList");
      var messages = document.getElementById("messages");
      var activeTitle = document.getElementById("activeTitle");
      var activeMeta = document.getElementById("activeMeta");
      var replyInput = document.getElementById("replyInput");
      var replyBtn = document.getElementById("replyBtn");
      var toggleStatus = document.getElementById("toggleStatus");
      var enableAlertsBtn = document.getElementById("enableAlertsBtn");
      var muteBtn = document.getElementById("muteBtn");
      var testAlertBtn = document.getElementById("testAlertBtn");
      var logoutBtn = document.getElementById("logoutBtn");
      var audioCtx = null;

      function req(action, payload) {
        var body = Object.assign({ action: action }, payload || {});
        return fetch(API, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body)
        }).then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          return r.json();
        });
      }

      function esc(text) {
        return String(text || "").replace(/[&<>\"]/g, function (c) {
          return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c];
        });
      }

      function renderList() {
        chatList.innerHTML = state.chats.map(function (c) {
          var active = c.id === state.activeId ? "active" : "";
          var unread = c.unread_for_agent > 0 ? '<span class="badge">' + c.unread_for_agent + '</span>' : "";
          return '<div class="chat-item ' + active + '" data-id="' + c.id + '">' +
            '<h4><span>' + esc(c.name || "Visitor") + '</span>' + unread + '</h4>' +
            '<p>' + esc(c.last_message || "No messages yet") + '</p>' +
            '<p class="muted">' + esc(c.last_page || "") + '</p>' +
            '</div>';
        }).join("");

        [].slice.call(chatList.querySelectorAll(".chat-item")).forEach(function (node) {
          node.addEventListener("click", function () {
            state.activeId = node.getAttribute("data-id") || "";
            state.lastMessageId = 0;
            messages.innerHTML = "";
            renderList();
            fetchMessages();
            syncHead();
          });
        });
      }

      function syncHead() {
        var c = state.chats.find(function (x) { return x.id === state.activeId; });
        if (!c) {
          activeTitle.textContent = "Select a conversation";
          activeMeta.textContent = "";
          toggleStatus.style.display = "none";
          return;
        }
        activeTitle.textContent = c.name || "Visitor";
        activeMeta.textContent = [c.email || "no email", c.first_seen_at, "status: " + c.status].join(" | ");
        toggleStatus.style.display = "inline-block";
        toggleStatus.textContent = c.status === "open" ? "Close Chat" : "Reopen Chat";
      }

      function appendBubble(sender, body) {
        var div = document.createElement("div");
        div.className = "bubble " + sender;
        div.textContent = body;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
      }

      function fetchMessages() {
        if (!state.activeId) return;
        req("fetch", { visitor_id: state.activeId, since_id: state.lastMessageId, role: "agent" }).then(function (res) {
          if (!res || !res.ok) return;
          (res.messages || []).forEach(function (m) {
            appendBubble(m.sender, m.body);
          });
          state.lastMessageId = Math.max(state.lastMessageId, res.last_id || 0);
        }).catch(function () {
          window.location.href = "chat-admin-login.php";
        });
      }

      function fetchChats() {
        req("list").then(function (res) {
          if (!res || !res.ok) {
            if (res && res.error === "Unauthorized") {
              window.location.href = "chat-admin-login.php";
            }
            return;
          }

          state.chats = res.items || [];
          if (!state.activeId && state.chats.length) {
            state.activeId = state.chats[0].id;
          }

          detectNewVisitorMessages(state.chats);
          renderList();
          syncHead();
          fetchStats();
          state.bootstrapped = true;
        }).catch(function () {
          window.location.href = "chat-admin-login.php";
        });
      }

      function fetchStats() {
        req("stats").then(function (res) {
          if (!res || !res.ok || !res.stats) return;
          document.getElementById("statOpen").textContent = "Open: " + res.stats.open_chats;
          document.getElementById("statVisitors").textContent = "Visitors: " + res.stats.total_visitors;
          document.getElementById("statUnread").textContent = "Unread: " + res.stats.unread_messages;

          if (res.stats.unread_messages > 0) {
            document.title = "(" + res.stats.unread_messages + ") TechErra Chat Admin";
          } else {
            document.title = "TechErra Chat Admin";
          }
          state.previousUnread = res.stats.unread_messages;
        });
      }

      function alertPermissionGranted() {
        return "Notification" in window && Notification.permission === "granted";
      }

      function updateAlertsButton() {
        if (!("Notification" in window)) {
          enableAlertsBtn.style.display = "none";
          return;
        }
        if (Notification.permission === "granted") {
          enableAlertsBtn.textContent = "Alerts Enabled";
          enableAlertsBtn.disabled = true;
          enableAlertsBtn.style.opacity = "0.7";
          return;
        }
        enableAlertsBtn.textContent = "Enable Alerts";
        enableAlertsBtn.disabled = false;
        enableAlertsBtn.style.opacity = "1";
      }

      function playAlertSound() {
        if (state.soundMuted) return;
        try {
          var Ctx = window.AudioContext || window.webkitAudioContext;
          if (!Ctx) return;
          audioCtx = audioCtx || new Ctx();
          var now = audioCtx.currentTime;
          var osc = audioCtx.createOscillator();
          var gain = audioCtx.createGain();
          osc.type = "sine";
          osc.frequency.setValueAtTime(880, now);
          osc.frequency.setValueAtTime(660, now + 0.08);
          gain.gain.setValueAtTime(0.001, now);
          gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
          gain.gain.exponentialRampToValueAtTime(0.001, now + 0.2);
          osc.connect(gain);
          gain.connect(audioCtx.destination);
          osc.start(now);
          osc.stop(now + 0.22);
        } catch (e) {}
      }

      function fireDesktopNotification(title, body) {
        if (!alertPermissionGranted()) return;
        try {
          new Notification(title, { body: body });
        } catch (e) {}
      }

      function detectNewVisitorMessages(chats) {
        chats.forEach(function (c) {
          var prev = Number(state.unreadByVisitor[c.id] || 0);
          var curr = Number(c.unread_for_agent || 0);
          state.unreadByVisitor[c.id] = curr;

          if (!state.bootstrapped) return;
          if (curr > prev) {
            var label = c.name || "Visitor";
            var preview = c.last_message || "sent a new message";
            playAlertSound();
            fireDesktopNotification("New visitor message: " + label, preview);
          }
        });
      }

      function sendReply() {
        if (!state.activeId) return;
        var message = replyInput.value.trim();
        if (!message) return;
        replyBtn.disabled = true;

        req("admin_send", { visitor_id: state.activeId, message: message }).then(function (res) {
          if (res && res.ok) {
            replyInput.value = "";
            appendBubble("agent", message);
            fetchChats();
          }
        }).finally(function () {
          replyBtn.disabled = false;
          replyInput.focus();
        });
      }

      replyBtn.addEventListener("click", sendReply);
      replyInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          sendReply();
        }
      });

      toggleStatus.addEventListener("click", function () {
        var c = state.chats.find(function (x) { return x.id === state.activeId; });
        if (!c) return;
        var next = c.status === "open" ? "closed" : "open";
        req("set_status", { visitor_id: c.id, status: next }).then(function () {
          fetchChats();
        });
      });

      enableAlertsBtn.addEventListener("click", function () {
        if (!("Notification" in window)) return;
        Notification.requestPermission().finally(updateAlertsButton);
      });

      function updateMuteButton() {
        if (state.soundMuted) {
          muteBtn.textContent = "Unmute Sound";
          muteBtn.classList.add("active");
        } else {
          muteBtn.textContent = "Mute Sound";
          muteBtn.classList.remove("active");
        }
      }

      muteBtn.addEventListener("click", function () {
        state.soundMuted = !state.soundMuted;
        localStorage.setItem("techerra_admin_sound_muted", state.soundMuted ? "1" : "0");
        updateMuteButton();
      });

      testAlertBtn.addEventListener("click", function () {
        playAlertSound();
        fireDesktopNotification("TechErra Test", "Desktop notifications are active.");
      });

      logoutBtn.addEventListener("click", function () {
        req("admin_logout").finally(function () {
          window.location.href = "chat-admin-login.php";
        });
      });

      req("admin_status").then(function (res) {
        if (!res || !res.ok || !res.authenticated) {
          window.location.href = "chat-admin-login.php";
          return;
        }
        updateMuteButton();
        updateAlertsButton();
        fetchChats();
        setInterval(function () {
          fetchChats();
          fetchMessages();
        }, 4000);
      }).catch(function () {
        window.location.href = "chat-admin-login.php";
      });

    })();
  </script>
</body>
</html>
