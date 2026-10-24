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
    return { entries: value.entries.map(normalizeEntry).filter(Boolean).slice(0, 500) };
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

  function showApp() {
    gate.hidden = true;
    app.hidden = false;
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
    if (!saveIds.length && !deleteIds.length) return;
    try {
      for (const id of deleteIds) {
        await api("/notes/api/entries/" + encodeURIComponent(id), { method: "DELETE" });
      }
      for (const id of saveIds) {
        const entry = state.log.entries.find((candidate) => candidate.id === id);
        if (!entry) continue;
        await api("/notes/api/entries/" + encodeURIComponent(id), {
          method: "PUT",
          body: JSON.stringify(entry)
        });
      }
      state.saving = dirty.size > 0 || removed.size > 0;
      if (!state.saving) setSaveState("Saved");
    } catch (error) {
      saveIds.forEach((id) => dirty.add(id));
      deleteIds.forEach((id) => removed.add(id));
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

  function renderFilters() {
    const counts = {
      all: state.log.entries.length,
      note: state.log.entries.filter((entry) => entry.type === "note").length,
      todo: state.log.entries.filter((entry) => entry.type === "todo").length
    };
    filterButtons.forEach((button) => {
      const filter = button.dataset.filter;
      const label = filter === "all" ? "All" : filter === "note" ? "Notes" : "Todos";
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

  editorEl.addEventListener("submit", (event) => {
    const form = event.target.closest("[data-action='add-item']");
    if (!form) return;
    event.preventDefault();
    const entry = selectedEntry();
    if (!entry || entry.type !== "todo") return;
    const input = form.querySelector("input");
    const text = input ? input.value.trim() : "";
    if (!text) return;
    entry.items.push({ id: createId(), text, done: false });
    touch(entry);
    state.editorKey = "";
    scheduleSave();
    render();
    const next = editorEl.querySelector(".log-add-item input");
    if (next) next.focus();
  });

  document.querySelector("#lock-btn").addEventListener("click", async () => {
    try {
      await settleSaves();
    } catch (error) {
      return;
    }
    await api("/notes/api/logout", { method: "POST", body: "{}" });
    state.log = blankLog();
    state.selectedId = null;
    state.query = "";
    searchInput.value = "";
    state.editorKey = "";
    showGate("unlock");
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
      const imported = normalizeLog(JSON.parse(await file.text()));
      await settleSaves();
      dirty.clear();
      removed.clear();
      const saved = await api("/notes/api/import", { method: "POST", body: JSON.stringify(imported) });
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

  window.addEventListener("pagehide", () => {
    if (dirty.size || removed.size) persistNow();
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
