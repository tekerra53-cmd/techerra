(function () {
  var API_URL = "chat-api.php";
  var STORAGE_KEY = "techerra_chat_visitor_id";
  var LAST_ID_KEY = "techerra_chat_last_id";
  var NAME_KEY = "techerra_chat_name";
  var EMAIL_KEY = "techerra_chat_email";
  var seenIds = Object.create(null);

  function el(html) {
    var d = document.createElement("div");
    d.innerHTML = html.trim();
    return d.firstChild;
  }

  function request(action, payload) {
    var body = Object.assign({ action: action }, payload || {});
    return fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }).then(function (r) {
      if (!r.ok) throw new Error("HTTP " + r.status);
      return r.json();
    });
  }

  function renderUI() {
    var launcher = el(
      '<button class="chat-launcher" aria-label="Open chat">' +
      '<span class="chat-launcher-icon">??</span>' +
      '<span class="chat-launcher-badge" style="display:none"></span>' +
      '</button>'
    );

    var widget = el(
      '<div class="chat-widget">' +
      '  <div class="chat-header">' +
      '    <div><strong>Chat with TechErra</strong><div class="chat-status"><span class="chat-status-dot"></span><span class="chat-status-text">Online</span></div></div>' +
      '    <button class="chat-close" type="button" aria-label="Close">&times;</button>' +
      '  </div>' +
      '  <div class="chat-identity">' +
      '    <input type="text" placeholder="Your name" class="chat-name" />' +
      '    <input type="email" placeholder="Email (optional)" class="chat-email" />' +
      '  </div>' +
      '  <div class="chat-error"></div>' +
      '  <div class="chat-messages"><div class="chat-empty">Start a conversation. We usually reply fast.</div></div>' +
      '  <div class="chat-input-wrap"><input type="text" placeholder="Type your message" class="chat-input" /><button type="button" class="chat-send">Send</button></div>' +
      '  <div class="chat-note">Your message goes directly to our inbox.</div>' +
      '</div>'
    );

    document.body.appendChild(launcher);
    document.body.appendChild(widget);

    return {
      launcher: launcher,
      widget: widget,
      badge: launcher.querySelector(".chat-launcher-badge"),
      closeBtn: widget.querySelector(".chat-close"),
      messages: widget.querySelector(".chat-messages"),
      input: widget.querySelector(".chat-input"),
      sendBtn: widget.querySelector(".chat-send"),
      name: widget.querySelector(".chat-name"),
      email: widget.querySelector(".chat-email"),
      error: widget.querySelector(".chat-error"),
      statusText: widget.querySelector(".chat-status-text"),
      empty: widget.querySelector(".chat-empty"),
    };
  }

  function appendMessage(ui, sender, text, id) {
    if (id && seenIds[id]) return;
    if (id) seenIds[id] = true;

    if (ui.empty) {
      ui.empty.remove();
      ui.empty = null;
    }
    var bubble = document.createElement("div");
    bubble.className = "chat-bubble " + sender;
    bubble.textContent = text;
    ui.messages.appendChild(bubble);
    ui.messages.scrollTop = ui.messages.scrollHeight;
  }

  function setBadge(ui, count) {
    if (count > 0) {
      ui.badge.style.display = "inline-block";
      ui.badge.textContent = count > 99 ? "99+" : String(count);
    } else {
      ui.badge.style.display = "none";
      ui.badge.textContent = "";
    }
  }

  function setError(ui, message) {
    if (!message) {
      ui.error.style.display = "none";
      ui.error.textContent = "";
      ui.statusText.textContent = "Online";
      return;
    }
    ui.error.style.display = "block";
    ui.error.textContent = message;
    ui.statusText.textContent = "Offline";
  }

  var ui = renderUI();
  var visitorId = localStorage.getItem(STORAGE_KEY) || "";
  var lastId = Number(localStorage.getItem(LAST_ID_KEY) || "0");
  var unreadReplies = 0;
  var initialized = false;

  ui.name.value = localStorage.getItem(NAME_KEY) || "";
  ui.email.value = localStorage.getItem(EMAIL_KEY) || "";

  function syncIdentity() {
    localStorage.setItem(NAME_KEY, ui.name.value.trim());
    localStorage.setItem(EMAIL_KEY, ui.email.value.trim());
    if (!initialized) return Promise.resolve();
    return initSession();
  }

  function initSession() {
    if (window.location.protocol === "file:") {
      setError(ui, "Chat requires a PHP server. Open the site via http://localhost:8000");
      return Promise.resolve();
    }

    return request("init", {
      visitor_id: visitorId,
      name: ui.name.value.trim(),
      email: ui.email.value.trim(),
      page: window.location.pathname + window.location.search,
    }).then(function (res) {
      if (res && res.ok && res.visitor_id) {
        visitorId = res.visitor_id;
        localStorage.setItem(STORAGE_KEY, visitorId);
        initialized = true;
        setError(ui, "");
      }
    }).catch(function () {
      setError(ui, "Chat is currently unavailable. Please try again shortly.");
    });
  }

  function fetchMessages() {
    if (!visitorId) return;
    request("fetch", { visitor_id: visitorId, since_id: lastId }).then(function (res) {
      if (!res || !res.ok || !Array.isArray(res.messages)) return;

      res.messages.forEach(function (m) {
        appendMessage(ui, m.sender, m.body, m.id);
        if (m.sender === "agent" && !ui.widget.classList.contains("open")) {
          unreadReplies += 1;
        }
      });

      if (typeof res.last_id === "number") {
        lastId = res.last_id;
        localStorage.setItem(LAST_ID_KEY, String(lastId));
      }

      setError(ui, "");
      setBadge(ui, unreadReplies);
    }).catch(function () {
      setError(ui, "Unable to sync messages right now.");
    });
  }

  function sendMessage() {
    var msg = ui.input.value.trim();
    if (!msg || !visitorId) return;

    ui.sendBtn.disabled = true;
    request("send", { visitor_id: visitorId, message: msg }).then(function (res) {
      if (res && res.ok) {
        ui.input.value = "";
        appendMessage(ui, "visitor", msg, res.message_id || 0);
        if (typeof res.message_id === "number" && res.message_id > lastId) {
          lastId = res.message_id;
          localStorage.setItem(LAST_ID_KEY, String(lastId));
        }
        setError(ui, "");
      }
    }).catch(function () {
      setError(ui, "Message not sent. Check connection and try again.");
    }).finally(function () {
      ui.sendBtn.disabled = false;
      fetchMessages();
    });
  }

  ui.launcher.addEventListener("click", function () {
    ui.widget.classList.toggle("open");
    if (ui.widget.classList.contains("open")) {
      unreadReplies = 0;
      setBadge(ui, 0);
      ui.input.focus();
      fetchMessages();
    }
  });

  ui.closeBtn.addEventListener("click", function () {
    ui.widget.classList.remove("open");
  });

  ui.sendBtn.addEventListener("click", sendMessage);
  ui.input.addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      sendMessage();
    }
  });

  ui.name.addEventListener("blur", syncIdentity);
  ui.email.addEventListener("blur", syncIdentity);

  initSession().then(function () {
    setTimeout(fetchMessages, 350);
    setInterval(function () {
      initSession().then(fetchMessages);
    }, 5000);
  });
})();
