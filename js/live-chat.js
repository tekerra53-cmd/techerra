(function () {
  var API_URL = "/api/chat";
  var STORAGE_KEY = "techerra_chat_visitor_id";
  var LAST_ID_KEY = "techerra_chat_last_id";
  var NAME_KEY = "techerra_chat_name";
  var EMAIL_KEY = "techerra_chat_email";
  var OPEN_KEY = "techerra_chat_open";
  var PROMPT_SHOWN_KEY = "techerra_chat_prompt_shown";
  var CONFIG = window.TECHERRA_CHAT_CONFIG || {};
  var seenIds = Object.create(null);
  var messageNodes = Object.create(null);
  var pendingVisitorMessageIds = [];

  function el(html) {
    var d = document.createElement("div");
    d.innerHTML = html.trim();
    return d.firstChild;
  }

  function formatTime(value) {
    if (!value) return "";
    var parsed = new Date(String(value).replace(" ", "T") + "Z");
    if (Number.isNaN(parsed.getTime())) return "";
    return parsed.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
  }

  function escapeText(text) {
    return String(text || "").replace(/[&<>"']/g, function (ch) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[ch];
    });
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
      '<button class="chat-launcher" aria-label="Open live chat">' +
      '  <span class="chat-launcher-pulse"></span>' +
      '  <span class="chat-launcher-icon">' +
      '    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v7A2.5 2.5 0 0 1 17.5 15H9l-4.2 4.2A.5.5 0 0 1 4 18.85V15.5A2.5 2.5 0 0 1 1.5 13v-7A2.5 2.5 0 0 1 4 3.5Z" fill="currentColor"/></svg>' +
      "  </span>" +
      '  <span class="chat-launcher-badge" style="display:none"></span>' +
      "</button>"
    );

    var widget = el(
      '<section class="chat-widget" aria-label="Live chat widget">' +
      '  <div class="chat-header">' +
      '    <div class="chat-header-main">' +
      '      <div class="chat-avatar">T</div>' +
      '      <div class="chat-header-copy">' +
      '        <strong class="chat-agent-name"></strong>' +
      '        <div class="chat-status"><span class="chat-status-dot"></span><span class="chat-status-text">Connecting...</span></div>' +
      '      </div>' +
      "    </div>" +
      '    <button class="chat-close" type="button" aria-label="Close">&times;</button>' +
      "  </div>" +
      '  <div class="chat-subheader">' +
      '    <p class="chat-agent-title"></p>' +
      '    <div class="chat-subheader-actions">' +
      '      <button class="chat-end" type="button">End chat</button>' +
      '      <button class="chat-toggle-details" type="button">Update details</button>' +
      "    </div>" +
      "  </div>" +
      '  <div class="chat-identity">' +
      '    <input type="text" placeholder="Your name (required)" class="chat-name" />' +
      '    <input type="email" placeholder="Email (optional)" class="chat-email" />' +
      "  </div>" +
      '  <div class="chat-error"></div>' +
      '  <div class="chat-messages">' +
      '    <div class="chat-welcome-card">' +
      '      <div class="chat-welcome-badge">Live Support</div>' +
      '      <h3>' + escapeText(CONFIG.welcomeTitle || "We are here to help") + '</h3>' +
      '      <p>' + escapeText(CONFIG.welcomeText || "Ask about a project, pricing, or support. Your messages go straight to our team.") + '</p>' +
      '      <div class="chat-quick-replies">' +
      '        <button type="button" data-message="Hi, I want to build a website.">Website Project</button>' +
      '        <button type="button" data-message="I need help with branding and design.">Branding Help</button>' +
      '        <button type="button" data-message="Can I get a quick quote for my project?">Request Quote</button>' +
      "      </div>" +
      "    </div>" +
      '    <div class="chat-thread"></div>' +
      '    <div class="chat-typing" style="display:none"><span></span><span></span><span></span><em>Support is reviewing your message</em></div>' +
      "  </div>" +
      '  <div class="chat-input-wrap">' +
      '    <textarea rows="1" placeholder="Write your message" class="chat-input"></textarea>' +
      '    <button type="button" class="chat-send">Send</button>' +
      "  </div>" +
      '  <div class="chat-note">Average reply: a few minutes during active hours. Send a new message anytime to reopen a closed chat.</div>' +
      "</section>"
    );

    var prompt = el(
      '<button class="chat-prompt" type="button" style="display:none" aria-label="Open help chat">' +
      '  <strong>' + escapeText(CONFIG.promptTitle || "Need help?") + '</strong>' +
      '  <span>' + escapeText(CONFIG.promptText || "We can help with your project or answer questions.") + '</span>' +
      "</button>"
    );

    document.body.appendChild(launcher);
    document.body.appendChild(widget);
    document.body.appendChild(prompt);

    return {
      launcher: launcher,
      widget: widget,
      prompt: prompt,
      badge: launcher.querySelector(".chat-launcher-badge"),
      closeBtn: widget.querySelector(".chat-close"),
      messages: widget.querySelector(".chat-messages"),
      thread: widget.querySelector(".chat-thread"),
      input: widget.querySelector(".chat-input"),
      sendBtn: widget.querySelector(".chat-send"),
      name: widget.querySelector(".chat-name"),
      email: widget.querySelector(".chat-email"),
      error: widget.querySelector(".chat-error"),
      statusText: widget.querySelector(".chat-status-text"),
      agentName: widget.querySelector(".chat-agent-name"),
      agentTitle: widget.querySelector(".chat-agent-title"),
      typing: widget.querySelector(".chat-typing"),
      welcomeCard: widget.querySelector(".chat-welcome-card"),
      identity: widget.querySelector(".chat-identity"),
      endBtn: widget.querySelector(".chat-end"),
      toggleDetails: widget.querySelector(".chat-toggle-details"),
      quickReplies: widget.querySelectorAll(".chat-quick-replies button"),
    };
  }

  function appendMessage(ui, sender, text, id, createdAt) {
    if (id && seenIds[id]) return;
    if (id) seenIds[id] = true;

    if (ui.welcomeCard) {
      ui.welcomeCard.style.display = "none";
    }

    var wrap = document.createElement("div");
    wrap.className = "chat-message-row " + sender;
    wrap.innerHTML =
      '<div class="chat-bubble ' + sender + '">' +
      '  <div class="chat-bubble-text">' + escapeText(text).replace(/\n/g, "<br>") + "</div>" +
      '  <div class="chat-bubble-meta">' + (sender === "agent" ? (CONFIG.agentName || "Support") : "You") + " · " + escapeText(formatTime(createdAt)) + "</div>" +
      "</div>";
    ui.thread.appendChild(wrap);
    ui.messages.scrollTop = ui.messages.scrollHeight;
  }

  function visitorTickMarkup(replied) {
    if (replied) {
      return '<span class="chat-ticks seen" aria-label="Seen">✓✓</span>';
    }
    return '<span class="chat-ticks" aria-label="Sent">✓</span>';
  }

  function updateMessageSeenState(id, replied) {
    if (!id || !messageNodes[id]) return;
    var tick = messageNodes[id].querySelector(".chat-ticks");
    if (!tick) return;
    if (replied) {
      tick.textContent = "✓✓";
      tick.classList.add("seen");
      tick.setAttribute("aria-label", "Seen");
      return;
    }
    tick.textContent = "✓";
    tick.classList.remove("seen");
    tick.setAttribute("aria-label", "Sent");
  }

  function markPendingVisitorMessagesSeen() {
    while (pendingVisitorMessageIds.length) {
      updateMessageSeenState(pendingVisitorMessageIds.shift(), true);
    }
  }

  function appendChatMessage(ui, sender, text, id, createdAt, repliedToVisitor) {
    if (id && seenIds[id]) {
      updateMessageSeenState(id, Boolean(repliedToVisitor));
      return;
    }
    if (id) seenIds[id] = true;

    if (ui.welcomeCard) {
      ui.welcomeCard.style.display = "none";
    }

    var wrap = document.createElement("div");
    wrap.className = "chat-message-row " + sender;
    wrap.innerHTML =
      '<div class="chat-bubble ' + sender + '">' +
      '  <div class="chat-bubble-text">' + escapeText(text).replace(/\n/g, "<br>") + "</div>" +
      '  <div class="chat-bubble-meta">' + (sender === "agent" ? (CONFIG.agentName || "Support") : "You") + " · " + escapeText(formatTime(createdAt)) + (sender === "visitor" ? " " + visitorTickMarkup(Boolean(repliedToVisitor)) : "") + "</div>" +
      "</div>";
    if (id) {
      messageNodes[id] = wrap;
      if (sender === "visitor" && !repliedToVisitor) {
        pendingVisitorMessageIds.push(id);
      }
    }
    ui.thread.appendChild(wrap);
    ui.messages.scrollTop = ui.messages.scrollHeight;
  }

  function setBadge(ui, count) {
    if (count > 0) {
      ui.badge.style.display = "inline-flex";
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
      updateChatStatus(currentStatus);
      return;
    }
    ui.error.style.display = "block";
    ui.error.textContent = message;
    ui.statusText.textContent = "Connection issue";
  }

  function updateChatStatus(status) {
    currentStatus = String(status || "open").toLowerCase();
    if (currentStatus === "solved") {
      ui.statusText.textContent = "Case solved";
      return;
    }
    if (currentStatus === "closed") {
      ui.statusText.textContent = "Chat ended";
      return;
    }
    ui.statusText.textContent = "Online now";
  }

  function autoResize(input) {
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 120) + "px";
  }

  var ui = renderUI();
  var visitorId = localStorage.getItem(STORAGE_KEY) || "";
  var lastId = Number(localStorage.getItem(LAST_ID_KEY) || "0");
  var unreadReplies = 0;
  var initialized = false;
  var pollTimer = 0;
  var proactivePromptTimer = 0;
  var currentStatus = "open";

  ui.name.value = localStorage.getItem(NAME_KEY) || "";
  ui.email.value = localStorage.getItem(EMAIL_KEY) || "";
  ui.agentName.textContent = CONFIG.agentName || "Support";
  ui.agentTitle.textContent = CONFIG.agentTitle || "Usually replies in a few minutes";
  autoResize(ui.input);

  function updateComposerAccess() {
    var hasName = Boolean(ui.name.value.trim());
    ui.sendBtn.disabled = !hasName;
    [].slice.call(ui.quickReplies).forEach(function (button) {
      button.disabled = !hasName;
      button.setAttribute("aria-disabled", hasName ? "false" : "true");
    });
  }

  function syncIdentity() {
    localStorage.setItem(NAME_KEY, ui.name.value.trim());
    localStorage.setItem(EMAIL_KEY, ui.email.value.trim());
    updateComposerAccess();
    if (!initialized) return Promise.resolve();
    return initSession();
  }

  function openWidget() {
    ui.widget.classList.add("open");
    ui.prompt.style.display = "none";
    localStorage.setItem(OPEN_KEY, "1");
    if (window.innerWidth <= 620) {
      document.body.classList.add("te-chat-open");
    }
    unreadReplies = 0;
    setBadge(ui, 0);
    ui.input.focus();
    fetchMessages();
  }

  function closeWidget() {
    ui.widget.classList.remove("open");
    localStorage.setItem(OPEN_KEY, "0");
    document.body.classList.remove("te-chat-open");
  }

  function maybeShowPrompt(isNewVisitor) {
    if (!isNewVisitor) return;
    if (localStorage.getItem(PROMPT_SHOWN_KEY) === "1") return;
    if (ui.widget.classList.contains("open")) return;
    if (proactivePromptTimer) {
      window.clearTimeout(proactivePromptTimer);
    }
    proactivePromptTimer = window.setTimeout(function () {
      if (!ui.widget.classList.contains("open")) {
        ui.prompt.style.display = "flex";
        localStorage.setItem(PROMPT_SHOWN_KEY, "1");
      }
    }, 2200);
  }

  function toggleTyping(show) {
    ui.typing.style.display = show ? "flex" : "none";
    if (show) {
      ui.messages.scrollTop = ui.messages.scrollHeight;
    }
  }

  function initSession() {
    if (window.location.protocol === "file:") {
      setError(ui, "Chat requires the Flask app to be running. Open the site via http://localhost:5000");
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
        updateChatStatus(res.status || "open");
        if (res.agent) {
          CONFIG.agentName = res.agent.name || CONFIG.agentName;
          CONFIG.agentTitle = res.agent.title || CONFIG.agentTitle;
          ui.agentName.textContent = CONFIG.agentName || "Support";
          ui.agentTitle.textContent = CONFIG.agentTitle || "Usually replies in a few minutes";
        }
        maybeShowPrompt(Boolean(res.is_new_visitor));
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

      var newAgentMessage = false;
      res.messages.forEach(function (m) {
        appendChatMessage(ui, m.sender, m.body, m.id, m.created_at, false);
        if (m.sender === "agent") {
          if (!m.is_auto) {
            newAgentMessage = true;
            markPendingVisitorMessagesSeen();
            if (!ui.widget.classList.contains("open")) {
              unreadReplies += 1;
            }
          }
        }
      });

      if (typeof res.last_id === "number") {
        lastId = res.last_id;
        localStorage.setItem(LAST_ID_KEY, String(lastId));
      }

      toggleTyping(false);
      updateChatStatus(res.status || "open");
      setError(ui, "");
      setBadge(ui, unreadReplies);

      if (newAgentMessage && ui.widget.classList.contains("open")) {
        ui.messages.scrollTop = ui.messages.scrollHeight;
      }
    }).catch(function () {
      toggleTyping(false);
      setError(ui, "Unable to sync messages right now.");
    });
  }

  function sendMessage(prefilled) {
    var name = ui.name.value.trim();
    var msg = (typeof prefilled === "string" ? prefilled : ui.input.value).trim();
    if (!name) {
      ui.identity.classList.add("open");
      setError(ui, "Please enter your name before sending a message.");
      ui.name.focus();
      return;
    }
    if (!msg || !visitorId) return;

    localStorage.setItem(NAME_KEY, name);
    localStorage.setItem(EMAIL_KEY, ui.email.value.trim());
    ui.sendBtn.disabled = true;
    toggleTyping(true);
    syncIdentity().then(function () {
      return request("send", { visitor_id: visitorId, message: msg });
    }).then(function (res) {
      if (res && res.ok) {
        ui.input.value = "";
        autoResize(ui.input);
        appendChatMessage(ui, "visitor", msg, res.message_id || 0, new Date().toISOString(), 0);
        updateChatStatus(res.status || "open");
        if (typeof res.message_id === "number" && res.message_id > lastId) {
          lastId = res.message_id;
          localStorage.setItem(LAST_ID_KEY, String(lastId));
        }
        setError(ui, "");
        openWidget();
      }
    }).catch(function () {
      toggleTyping(false);
      setError(ui, "Message not sent. Check connection and try again.");
    }).finally(function () {
      ui.sendBtn.disabled = false;
      fetchMessages();
    });
  }

  ui.launcher.addEventListener("click", function () {
    if (ui.widget.classList.contains("open")) {
      closeWidget();
      return;
    }
    openWidget();
  });

  ui.closeBtn.addEventListener("click", closeWidget);
  ui.endBtn.addEventListener("click", function () {
    if (!visitorId) return;
    request("end_chat", { visitor_id: visitorId }).then(function (res) {
      if (!res || !res.ok) return;
      updateChatStatus(res.status || "closed");
      appendChatMessage(ui, "agent", "This chat has been ended. Send a new message anytime to reopen it.", 0, new Date().toISOString(), 0);
      closeWidget();
    }).catch(function () {
      setError(ui, "Unable to end chat right now.");
    });
  });
  ui.prompt.addEventListener("click", function () {
    openWidget();
  });

  ui.sendBtn.addEventListener("click", function () {
    sendMessage();
  });

  ui.input.addEventListener("input", function () {
    autoResize(ui.input);
  });

  ui.input.addEventListener("keydown", function (e) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  ui.name.addEventListener("input", function () {
    updateComposerAccess();
  });

  ui.toggleDetails.addEventListener("click", function () {
    ui.identity.classList.toggle("open");
  });

  [].slice.call(ui.quickReplies).forEach(function (button) {
    button.addEventListener("click", function () {
      if (!ui.widget.classList.contains("open")) {
        openWidget();
      }
      sendMessage(button.getAttribute("data-message") || "");
    });
  });

  ui.name.addEventListener("blur", syncIdentity);
  ui.email.addEventListener("blur", syncIdentity);

  initSession().then(function () {
    updateComposerAccess();
    if (localStorage.getItem(OPEN_KEY) === "1") {
      openWidget();
    }
    setTimeout(fetchMessages, 350);
    pollTimer = window.setInterval(function () {
      initSession().then(fetchMessages);
    }, 5000);
  });
})();
