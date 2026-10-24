(function () {
  const gate = document.querySelector("#gate");
  const gateTitle = document.querySelector("#gate-title");
  const gateCopy = document.querySelector("#gate-copy");
  const gateForm = document.querySelector("#gate-form");
  const gatePassphrase = document.querySelector("#gate-passphrase");
  const gateConfirmWrap = document.querySelector("#gate-confirm-wrap");
  const gateConfirm = document.querySelector("#gate-confirm");
  const gateSubmit = document.querySelector("#gate-submit");
  const gateError = document.querySelector("#gate-error");
  const gateToggle = document.querySelector("#gate-toggle");
  const app = document.querySelector("#app");
  const searchInput = document.querySelector("#log-search");
  const listEl = document.querySelector("#log-list");
  const emptyList = document.querySelector("#log-empty");
  const editorEl = document.querySelector("#editor");
  const filterButtons = Array.from(document.querySelectorAll("[data-filter]"));
  const saveState = document.querySelector("#save-state");
  const backupPanel = document.querySelector("#backup-panel");
  const importInput = document.querySelector("#import-file");

  const state = {
    log: { entries: [] },
    selectedId: null,
    filter: "all",
    query: "",
    saveTimer: 0,
    saving: false,
    editorKey: "",
    confirmErase: false,
    confirmDeleteId: ""
  };

  const dirty = new Set();
  const removed = new Set();
  const sendingSaves = new Map();
  const sendingDeletes = new Set();
  let persistChain = Promise.resolve();

  async function api(path, options) {
    const response = await fetch(path, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      ...options
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.error || "The notes could not be reached.");
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function now() {
    return Date.now();
  }

  function createId() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return "id-" + now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
  }

  function blankLog() {
    return { entries: [] };
  }

  function normalizeItem(item) {
    if (!item || typeof item.text !== "string") return null;
    return {
      id: typeof item.id === "string" && item.id ? item.id : createId(),
      text: item.text.slice(0, 500),
      done: Boolean(item.done)
    };
  }

  function normalizeEntry(entry) {
    if (!entry || (entry.type !== "note" && entry.type !== "todo")) return null;
    const createdAt = Number(entry.createdAt) || now();
    const base = {
      id: typeof entry.id === "string" && entry.id ? entry.id : createId(),
      type: entry.type,
      title: String(entry.title || "").slice(0, 160),
      createdAt,
      updatedAt: Number(entry.updatedAt) || createdAt
    };
    if (entry.type === "note") {
      return {
        ...base,
        format: entry.format === "markdown" ? "markdown" : "plain",
        body: String(entry.body || "").slice(0, 200000)
      };
    }
    const items = Array.isArray(entry.items) ? entry.items.map(normalizeItem).filter(Boolean).slice(0, 200) : [];
    return { ...base, items };
  }

  function normalizeLog(value) {
    if (!value || !Array.isArray(value.entries)) {
      throw new Error("That file is not a log backup.");
    }
    return { entries: value.entries.map(normalizeEntry).filter(Boolean) };
  }

  function selectedEntry() {
    return state.log.entries.find((entry) => entry.id === state.selectedId) || null;
  }

  function touch(entry) {
    entry.updatedAt = now();
  }

  function setGateError(message) {
    gateError.textContent = message || "";
    gateError.hidden = !message;
  }

  function setSaveState(text) {
    saveState.textContent = text;
  }

  function showGate(mode) {
    app.hidden = true;
    gate.hidden = false;
    setGateError("");
    gatePassphrase.value = "";
    gateConfirm.value = "";
    const creating = mode === "create";
    gateTitle.textContent = creating ? "Create your passphrase" : "Unlock your notes";
    gateCopy.textContent = creating
      ? "Choose a passphrase for this log. Notes and lists are saved in your MySQL database."
      : "Enter your passphrase to open the notes saved in your database.";
    gateForm.hidden = false;
    gateConfirmWrap.hidden = !creating;
    gateConfirm.required = creating;
    gateSubmit.textContent = creating ? "Create log" : "Unlock";
    gate.dataset.mode = mode;
    gatePassphrase.focus();
  }

  async function showApp() {
    gate.hidden = true;
    app.hidden = false;
    try {
      await loadJournalStatus();
    } catch (error) {
      journal.configured = false;
    }
    render();
    searchInput.focus();
  }

  function visibleEntries() {
    const query = state.query.trim().toLowerCase();
    return state.log.entries
      .filter((entry) => state.filter === "all" || entry.type === state.filter)
      .filter((entry) => {
        if (!query) return true;
        const haystack =
          entry.type === "note"
            ? entry.title + "\n" + entry.body
            : entry.title + "\n" + entry.items.map((item) => item.text).join("\n");
        return haystack.toLowerCase().includes(query);
      })
      .sort((a, b) => b.updatedAt - a.updatedAt);
  }

  function formatUpdated(timestamp) {
    const minutes = Math.round((now() - timestamp) / 60000);
    if (minutes < 1) return "Updated just now";
    if (minutes < 60) return "Updated " + minutes + "m ago";
    const hours = Math.round(minutes / 60);
    if (hours < 24) return "Updated " + hours + "h ago";
    const days = Math.round(hours / 24);
    if (days < 7) return "Updated " + days + "d ago";
    return (
      "Updated " +
      new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric" }).format(new Date(timestamp))
    );
  }

  function previewText(entry) {
    if (entry.type === "todo") {
      const done = entry.items.filter((item) => item.done).length;
      if (!entry.items.length) return "No items yet";
      return done + " of " + entry.items.length + " done";
    }
    const text = entry.body
      .replace(/^#{1,3}\s+/gm, "")
      .replace(/[*_`]/g, "")
      .replace(/\s+/g, " ")
      .trim();
    return text || "Empty note";
  }

  function queueFlush() {
    state.saving = true;
    setSaveState("Saving…");
    window.clearTimeout(state.saveTimer);
    state.saveTimer = window.setTimeout(() => persistNow(), 280);
  }

  function scheduleSave() {
    const entry = selectedEntry();
    if (!entry) return;
    removed.delete(entry.id);
    dirty.add(entry.id);
    queueFlush();
  }

  function markRemoved(id) {
    dirty.delete(id);
    removed.add(id);
    queueFlush();
  }

  async function flushOnce() {
    const saveIds = [...dirty];
    const deleteIds = [...removed];
    dirty.clear();
    removed.clear();
    saveIds.forEach((id) => {
      const entry = state.log.entries.find((candidate) => candidate.id === id);
      if (entry) sendingSaves.set(id, JSON.parse(JSON.stringify(entry)));
    });
    deleteIds.forEach((id) => {
      sendingSaves.delete(id);
      sendingDeletes.add(id);
    });
    if (!saveIds.length && !deleteIds.length) return;
    try {
      for (const id of deleteIds) {
        await api("/notes/api/entries/" + encodeURIComponent(id), { method: "DELETE" });
        sendingDeletes.delete(id);
      }
      for (const id of saveIds) {
        const snapshot = sendingSaves.get(id);
        if (!snapshot || sendingDeletes.has(id)) continue;
        await api("/notes/api/entries/" + encodeURIComponent(id), {
          method: "PUT",
          body: JSON.stringify(snapshot)
        });
        if (!dirty.has(id)) sendingSaves.delete(id);
      }
      state.saving = dirty.size > 0 || removed.size > 0 || sendingSaves.size > 0 || sendingDeletes.size > 0;
      if (!state.saving) setSaveState("Saved");
    } catch (error) {
      saveIds.forEach((id) => {
        if (sendingSaves.has(id)) dirty.add(id);
      });
      deleteIds.forEach((id) => {
        if (sendingDeletes.has(id)) removed.add(id);
      });
      state.saving = false;
      setSaveState(error.message || "Could not save");
      throw error;
    }
  }

  function persistNow() {
    const run = persistChain.then(flushOnce);
    persistChain = run.catch(() => {});
    return run;
  }

  async function settleSaves() {
    window.clearTimeout(state.saveTimer);
    await persistNow();
  }

  const journal = {
    configured: false,
    entryCount: 0,
    key: null,
    entries: [],
    dirty: new Set(),
    removed: new Set(),
    sending: new Set(),
    timer: 0,
    chain: Promise.resolve()
  };

  function journalEntry() {
    return journal.entries.find((entry) => entry.id === state.selectedId) || null;
  }

  async function loadJournalStatus() {
    const status = await api("/notes/api/journal");
    journal.configured = Boolean(status.configured);
    journal.entryCount = Number(status.entryCount) || 0;
  }

  function forgetJournal() {
    window.clearTimeout(journal.timer);
    journal.key = null;
    journal.entries = [];
    journal.dirty.clear();
    journal.removed.clear();
    journal.sending.clear();
  }

  async function sealJournalEntry(entry) {
    const sealed = await JournalCrypto.encryptJson(journal.key, {
      title: entry.title,
      body: entry.body,
      format: entry.format
    });
    await api("/notes/api/journal/entries/" + encodeURIComponent(entry.id), {
      method: "PUT",
      body: JSON.stringify({
        iv: sealed.iv,
        ciphertext: sealed.ciphertext,
        createdAt: entry.createdAt,
        updatedAt: entry.updatedAt
      })
    });
  }

  async function flushJournalOnce() {
    if (!journal.key) return;
    const saveIds = [...journal.dirty];
    const deleteIds = [...journal.removed];
    saveIds.forEach((id) => journal.sending.add(id));
    deleteIds.forEach((id) => journal.sending.add(id));
    journal.dirty.clear();
    journal.removed.clear();
    if (!saveIds.length && !deleteIds.length) return;
    try {
      for (const id of deleteIds) {
        await api("/notes/api/journal/entries/" + encodeURIComponent(id), { method: "DELETE" });
        journal.sending.delete(id);
      }
      for (const id of saveIds) {
        const entry = journal.entries.find((candidate) => candidate.id === id);
        if (!entry) {
          journal.sending.delete(id);
          continue;
        }
        await sealJournalEntry(entry);
        if (!journal.dirty.has(id)) journal.sending.delete(id);
      }
      journal.entryCount = journal.entries.length;
      state.saving =
        journal.dirty.size > 0 ||
        journal.removed.size > 0 ||
        journal.sending.size > 0 ||
        dirty.size > 0 ||
        removed.size > 0 ||
        sendingSaves.size > 0 ||
        sendingDeletes.size > 0;
      if (!state.saving) setSaveState("Saved");
    } catch (error) {
      saveIds.forEach((id) => {
        if (journal.sending.has(id)) journal.dirty.add(id);
      });
      deleteIds.forEach((id) => {
        if (journal.sending.has(id)) journal.removed.add(id);
      });
      state.saving = false;
      setSaveState(error.message || "Could not save the journal");
      throw error;
    }
  }

  function persistJournal() {
    const run = journal.chain.then(flushJournalOnce);
    journal.chain = run.catch(() => {});
    return run;
  }

  function scheduleJournalSave() {
    state.saving = true;
    setSaveState("Saving…");
    window.clearTimeout(journal.timer);
    journal.timer = window.setTimeout(() => persistJournal(), 280);
  }

  function markJournalDirty(entry) {
    journal.removed.delete(entry.id);
    journal.dirty.add(entry.id);
    scheduleJournalSave();
  }

  async function settleJournal() {
    window.clearTimeout(journal.timer);
    await persistJournal();
  }

  async function openJournal(password) {
    const seal = await api("/notes/api/journal/seal");
    const key = await JournalCrypto.deriveKey(password, JournalCrypto.base64ToBytes(seal.salt), seal.iterations);
    let check;
    try {
      check = await JournalCrypto.decryptJson(key, seal.verifierIv, seal.verifier);
    } catch (error) {
      throw new Error("That journal password is not right.");
    }
    if (!check || check.check !== JournalCrypto.CHECK) {
      throw new Error("That journal password is not right.");
    }
    const stored = await api("/notes/api/journal/entries");
    const entries = [];
    for (const row of stored.entries) {
      const payload = await JournalCrypto.decryptJson(key, row.iv, row.ciphertext);
      entries.push({
        id: row.id,
        title: String(payload.title || "").slice(0, 160),
        body: String(payload.body || ""),
        format: payload.format === "markdown" ? "markdown" : "plain",
        createdAt: Number(row.createdAt),
        updatedAt: Number(row.updatedAt)
      });
    }
    journal.key = key;
    journal.entries = entries;
    journal.entryCount = entries.length;
    journal.configured = true;
  }

  async function createJournal(password) {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const key = await JournalCrypto.deriveKey(password, salt, JournalCrypto.ITERATIONS);
    const verifier = await JournalCrypto.encryptJson(key, { check: JournalCrypto.CHECK });
    await api("/notes/api/journal/seal", {
      method: "PUT",
      body: JSON.stringify({
        iterations: JournalCrypto.ITERATIONS,
        salt: JournalCrypto.bytesToBase64(salt),
        verifierIv: verifier.iv,
        verifier: verifier.ciphertext
      })
    });
    journal.key = key;
    journal.entries = [];
    journal.entryCount = 0;
    journal.configured = true;
  }

  async function changeJournalPassword(nextPassword) {
    await settleJournal();
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const key = await JournalCrypto.deriveKey(nextPassword, salt, JournalCrypto.ITERATIONS);
    const verifier = await JournalCrypto.encryptJson(key, { check: JournalCrypto.CHECK });
    const entries = [];
    for (const entry of journal.entries) {
      const sealed = await JournalCrypto.encryptJson(key, {
        title: entry.title,
        body: entry.body,
        format: entry.format
      });
      entries.push({
        id: entry.id,
        iv: sealed.iv,
        ciphertext: sealed.ciphertext,
        createdAt: entry.createdAt,
        updatedAt: entry.updatedAt
      });
    }
    await api("/notes/api/journal/rekey", {
      method: "PUT",
      body: JSON.stringify({
        seal: {
          iterations: JournalCrypto.ITERATIONS,
          salt: JournalCrypto.bytesToBase64(salt),
          verifierIv: verifier.iv,
          verifier: verifier.ciphertext
        },
        entries
      })
    });
    journal.key = key;
  }

  function addJournalEntry() {
    const entry = {
      id: createId(),
      title: "",
      body: "",
      format: "plain",
      createdAt: now(),
      updatedAt: now()
    };
    journal.entries.unshift(entry);
    journal.entryCount = journal.entries.length;
    state.selectedId = entry.id;
    state.confirmDeleteId = "";
    state.editorKey = "";
    state.filter = "journal";
    markJournalDirty(entry);
    render();
  }

  function journalGate(mode) {
    const card = document.createElement("div");
    card.className = "log-journal-card";
    const title = document.createElement("h2");
    title.className = "section-title";
    title.textContent = mode === "create" ? "Start an encrypted journal" : "Unlock your journal";
    const copy = document.createElement("p");
    copy.className = "section-copy";
    copy.textContent = mode === "create"
      ? "Choose a journal password that is different from the log passphrase. Entries are encrypted in this browser, and the password is never saved or sent to the server."
      : "This password is only for the diary. The log passphrase cannot open these entries.";
    const form = document.createElement("form");
    form.className = "form-stack";
    form.dataset.journalAction = "journal-password";
    form.dataset.mode = mode;
    const passwordLabel = document.createElement("label");
    passwordLabel.className = "form-label";
    passwordLabel.htmlFor = "journal-password";
    passwordLabel.textContent = "Journal password";
    const password = document.createElement("input");
    password.id = "journal-password";
    password.className = "form-input";
    password.type = "password";
    password.autocomplete = mode === "create" ? "new-password" : "current-password";
    password.minLength = 8;
    password.required = true;
    form.append(passwordLabel, password);
    if (mode === "create") {
      const confirmLabel = document.createElement("label");
      confirmLabel.className = "form-label";
      confirmLabel.htmlFor = "journal-password-confirm";
      confirmLabel.textContent = "Confirm journal password";
      const confirm = document.createElement("input");
      confirm.id = "journal-password-confirm";
      confirm.className = "form-input";
      confirm.type = "password";
      confirm.autocomplete = "new-password";
      confirm.minLength = 8;
      confirm.required = true;
      form.append(confirmLabel, confirm);
    }
    const error = document.createElement("p");
    error.id = "journal-error";
    error.className = "log-error";
    error.hidden = true;
    const submit = document.createElement("button");
    submit.className = "btn btn-brand";
    submit.type = "submit";
    submit.textContent = mode === "create" ? "Create journal" : "Unlock journal";
    form.append(error, submit);
    card.append(title, copy, form);
    return card;
  }

  function renderJournalEntry(entry) {
    const header = document.createElement("div");
    header.className = "log-editor-header";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-brand-outline btn-small log-back";
    back.dataset.action = "back";
    back.textContent = "All entries";
    const titleField = document.createElement("div");
    titleField.className = "log-title-field";
    const titleLabel = document.createElement("label");
    titleLabel.htmlFor = "entry-title";
    titleLabel.textContent = "Title";
    const titleInput = document.createElement("input");
    titleInput.id = "entry-title";
    titleInput.className = "form-input log-entry-title";
    titleInput.maxLength = 160;
    titleInput.value = entry.title;
    titleInput.placeholder = "Diary title";
    titleField.append(titleLabel, titleInput);
    const actions = document.createElement("div");
    actions.className = "log-editor-actions";
    const lock = document.createElement("button");
    lock.type = "button";
    lock.className = "btn btn-brand-outline btn-small";
    lock.dataset.action = "lock-journal";
    lock.textContent = "Lock journal";
    actions.append(lock);
    if (state.confirmDeleteId === entry.id) {
      const cancel = document.createElement("button");
      cancel.type = "button";
      cancel.className = "btn btn-brand-outline btn-small";
      cancel.dataset.action = "cancel-delete";
      cancel.textContent = "Keep";
      const confirm = document.createElement("button");
      confirm.type = "button";
      confirm.className = "btn btn-danger btn-small";
      confirm.dataset.action = "confirm-delete";
      confirm.textContent = "Delete";
      actions.append(cancel, confirm);
    } else {
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "btn btn-brand-outline btn-small";
      remove.dataset.action = "delete-entry";
      remove.textContent = "Delete";
      actions.append(remove);
    }
    header.append(back, titleField, actions);
    const note = renderNoteEditor(entry);
    const passwordForm = document.createElement("form");
    passwordForm.className = "form-stack log-journal-card";
    passwordForm.dataset.journalAction = "change-journal-password";
    passwordForm.innerHTML = "<p class=\"log-hint\">Changing the journal password re-encrypts every diary entry. The new password stays in this browser.</p>";
    const nextLabel = document.createElement("label");
    nextLabel.className = "form-label";
    nextLabel.htmlFor = "journal-next-password";
    nextLabel.textContent = "New journal password";
    const next = document.createElement("input");
    next.id = "journal-next-password";
    next.className = "form-input";
    next.type = "password";
    next.autocomplete = "new-password";
    next.minLength = 8;
    next.required = true;
    const againLabel = document.createElement("label");
    againLabel.className = "form-label";
    againLabel.htmlFor = "journal-next-password-confirm";
    againLabel.textContent = "Confirm new journal password";
    const again = document.createElement("input");
    again.id = "journal-next-password-confirm";
    again.className = "form-input";
    again.type = "password";
    again.autocomplete = "new-password";
    again.minLength = 8;
    again.required = true;
    const change = document.createElement("button");
    change.type = "submit";
    change.className = "btn btn-brand-outline btn-small";
    change.textContent = "Change journal password";
    const message = document.createElement("p");
    message.id = "journal-password-message";
    message.className = "log-hint";
    passwordForm.append(nextLabel, next, againLabel, again, change, message);
    const wrap = document.createElement("div");
    wrap.append(header, note, passwordForm);
    return wrap;
  }

  function renderJournal() {
    const unlocked = Boolean(journal.key);
    const query = state.query.trim().toLowerCase();
    const entries = unlocked
      ? journal.entries
          .filter((entry) => !query || (entry.title + "\n" + entry.body).toLowerCase().includes(query))
          .sort((a, b) => b.updatedAt - a.updatedAt)
      : [];
    listEl.innerHTML = "";
    emptyList.hidden = entries.length > 0;
    if (!unlocked) {
      emptyList.hidden = false;
      emptyList.textContent = journal.configured
        ? journal.entryCount + (journal.entryCount === 1 ? " encrypted entry is locked." : " encrypted entries are locked.")
        : "The journal does not have a password yet.";
    } else if (!entries.length) {
      emptyList.textContent = journal.entries.length ? "Nothing matches that search." : "No diary entries yet.";
    }

    entries.forEach((entry) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "log-entry" + (entry.id === state.selectedId ? " is-active" : "");
      button.dataset.id = entry.id;
      const kicker = document.createElement("span");
      kicker.className = "log-kicker";
      kicker.textContent = "Journal";
      const title = document.createElement("strong");
      title.textContent = entry.title.trim() || "Untitled entry";
      const meta = document.createElement("span");
      meta.className = "log-entry-meta";
      meta.textContent = previewText(entry) + " · " + formatUpdated(entry.updatedAt);
      button.append(kicker, title, meta);
      listEl.append(button);
    });

    const entry = unlocked ? journalEntry() : null;
    const mode = !journal.configured ? "create" : unlocked ? "edit" : "unlock";
    const signature = "journal:" + mode + ":" + (entry ? entry.id : "") + ":" + state.confirmDeleteId;
    if (signature === state.editorKey) return;
    state.editorKey = signature;
    editorEl.innerHTML = "";
    if (mode !== "edit") {
      editorEl.append(journalGate(mode));
      const field = editorEl.querySelector("#journal-password");
      if (field) field.focus();
      return;
    }
    if (!entry) {
      const empty = document.createElement("div");
      empty.className = "log-editor-empty";
      const heading = document.createElement("h2");
      heading.textContent = "Encrypted journal";
      const message = document.createElement("p");
      message.textContent = "Diary entries are encrypted before they are saved. Lock the journal when you step away.";
      const lock = document.createElement("button");
      lock.type = "button";
      lock.className = "btn btn-brand-outline";
      lock.dataset.action = "lock-journal";
      lock.textContent = "Lock journal";
      empty.append(heading, message, lock);
      editorEl.append(empty);
      return;
    }
    editorEl.append(renderJournalEntry(entry));
    if (!entry.title) {
      const title = editorEl.querySelector("#entry-title");
      if (title) title.focus();
    }
  }

  function renderFilters() {
    const counts = {
      all: state.log.entries.length,
      note: state.log.entries.filter((entry) => entry.type === "note").length,
      todo: state.log.entries.filter((entry) => entry.type === "todo").length,
      journal: journal.key ? journal.entries.length : journal.entryCount
    };
    filterButtons.forEach((button) => {
      const filter = button.dataset.filter;
      const label = filter === "all" ? "All" : filter === "note" ? "Notes" : filter === "todo" ? "Todos" : "Journal";
      button.textContent = label + " " + counts[filter];
      button.classList.toggle("is-active", state.filter === filter);
      button.setAttribute("aria-pressed", state.filter === filter ? "true" : "false");
    });
  }

  function renderList() {
    const entries = visibleEntries();
    listEl.innerHTML = "";
    emptyList.hidden = entries.length > 0;
    emptyList.textContent = state.log.entries.length
      ? "Nothing matches that search."
      : "Nothing here yet. Create a note or a todo list.";

    entries.forEach((entry) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "log-entry" + (entry.id === state.selectedId ? " is-active" : "");
      button.dataset.id = entry.id;

      const kicker = document.createElement("span");
      kicker.className = "log-kicker";
      kicker.textContent = entry.type === "note" ? (entry.format === "markdown" ? "Markdown" : "Note") : "Todo";

      const title = document.createElement("strong");
      title.textContent = entry.title.trim() || (entry.type === "note" ? "Untitled note" : "Untitled list");

      const meta = document.createElement("span");
      meta.className = "log-entry-meta";
      meta.textContent = previewText(entry) + " · " + formatUpdated(entry.updatedAt);

      button.append(kicker, title, meta);
      listEl.append(button);
    });
  }

  function editorSignature(entry) {
    if (!entry) return "empty";
    if (entry.type === "note") return entry.id + ":note";
    return entry.id + ":todo:" + entry.items.map((item) => item.id).join(",");
  }

  function renderPreview(entry) {
    const preview = editorEl.querySelector("#note-preview");
    if (!preview) return;
    preview.innerHTML = entry.body.trim()
      ? LogMarkdown.render(entry.body)
      : '<p class="log-preview-empty">Markdown preview will show up here.</p>';
  }

  function renderEditor(force) {
    const entry = selectedEntry();
    const signature = editorSignature(entry) + ":" + state.confirmDeleteId;
    if (!force && signature === state.editorKey) return;
    state.editorKey = signature;
    editorEl.innerHTML = "";

    if (!entry) {
      const empty = document.createElement("div");
      empty.className = "log-editor-empty";
      empty.innerHTML =
        "<h2>Your central log</h2><p>Write a plain note, a markdown note, or a short todo list. Entries are saved to your database.</p>";
      editorEl.append(empty);
      return;
    }

    const header = document.createElement("div");
    header.className = "log-editor-header";
    const back = document.createElement("button");
    back.type = "button";
    back.className = "btn btn-brand-outline btn-small log-back";
    back.dataset.action = "back";
    back.textContent = "All entries";
    const titleField = document.createElement("div");
    titleField.className = "log-title-field";
    const titleLabel = document.createElement("label");
    titleLabel.htmlFor = "entry-title";
    titleLabel.textContent = "Title";
    const titleInput = document.createElement("input");
    titleInput.id = "entry-title";
    titleInput.className = "form-input log-entry-title";
    titleInput.maxLength = 160;
    titleInput.value = entry.title;
    titleInput.placeholder = entry.type === "note" ? "Note title" : "List title";
    titleField.append(titleLabel, titleInput);

    const actions = document.createElement("div");
    actions.className = "log-editor-actions";
    if (state.confirmDeleteId === entry.id) {
      const cancel = document.createElement("button");
      cancel.type = "button";
      cancel.className = "btn btn-brand-outline btn-small";
      cancel.dataset.action = "cancel-delete";
      cancel.textContent = "Keep";
      const confirm = document.createElement("button");
      confirm.type = "button";
      confirm.className = "btn btn-danger btn-small";
      confirm.dataset.action = "confirm-delete";
      confirm.textContent = "Delete";
      actions.append(cancel, confirm);
    } else {
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "btn btn-brand-outline btn-small";
      remove.dataset.action = "delete-entry";
      remove.textContent = "Delete";
      actions.append(remove);
    }
    header.append(back, titleField, actions);
    editorEl.append(header);

    if (entry.type === "note") {
      editorEl.append(renderNoteEditor(entry));
    } else {
      editorEl.append(renderTodoEditor(entry));
    }

    if (!entry.title) titleInput.focus();
  }

  function renderNoteEditor(entry) {
    const wrap = document.createElement("div");
    wrap.className = "log-note";

    const controls = document.createElement("div");
    controls.className = "log-format";
    ["plain", "markdown"].forEach((format) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "log-format-btn" + (entry.format === format ? " is-active" : "");
      button.dataset.format = format;
      button.textContent = format === "plain" ? "Plain text" : "Markdown";
      button.setAttribute("aria-pressed", entry.format === format ? "true" : "false");
      controls.append(button);
    });

    const panes = document.createElement("div");
    panes.className = "log-panes" + (entry.format === "markdown" ? " has-preview" : "");

    const write = document.createElement("div");
    write.className = "log-write";
    const bodyLabel = document.createElement("label");
    bodyLabel.htmlFor = "entry-body";
    bodyLabel.textContent = entry.format === "markdown" ? "Markdown" : "Note";
    const body = document.createElement("textarea");
    body.id = "entry-body";
    body.className = "form-input log-body";
    body.maxLength = 200000;
    body.value = entry.body;
    body.placeholder = entry.format === "markdown" ? "# Heading\n\nWrite with **bold**, lists, and `code`." : "Write the note.";
    write.append(bodyLabel, body);

    panes.append(write);
    if (entry.format === "markdown") {
      const hint = document.createElement("p");
      hint.className = "log-hint";
      hint.textContent = "Supports headings, bold, italic, lists, quotes, links, and code.";
      write.append(hint);
      const preview = document.createElement("div");
      preview.className = "log-preview";
      preview.id = "note-preview";
      preview.innerHTML = entry.body.trim()
        ? LogMarkdown.render(entry.body)
        : '<p class="log-preview-empty">Markdown preview will show up here.</p>';
      panes.append(preview);
    }

    wrap.append(controls, panes);
    return wrap;
  }

  function renderTodoEditor(entry) {
    const wrap = document.createElement("div");
    wrap.className = "log-todo";
    const done = entry.items.filter((item) => item.done).length;
    const progress = document.createElement("p");
    progress.className = "log-progress";
    progress.textContent = entry.items.length ? done + " of " + entry.items.length + " complete" : "Add the first item.";
    wrap.append(progress);

    const list = document.createElement("ul");
    list.className = "log-todo-items";
    entry.items.forEach((item) => {
      const row = document.createElement("li");
      row.className = "log-todo-item" + (item.done ? " is-done" : "");
      row.dataset.itemId = item.id;

      const checkbox = document.createElement("input");
      checkbox.type = "checkbox";
      checkbox.checked = item.done;
      checkbox.dataset.action = "toggle-item";
      checkbox.setAttribute("aria-label", "Mark item done");

      const text = document.createElement("input");
      text.type = "text";
      text.className = "form-input";
      text.value = item.text;
      text.maxLength = 500;
      text.dataset.action = "edit-item";
      text.setAttribute("aria-label", "Todo item");

      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "log-icon-btn";
      remove.dataset.action = "remove-item";
      remove.setAttribute("aria-label", "Remove item");
      remove.textContent = "×";

      row.append(checkbox, text, remove);
      list.append(row);
    });

    const adder = document.createElement("form");
    adder.className = "log-add-item";
    adder.dataset.action = "add-item";
    const input = document.createElement("input");
    input.type = "text";
    input.className = "form-input";
    input.name = "item";
    input.maxLength = 500;
    input.placeholder = "Add an item";
    input.setAttribute("aria-label", "New todo item");
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn btn-brand";
    submit.textContent = "Add";
    adder.append(input, submit);

    wrap.append(list, adder);
    if (entry.items.some((item) => item.done)) {
      const clear = document.createElement("button");
      clear.type = "button";
      clear.className = "btn btn-brand-outline btn-small";
      clear.dataset.action = "clear-done";
      clear.textContent = "Clear completed";
      wrap.append(clear);
    }
    return wrap;
  }

  function render() {
    renderFilters();
    if (state.filter === "journal") {
      renderJournal();
      return;
    }
    renderList();
    renderEditor(false);
  }

  function selectEntry(id) {
    state.selectedId = id;
    state.confirmDeleteId = "";
    state.editorKey = "";
    render();
    if (window.matchMedia("(max-width: 900px)").matches) {
      editorEl.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  function addEntry(type) {
    const entry =
      type === "note"
        ? {
            id: createId(),
            type: "note",
            title: "",
            format: "plain",
            body: "",
            createdAt: now(),
            updatedAt: now()
          }
        : {
            id: createId(),
            type: "todo",
            title: "",
            items: [],
            createdAt: now(),
            updatedAt: now()
          };
    state.log.entries.unshift(entry);
    state.selectedId = entry.id;
    state.filter = "all";
    state.query = "";
    searchInput.value = "";
    state.confirmDeleteId = "";
    state.editorKey = "";
    scheduleSave();
    render();
  }

  gateToggle.addEventListener("click", () => {
    const showing = gatePassphrase.type === "text";
    gatePassphrase.type = showing ? "password" : "text";
    gateConfirm.type = showing ? "password" : "text";
    gateToggle.textContent = showing ? "Show" : "Hide";
    gateToggle.setAttribute("aria-pressed", showing ? "false" : "true");
  });

  gateForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const passphrase = gatePassphrase.value;
    if (passphrase.length < 8) {
      setGateError("Use at least 8 characters.");
      return;
    }
    gateSubmit.disabled = true;
    gateSubmit.textContent = gate.dataset.mode === "create" ? "Creating…" : "Unlocking…";
    try {
      if (gate.dataset.mode === "create") {
        if (passphrase !== gateConfirm.value) {
          setGateError("Those passphrases do not match.");
          return;
        }
        await api("/notes/api/setup", { method: "POST", body: JSON.stringify({ passphrase }) });
        state.log = blankLog();
      } else {
        await api("/notes/api/login", { method: "POST", body: JSON.stringify({ passphrase }) });
        state.log = normalizeLog(await api("/notes/api/entries"));
      }
      state.selectedId = null;
      showApp();
    } catch (error) {
      setGateError(error.message || "The log could not be opened.");
    } finally {
      gateSubmit.disabled = false;
      if (!app.hidden) return;
      gateSubmit.textContent = gate.dataset.mode === "create" ? "Create log" : "Unlock";
    }
  });

  document.querySelector("#new-note").addEventListener("click", () => addEntry("note"));
  document.querySelector("#new-todo").addEventListener("click", () => addEntry("todo"));
  document.querySelector("#new-journal").addEventListener("click", () => {
    state.filter = "journal";
    state.query = "";
    searchInput.value = "";
    if (!journal.key) {
      state.selectedId = null;
      state.confirmDeleteId = "";
      state.editorKey = "";
      render();
      return;
    }
    addJournalEntry();
  });

  filterButtons.forEach((button) => {
    button.addEventListener("click", () => {
      state.filter = button.dataset.filter;
      render();
    });
  });

  searchInput.addEventListener("input", () => {
    state.query = searchInput.value;
    renderList();
  });

  listEl.addEventListener("click", (event) => {
    const button = event.target.closest(".log-entry");
    if (!button) return;
    selectEntry(button.dataset.id);
  });

  editorEl.addEventListener("input", (event) => {
    if (state.filter === "journal") {
      const entry = journalEntry();
      if (!entry) return;
      if (event.target.id === "entry-title") entry.title = event.target.value;
      if (event.target.id === "entry-body") entry.body = event.target.value;
      if (event.target.id === "entry-title" || event.target.id === "entry-body") {
        entry.updatedAt = now();
        markJournalDirty(entry);
        const signature = state.editorKey;
        state.editorKey = signature;
        renderJournal();
        if (entry.format === "markdown" && event.target.id === "entry-body") renderPreview(entry);
      }
      return;
    }
    const entry = selectedEntry();
    if (!entry) return;
    if (event.target.id === "entry-title") {
      entry.title = event.target.value;
      touch(entry);
      scheduleSave();
      renderList();
      return;
    }
    if (event.target.id === "entry-body") {
      entry.body = event.target.value;
      touch(entry);
      scheduleSave();
      renderList();
      if (entry.format === "markdown") renderPreview(entry);
      return;
    }
    if (event.target.dataset.action === "edit-item") {
      const row = event.target.closest("[data-item-id]");
      const item = entry.items.find((candidate) => candidate.id === row.dataset.itemId);
      if (!item) return;
      item.text = event.target.value;
      touch(entry);
      scheduleSave();
      renderList();
    }
  });

  editorEl.addEventListener("click", (event) => {
    if (state.filter === "journal") {
      const control = event.target.closest("[data-action], [data-format]");
      const action = control ? control.dataset.action || control.dataset.format : "";
      if (!action) return;
      if (action === "lock-journal") {
        settleJournal().finally(() => {
          forgetJournal();
          state.selectedId = null;
          state.confirmDeleteId = "";
          state.editorKey = "";
          render();
        });
        return;
      }
      const entry = journalEntry();
      if (!entry) return;
      if (action === "back") {
        state.selectedId = null;
        state.confirmDeleteId = "";
        state.editorKey = "";
        render();
        return;
      }
      if (action === "plain" || action === "markdown") {
        entry.format = action;
        entry.updatedAt = now();
        state.editorKey = "";
        markJournalDirty(entry);
        render();
        return;
      }
      if (action === "delete-entry") {
        state.confirmDeleteId = entry.id;
        state.editorKey = "";
        render();
        return;
      }
      if (action === "cancel-delete") {
        state.confirmDeleteId = "";
        state.editorKey = "";
        render();
        return;
      }
      if (action === "confirm-delete") {
        journal.entries = journal.entries.filter((candidate) => candidate.id !== entry.id);
        journal.dirty.delete(entry.id);
        journal.removed.add(entry.id);
        journal.entryCount = journal.entries.length;
        state.selectedId = null;
        state.confirmDeleteId = "";
        state.editorKey = "";
        scheduleJournalSave();
        render();
      }
      return;
    }
    const control = event.target.closest("[data-action], [data-format]");
    const entry = selectedEntry();
    const action = control ? control.dataset.action || control.dataset.format : "";
    if (!entry || !action) return;

    if (action === "back") {
      state.selectedId = null;
      state.confirmDeleteId = "";
      state.editorKey = "";
      render();
      return;
    }

    if (action === "plain" || action === "markdown") {
      entry.format = action;
      touch(entry);
      state.editorKey = "";
      scheduleSave();
      render();
      return;
    }

    if (action === "delete-entry") {
      state.confirmDeleteId = entry.id;
      state.editorKey = "";
      renderEditor(true);
      return;
    }

    if (action === "cancel-delete") {
      state.confirmDeleteId = "";
      state.editorKey = "";
      renderEditor(true);
      return;
    }

    if (action === "confirm-delete") {
      const removedId = entry.id;
      state.log.entries = state.log.entries.filter((candidate) => candidate.id !== removedId);
      state.selectedId = null;
      state.confirmDeleteId = "";
      state.editorKey = "";
      markRemoved(removedId);
      render();
      return;
    }

    if (action === "toggle-item") {
      const row = control.closest("[data-item-id]");
      const item = entry.items.find((candidate) => candidate.id === row.dataset.itemId);
      if (!item) return;
      item.done = event.target.checked;
      touch(entry);
      state.editorKey = "";
      scheduleSave();
      render();
      return;
    }

    if (action === "remove-item") {
      const row = control.closest("[data-item-id]");
      entry.items = entry.items.filter((candidate) => candidate.id !== row.dataset.itemId);
      touch(entry);
      state.editorKey = "";
      scheduleSave();
      render();
      return;
    }

    if (action === "clear-done") {
      entry.items = entry.items.filter((item) => !item.done);
      touch(entry);
      state.editorKey = "";
      scheduleSave();
      render();
    }
  });

  editorEl.addEventListener("submit", async (event) => {
    const journalForm = event.target.closest("[data-journal-action]");
    if (journalForm) {
      event.preventDefault();
      const action = journalForm.dataset.journalAction;
      if (action === "journal-password") {
        const password = journalForm.querySelector("#journal-password").value;
        const error = journalForm.querySelector("#journal-error");
        const submit = journalForm.querySelector("button[type='submit']");
        if (password.length < 8) {
          error.hidden = false;
          error.textContent = "Use at least 8 characters.";
          return;
        }
        if (journalForm.dataset.mode === "create") {
          const confirm = journalForm.querySelector("#journal-password-confirm").value;
          if (password !== confirm) {
            error.hidden = false;
            error.textContent = "Those journal passwords do not match.";
            return;
          }
        }
        submit.disabled = true;
        submit.textContent = journalForm.dataset.mode === "create" ? "Encrypting…" : "Unlocking…";
        try {
          if (journalForm.dataset.mode === "create") await createJournal(password);
          else await openJournal(password);
          state.editorKey = "";
          render();
        } catch (openError) {
          error.hidden = false;
          error.textContent = openError.message || "The journal could not be opened.";
          submit.disabled = false;
          submit.textContent = journalForm.dataset.mode === "create" ? "Create journal" : "Unlock journal";
        }
        return;
      }
      if (action === "change-journal-password") {
        const next = journalForm.querySelector("#journal-next-password");
        const again = journalForm.querySelector("#journal-next-password-confirm");
        const message = journalForm.querySelector("#journal-password-message");
        message.textContent = "";
        if (next.value.length < 8) {
          message.textContent = "Use at least 8 characters.";
          return;
        }
        if (next.value !== again.value) {
          message.textContent = "Those journal passwords do not match.";
          return;
        }
        try {
          await changeJournalPassword(next.value);
          next.value = "";
          again.value = "";
          message.textContent = "Journal password updated. Entries were re-encrypted.";
        } catch (changeError) {
          message.textContent = changeError.message || "The journal password could not be changed.";
        }
      }
      return;
    }
    const form = event.target.closest("[data-action='add-item']");
    if (!form) return;
    event.preventDefault();
    const entry = selectedEntry();
    if (!entry || entry.type !== "todo") return;
    const input = form.querySelector("input");
    const text = input ? input.value.trim() : "";
    if (!text) return;
    if (entry.items.length >= 200) {
      const progress = editorEl.querySelector(".log-progress");
      if (progress) progress.textContent = "A list holds up to 200 items.";
      return;
    }
    entry.items.push({ id: createId(), text, done: false });
    touch(entry);
    state.editorKey = "";
    scheduleSave();
    render();
    const next = editorEl.querySelector(".log-add-item input");
    if (next) next.focus();
  });

  function clearPrivateView() {
    window.clearTimeout(state.saveTimer);
    window.clearTimeout(journal.timer);
    dirty.clear();
    removed.clear();
    sendingSaves.clear();
    sendingDeletes.clear();
    forgetJournal();
    state.log = blankLog();
    state.selectedId = null;
    state.query = "";
    state.confirmDeleteId = "";
    state.confirmErase = false;
    state.editorKey = "";
    state.saving = false;
    searchInput.value = "";
    ["#current-passphrase", "#next-passphrase", "#next-passphrase-confirm"].forEach((selector) => {
      const field = document.querySelector(selector);
      if (field) field.value = "";
    });
    const passphraseMessage = document.querySelector("#passphrase-message");
    if (passphraseMessage) passphraseMessage.textContent = "";
    listEl.innerHTML = "";
    editorEl.innerHTML = "";
    const lockButton = document.querySelector("#lock-btn");
    lockButton.textContent = "Lock";
    delete lockButton.dataset.discard;
  }

  document.querySelector("#lock-btn").addEventListener("click", async () => {
    const button = document.querySelector("#lock-btn");
    const discard = button.dataset.discard === "true";
    if (!discard) {
      try {
        await settleSaves();
        await settleJournal();
      } catch (error) {
        button.textContent = "Discard and lock";
        button.dataset.discard = "true";
        setSaveState("Unsaved edits could not be stored. Choose Discard and lock to hide them.");
        return;
      }
    }
    let logoutMessage = "";
    try {
      await api("/notes/api/logout", { method: "POST", body: "{}" });
    } catch (error) {
      logoutMessage = "Sign-out did not finish. Refreshing may open the log again.";
    }
    clearPrivateView();
    showGate("unlock");
    if (logoutMessage) setGateError(logoutMessage);
  });

  document.querySelector("#export-btn").addEventListener("click", () => {
    const blob = new Blob([JSON.stringify(state.log, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    const stamp = new Date().toISOString().slice(0, 10);
    link.href = url;
    link.download = "log-backup-" + stamp + ".json";
    link.click();
    URL.revokeObjectURL(url);
  });

  document.querySelector("#import-btn").addEventListener("click", () => importInput.click());

  importInput.addEventListener("change", async () => {
    const file = importInput.files && importInput.files[0];
    importInput.value = "";
    if (!file) return;
    try {
      const parsed = JSON.parse(await file.text());
      if (!parsed || !Array.isArray(parsed.entries)) {
        throw new Error("That file is not a log backup.");
      }
      await settleSaves();
      dirty.clear();
      removed.clear();
      sendingSaves.clear();
      sendingDeletes.clear();
      const saved = await api("/notes/api/import", { method: "POST", body: JSON.stringify({ entries: parsed.entries }) });
      state.log = normalizeLog(saved);
      state.selectedId = state.log.entries[0] ? state.log.entries[0].id : null;
      state.editorKey = "";
      setSaveState("Saved");
      render();
    } catch (error) {
      setSaveState(error.message || "That backup could not be imported.");
    }
  });

  document.querySelector("#passphrase-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const current = document.querySelector("#current-passphrase");
    const next = document.querySelector("#next-passphrase");
    const again = document.querySelector("#next-passphrase-confirm");
    const message = document.querySelector("#passphrase-message");
    message.textContent = "";
    if (next.value.length < 8) {
      message.textContent = "Use at least 8 characters.";
      return;
    }
    if (next.value !== again.value) {
      message.textContent = "Those passphrases do not match.";
      return;
    }
    try {
      await settleSaves();
      await api("/notes/api/passphrase", {
        method: "PUT",
        body: JSON.stringify({ current: current.value, next: next.value })
      });
      current.value = "";
      next.value = "";
      again.value = "";
      message.textContent = "Passphrase updated.";
    } catch (error) {
      message.textContent = error.message || "The passphrase could not be changed.";
    }
  });

  document.querySelector("#erase-btn").addEventListener("click", async () => {
    const button = document.querySelector("#erase-btn");
    if (!state.confirmErase) {
      state.confirmErase = true;
      button.textContent = "Yes, delete all entries";
      return;
    }
    window.clearTimeout(state.saveTimer);
    dirty.clear();
    removed.clear();
    await persistChain;
    try {
      await api("/notes/api/entries", { method: "DELETE" });
      state.log = blankLog();
      state.selectedId = null;
      state.editorKey = "";
      state.confirmErase = false;
      button.textContent = "Delete all entries";
      backupPanel.open = false;
      setSaveState("Saved");
      render();
    } catch (error) {
      setSaveState(error.message || "The entries could not be deleted.");
    }
  });

  function keepaliveJson(path, method, payload) {
    const body = payload === undefined ? undefined : JSON.stringify(payload);
    if (body && body.length > 60000) return;
    fetch(path, {
      method,
      body,
      keepalive: true,
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" }
    }).catch(() => {});
  }

  function hasUnsavedWork() {
    return (
      dirty.size > 0 ||
      removed.size > 0 ||
      sendingSaves.size > 0 ||
      sendingDeletes.size > 0 ||
      journal.dirty.size > 0 ||
      journal.removed.size > 0 ||
      journal.sending.size > 0
    );
  }

  function flushOnLeave() {
    window.clearTimeout(state.saveTimer);
    window.clearTimeout(journal.timer);
    const saves = new Map(sendingSaves);
    dirty.forEach((id) => {
      const entry = state.log.entries.find((candidate) => candidate.id === id);
      if (entry) saves.set(id, JSON.parse(JSON.stringify(entry)));
    });
    const deletes = new Set([...removed, ...sendingDeletes]);
    deletes.forEach((id) => {
      saves.delete(id);
      keepaliveJson("/notes/api/entries/" + encodeURIComponent(id), "DELETE");
    });
    saves.forEach((entry, id) => {
      keepaliveJson("/notes/api/entries/" + encodeURIComponent(id), "PUT", entry);
    });
    if (journal.key) persistJournal();
  }

  window.addEventListener("beforeunload", (event) => {
    if (!hasUnsavedWork()) return;
    flushOnLeave();
    event.preventDefault();
    event.returnValue = "";
  });

  window.addEventListener("pagehide", () => {
    if (hasUnsavedWork()) flushOnLeave();
  });

  async function boot() {
    try {
      const status = await api("/notes/api/session");
      if (status.authenticated) {
        state.log = normalizeLog(await api("/notes/api/entries"));
        showApp();
        setSaveState("Saved");
        return;
      }
      showGate(status.needsSetup ? "create" : "unlock");
    } catch (error) {
      showGate("unlock");
      gateForm.hidden = true;
      setGateError(error.message || "The notes database is not connected. Check the MySQL settings in .env and restart the server.");
    }
  }

  boot();
})();
