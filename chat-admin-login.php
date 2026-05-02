<?php
require_once __DIR__ . '/chat-security.php';
techerra_block_if_not_allowed();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TechErra Admin Login</title>
  <style>
    :root {
      --bg1: #eff4fb;
      --bg2: #dbe8f6;
      --panel: #ffffff;
      --text: #0f2238;
      --muted: #5c738e;
      --brand: #0f4c81;
      --line: #d6e2ef;
      --danger: #b42318;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: "Segoe UI", sans-serif;
      color: var(--text);
      background: radial-gradient(circle at 15% 10%, var(--bg2), var(--bg1) 52%);
      display: grid;
      place-items: center;
      padding: 18px;
    }
    .card {
      width: min(420px, 100%);
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 14px;
      box-shadow: 0 18px 45px rgba(16, 51, 80, 0.17);
      padding: 24px;
    }
    h1 {
      margin: 0 0 8px;
      font-size: 24px;
      line-height: 1.2;
    }
    p {
      margin: 0 0 18px;
      color: var(--muted);
      font-size: 14px;
    }
    label {
      display: block;
      margin-bottom: 8px;
      font-size: 13px;
      font-weight: 600;
    }
    input {
      width: 100%;
      border: 1px solid #c9d7e7;
      border-radius: 10px;
      padding: 12px;
      font-size: 14px;
      margin-bottom: 12px;
    }
    button {
      width: 100%;
      border: 0;
      border-radius: 10px;
      padding: 12px;
      background: var(--brand);
      color: #fff;
      font-size: 14px;
      cursor: pointer;
    }
    button:disabled { opacity: 0.7; cursor: not-allowed; }
    .error {
      display: none;
      margin-top: 12px;
      border: 1px solid #ffd2cc;
      background: #fff4f3;
      color: var(--danger);
      border-radius: 8px;
      padding: 8px 10px;
      font-size: 13px;
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Admin Login</h1>
    <p>Sign in to manage visitor chats and notifications.</p>
    <label for="password">Password</label>
    <input id="password" type="password" autocomplete="current-password" />
    <button id="loginBtn" type="button">Sign In</button>
    <div class="error" id="error"></div>
  </div>

  <script>
    (function () {
      var API = "chat-api.php";
      var btn = document.getElementById("loginBtn");
      var input = document.getElementById("password");
      var error = document.getElementById("error");

      function showError(msg) {
        if (!msg) {
          error.style.display = "none";
          error.textContent = "";
          return;
        }
        error.style.display = "block";
        error.textContent = msg;
      }

      function req(action, payload) {
        return fetch(API, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(Object.assign({ action: action }, payload || {}))
        }).then(function (r) { return r.json(); });
      }

      function checkAuth() {
        req("admin_status").then(function (res) {
          if (res && res.ok && res.authenticated) {
            window.location.href = "chat-admin.php";
          }
        });
      }

      function login() {
        var password = input.value.trim();
        if (!password) {
          showError("Enter your admin password.");
          return;
        }
        btn.disabled = true;
        showError("");

        req("admin_login", { password: password }).then(function (res) {
          if (res && res.ok) {
            window.location.href = "chat-admin.php";
            return;
          }
          showError((res && res.error) || "Login failed.");
        }).catch(function () {
          showError("Unable to connect to chat API.");
        }).finally(function () {
          btn.disabled = false;
        });
      }

      btn.addEventListener("click", login);
      input.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          login();
        }
      });

      checkAuth();
      input.focus();
    })();
  </script>
</body>
</html>
