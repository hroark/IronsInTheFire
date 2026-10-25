(function () {
  var cryptoApi = window.JournalCrypto;
  var gate = document.getElementById("journal-gate");
  if (!gate || !cryptoApi) {
    return;
  }

  var gateTitle = document.getElementById("journal-gate-title");
  var gateCopy = document.getElementById("journal-gate-copy");
  var gateForm = document.getElementById("journal-gate-form");
  var passwordInput = document.getElementById("journal-password");
  var confirmWrap = document.getElementById("journal-confirm-wrap");
  var confirmInput = document.getElementById("journal-confirm");
  var errorEl = document.getElementById("journal-error");
  var submitButton = document.getElementById("journal-submit");
  var lockButton = document.getElementById("journal-lock");
  var app = document.getElementById("journal-app");
  var listEl = document.getElementById("journal-list");
  var emptyEl = document.getElementById("journal-empty");
  var editor = document.getElementById("journal-editor");
  var titleInput = document.getElementById("journal-title");
  var bodyInput = document.getElementById("journal-body");
  var statusEl = document.getElementById("journal-status");
  var state = { key: null, configured: false, entries: [], selected: null };

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }

  function showError(message) {
    errorEl.hidden = !message;
    errorEl.textContent = message || "";
  }

  function request(url, options) {
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        if (!response.ok || data.ok === false) {
          throw new Error(data.error || "The journal could not be saved.");
        }
        return data;
      });
    });
  }

  function post(action, fields) {
    var body = new URLSearchParams();
    body.set("csrf", csrf());
    body.set("action", action);
    Object.keys(fields).forEach(function (key) {
      body.set(key, String(fields[key]));
    });
    return request("index.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-Requested-With": "XMLHttpRequest"
      },
      body: body.toString(),
      credentials: "same-origin"
    });
  }

  function setGate(creating) {
    gate.hidden = false;
    app.hidden = true;
    lockButton.hidden = true;
    confirmWrap.hidden = !creating;
    confirmInput.required = creating;
    gateTitle.textContent = creating ? "Choose a journal password" : "Unlock your journal";
    gateCopy.textContent = creating
      ? "Choose a password that is different from the notes sign-in. It stays in this browser."
      : "Enter the journal password. It is not sent to the server.";
    submitButton.textContent = creating ? "Create journal" : "Unlock";
    passwordInput.value = "";
    confirmInput.value = "";
    showError("");
  }

  function clearPrivateText() {
    state.key = null;
    state.entries = [];
    state.selected = null;
    listEl.innerHTML = "";
    titleInput.value = "";
    bodyInput.value = "";
    editor.hidden = true;
  }

  function renderList() {
    listEl.innerHTML = "";
    emptyEl.hidden = state.entries.length > 0;
    state.entries.forEach(function (entry) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "notes-card journal-entry" + (state.selected === entry ? " is-active" : "");
      button.textContent = entry.title || "Untitled entry";
      button.addEventListener("click", function () {
        state.selected = entry;
        titleInput.value = entry.title;
        bodyInput.value = entry.body;
        editor.hidden = false;
        statusEl.textContent = "Encrypted before it is saved.";
        renderList();
      });
      listEl.appendChild(button);
    });
  }

  function showEntries() {
    gate.hidden = true;
    app.hidden = false;
    lockButton.hidden = false;
    renderList();
  }

  async function openWithPassword(password, creating, stored) {
    var salt = creating ? crypto.getRandomValues(new Uint8Array(16)) : cryptoApi.base64ToBytes(stored.salt);
    var iterations = creating ? cryptoApi.ITERATIONS : stored.iterations;
    var key = await cryptoApi.deriveKey(password, salt, iterations);
    passwordInput.value = "";
    confirmInput.value = "";
    if (creating) {
      var verifier = await cryptoApi.encryptJson(key, { check: cryptoApi.CHECK });
      await post("journal-seal", {
        iterations: iterations,
        salt: cryptoApi.bytesToBase64(salt),
        verifierIv: verifier.iv,
        verifier: verifier.ciphertext
      });
      state.configured = true;
    } else {
      var opened;
      try {
        opened = await cryptoApi.decryptJson(key, stored.verifierIv, stored.verifier);
      } catch (decryptError) {
        throw new Error("That journal password is not right.");
      }
      if (!opened || opened.check !== cryptoApi.CHECK) {
        throw new Error("That journal password is not right.");
      }
      var entries = [];
      for (var index = 0; index < stored.entries.length; index += 1) {
        var row = stored.entries[index];
        var plain;
        try {
          plain = await cryptoApi.decryptJson(key, row.iv, row.ciphertext);
        } catch (decryptError) {
          throw new Error("A saved journal entry could not be opened.");
        }
        entries.push({
          id: row.id,
          title: String((plain && plain.title) || ""),
          body: String((plain && plain.body) || ""),
          updatedAt: row.updatedAt
        });
      }
      state.entries = entries;
    }
    state.key = key;
    showEntries();
  }

  gateForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var password = passwordInput.value;
    if (password.length < 8) {
      showError("Use at least 8 characters.");
      return;
    }
    var creating = !state.configured;
    if (creating && password !== confirmInput.value) {
      showError("Those journal passwords do not match.");
      return;
    }
    submitButton.disabled = true;
    request("index.php?action=journal-data", {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin"
    }).then(function (stored) {
      return openWithPassword(password, creating, stored);
    }).catch(function (error) {
      state.key = null;
      showError(error.message || "The journal could not be opened.");
    }).finally(function () {
      submitButton.disabled = false;
    });
  });

  lockButton.addEventListener("click", function () {
    clearPrivateText();
    setGate(!state.configured);
  });

  document.getElementById("journal-new").addEventListener("click", function () {
    state.selected = { id: 0, title: "", body: "" };
    titleInput.value = "";
    bodyInput.value = "";
    editor.hidden = false;
    statusEl.textContent = "New entry. Save encrypts it before it leaves this browser.";
    titleInput.focus();
  });

  editor.addEventListener("submit", function (event) {
    event.preventDefault();
    if (!state.key || !state.selected) {
      return;
    }
    var entry = state.selected;
    entry.title = titleInput.value.trim();
    entry.body = bodyInput.value;
    statusEl.textContent = "Encrypting…";
    cryptoApi.encryptJson(state.key, { title: entry.title, body: entry.body }).then(function (sealed) {
      return post("journal-save", { id: entry.id || 0, iv: sealed.iv, ciphertext: sealed.ciphertext });
    }).then(function (saved) {
      entry.id = saved.id;
      entry.updatedAt = saved.updatedAt;
      if (state.entries.indexOf(entry) === -1) {
        state.entries.unshift(entry);
      }
      statusEl.textContent = "Saved as ciphertext.";
      renderList();
    }).catch(function (error) {
      statusEl.textContent = error.message || "The journal entry could not be saved.";
    });
  });

  document.getElementById("journal-delete").addEventListener("click", function () {
    if (!state.selected) {
      return;
    }
    var entry = state.selected;
    var finish = function () {
      state.entries = state.entries.filter(function (candidate) {
        return candidate !== entry;
      });
      state.selected = null;
      editor.hidden = true;
      titleInput.value = "";
      bodyInput.value = "";
      renderList();
    };
    if (!entry.id) {
      finish();
      return;
    }
    post("journal-delete", { id: entry.id }).then(finish).catch(function (error) {
      statusEl.textContent = error.message || "The journal entry could not be deleted.";
    });
  });

  request("index.php?action=journal-data", {
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  }).then(function (stored) {
    state.configured = !!stored.configured;
    setGate(!state.configured);
  }).catch(function (error) {
    setGate(false);
    showError(error.message || "The journal could not be opened.");
  });
})();
