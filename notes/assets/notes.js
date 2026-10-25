(function () {
  var taskPattern = /^(\s*[-*+]\s+)\[([ xX])\](.*)$/;

  function taskLines(lines) {
    var tasks = [];
    var fenceCharacter = null;
    var fenceLength = 0;
    for (var i = 0; i < lines.length; i++) {
      if (fenceCharacter !== null) {
        var closingFence = new RegExp("^ {0,3}" + fenceCharacter + "{" + fenceLength + ",}[ \\t]*$");
        if (closingFence.test(lines[i])) {
          fenceCharacter = null;
          fenceLength = 0;
        }
        continue;
      }
      var fence = lines[i].match(/^ {0,3}(`{3,}|~{3,})/);
      if (fence) {
        fenceCharacter = fence[1].charAt(0);
        fenceLength = fence[1].length;
        continue;
      }
      var match = lines[i].match(taskPattern);
      if (match) {
        tasks.push({ line: i, match: match });
      }
    }
    return tasks;
  }

  function toggleTaskLine(markdown, taskIndex) {
    var lines = String(markdown).replace(/\r\n/g, "\n").replace(/\r/g, "\n").split("\n");
    var task = taskLines(lines)[taskIndex];
    if (task) {
      var mark = task.match[2].toLowerCase() === "x" ? " " : "x";
      lines[task.line] = task.match[1] + "[" + mark + "]" + task.match[3];
    }
    return lines.join("\n");
  }

  function reconcileTaskToggle(currentMarkdown, submittedMarkdown, responseMarkdown, taskIndex) {
    var normalize = function (markdown) {
      return String(markdown).replace(/\r\n/g, "\n").replace(/\r/g, "\n").split("\n");
    };
    var currentLines = normalize(currentMarkdown);
    var submittedTask = taskLines(normalize(submittedMarkdown))[taskIndex];
    var responseTask = taskLines(normalize(responseMarkdown))[taskIndex];
    var currentTask = taskLines(currentLines)[taskIndex];
    if (!submittedTask || !responseTask || !currentTask ||
        currentTask.match[2].toLowerCase() !== submittedTask.match[2].toLowerCase()) {
      return currentMarkdown;
    }
    currentLines[currentTask.line] = currentTask.match[1] + "[" + responseTask.match[2] + "]" + currentTask.match[3];
    return currentLines.join("\n");
  }

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") : "";
  }

  function renderPreview() {
    var preview = document.getElementById("note-preview");
    var body = document.getElementById("note-body");
    if (!preview || !body || !window.marked || !window.DOMPurify) {
      return;
    }
    var raw = window.marked.parse(body.value, { gfm: true, breaks: false });
    preview.innerHTML = window.DOMPurify.sanitize(raw, {
      ADD_TAGS: ["input"],
      ADD_ATTR: ["checked", "disabled", "type"]
    });
    var boxes = preview.querySelectorAll('input[type="checkbox"]');
    var tasks = taskLines(body.value.replace(/\r\n/g, "\n").replace(/\r/g, "\n").split("\n"));
    Array.prototype.forEach.call(boxes, function (box, index) {
      box.disabled = false;
      if (index < tasks.length) {
        box.setAttribute("data-task-index", String(index));
      } else {
        box.disabled = true;
        box.removeAttribute("data-task-index");
      }
    });
  }

  var body = document.getElementById("note-body");
  var preview = document.getElementById("note-preview");
  var form = document.getElementById("note-form");
  var taskUpdateQueue = Promise.resolve();
  if (body) {
    body.addEventListener("input", renderPreview);
    renderPreview();
  }

  if (preview && body && form) {
    preview.addEventListener("click", function (event) {
      var box = event.target.closest ? event.target.closest('input[type="checkbox"]') : null;
      if (!box || !preview.contains(box)) {
        return;
      }
      event.preventDefault();
      var index = Number(box.getAttribute("data-task-index"));
      var noteId = form.querySelector('[name="id"]').value;
      if (!noteId || noteId === "0") {
        body.value = toggleTaskLine(body.value, index);
        renderPreview();
        return;
      }
      taskUpdateQueue = taskUpdateQueue.then(function () {
        var submittedBody = body.value;
        var data = new FormData();
        data.set("action", "toggle-task");
        data.set("csrf", csrf());
        data.set("id", noteId);
        data.set("task_index", String(index));
        data.set("body", submittedBody);
        return fetch("index.php", {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
          body: data
        })
          .then(function (response) { return response.json(); })
          .then(function (payload) {
            if (payload && payload.ok && typeof payload.body === "string") {
              body.value = reconcileTaskToggle(body.value, submittedBody, payload.body, index);
            } else {
              body.value = toggleTaskLine(body.value, index);
            }
            renderPreview();
          })
          .catch(function () {
            body.value = toggleTaskLine(body.value, index);
            renderPreview();
          });
      });
    });
  }

  function readClipPayload(hash) {
    var raw = String(hash || "");
    if (raw.charAt(0) === "#") {
      raw = raw.slice(1);
    }
    if (!raw) {
      return null;
    }
    var candidates = [raw];
    try {
      candidates.push(decodeURIComponent(raw));
    } catch (error) {
      /* The hash may already be decoded. */
    }
    for (var i = 0; i < candidates.length; i++) {
      try {
        var payload = JSON.parse(candidates[i]);
        if (payload && typeof payload.url === "string") {
          return payload;
        }
      } catch (error) {
        /* Try the other form of the hash. */
      }
    }
    return null;
  }

  if (readClipPayload(location.hash)) {
    sessionStorage.setItem("notes-clip-hash", location.hash);
  }

  document.addEventListener("dragstart", function (event) {
    var card = event.target.closest ? event.target.closest("[data-note-id]") : null;
    if (!card) {
      return;
    }
    var control = event.target.closest ? event.target.closest("button, input, select, textarea, label") : null;
    if (control) {
      event.preventDefault();
      return;
    }
    event.dataTransfer.setData("text/plain", card.getAttribute("data-note-id") || "");
    event.dataTransfer.effectAllowed = "move";
    card.classList.add("is-dragging");
  });

  document.addEventListener("dragover", function (event) {
    var target = event.target.closest ? event.target.closest("[data-drop-notebook]") : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    target.classList.add("is-drop-target");
  });

  document.addEventListener("dragleave", function (event) {
    var target = event.target.closest ? event.target.closest("[data-drop-notebook]") : null;
    if (!target) {
      return;
    }
    if (event.relatedTarget && target.contains(event.relatedTarget)) {
      return;
    }
    target.classList.remove("is-drop-target");
  });

  document.addEventListener("drop", function (event) {
    var target = event.target.closest ? event.target.closest("[data-drop-notebook]") : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    target.classList.remove("is-drop-target");
    var noteId = event.dataTransfer.getData("text/plain");
    var notebookId = target.getAttribute("data-drop-notebook");
    if (!noteId || !notebookId) {
      return;
    }
    var data = new FormData();
    data.set("action", "move-note");
    data.set("csrf", csrf());
    data.set("id", noteId);
    data.set("notebook_id", notebookId);
    data.set("return", location.search.replace(/^\?/, "") || "view=notes");
    fetch("index.php", {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: data
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (payload && payload.ok) {
          window.location.reload();
        }
      })
      .catch(function () {});
  });

  document.addEventListener("dragend", function () {
    Array.prototype.forEach.call(document.querySelectorAll(".is-dragging, .is-drop-target"), function (node) {
      node.classList.remove("is-dragging");
      node.classList.remove("is-drop-target");
    });
  });

  document.addEventListener("submit", function (event) {
    var target = event.target;
    if (!target || !target.getAttribute) {
      return;
    }
    var message = target.getAttribute("data-confirm");
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  var linkUrl = document.getElementById("link-url");
  var linkTitle = document.getElementById("link-title");
  if (linkUrl && linkTitle) {
    linkUrl.addEventListener("change", function () {
      if (linkTitle.value.trim() !== "" || linkUrl.value.trim() === "") {
        return;
      }
      var data = new FormData();
      data.set("action", "github-title");
      data.set("csrf", csrf());
      data.set("url", linkUrl.value.trim());
      fetch("index.php", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: data
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (payload && payload.title && linkTitle.value.trim() === "" && linkUrl.value.trim() === data.get("url")) {
            linkTitle.value = payload.title;
          }
        })
        .catch(function () {});
    });
  }
})();
