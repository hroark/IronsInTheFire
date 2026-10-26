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

  var previewGeneration = 0;

  function renderMermaid(preview) {
    if (!window.mermaid) {
      return;
    }
    var blocks = preview.querySelectorAll("code.language-mermaid");
    if (!blocks.length) {
      return;
    }
    window.mermaid.initialize({
      startOnLoad: false,
      securityLevel: "strict",
      suppressErrorRendering: true,
      theme: "neutral"
    });
    var generation = ++previewGeneration;
    Array.prototype.forEach.call(blocks, function (block, index) {
      var source = block.textContent || "";
      var host = block.parentNode && block.parentNode.tagName === "PRE" ? block.parentNode : block;
      window.mermaid.render("mermaid-" + generation + "-" + index, source).then(function (result) {
        if (generation !== previewGeneration || !host.parentNode) {
          return;
        }
        var diagram = document.createElement("div");
        diagram.className = "mermaid-diagram";
        diagram.innerHTML = result.svg;
        host.parentNode.replaceChild(diagram, host);
      }).catch(function () {
        if (generation !== previewGeneration || !host.parentNode) {
          return;
        }
        var error = document.createElement("p");
        error.className = "mermaid-error";
        error.textContent = "This diagram could not be drawn.";
        host.parentNode.insertBefore(error, host);
      });
    });
  }

  function renderPreview() {
    var preview = document.getElementById("note-preview");
    var body = document.getElementById("note-body");
    if (!preview || !body || !window.marked || !window.DOMPurify) {
      return;
    }
    previewGeneration += 1;
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
    renderMermaid(preview);
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

  var notebookDragged = false;
  var notebookType = "application/x-notes-notebook";

  function dragTypes(event) {
    var types = event.dataTransfer && event.dataTransfer.types ? event.dataTransfer.types : [];
    var names = [];
    for (var i = 0; i < types.length; i++) {
      names.push(String(types[i]).toLowerCase());
    }
    return names;
  }

  function draggingNotebook(event) {
    return dragTypes(event).indexOf(notebookType) !== -1;
  }

  function clearDropMarks(keepDragging) {
    Array.prototype.forEach.call(document.querySelectorAll(".is-dragging, .is-drop-target, .is-drop-before, .is-drop-after"), function (node) {
      if (!keepDragging) {
        node.classList.remove("is-dragging");
      }
      node.classList.remove("is-drop-target");
      node.classList.remove("is-drop-before");
      node.classList.remove("is-drop-after");
    });
  }

  document.addEventListener("dragstart", function (event) {
    var control = event.target.closest ? event.target.closest("button, input, select, textarea, label") : null;
    if (control) {
      event.preventDefault();
      return;
    }
    var card = event.target.closest ? event.target.closest("[data-note-id]") : null;
    if (card) {
      event.dataTransfer.setData("text/plain", card.getAttribute("data-note-id") || "");
      event.dataTransfer.effectAllowed = "move";
      card.classList.add("is-dragging");
      return;
    }
    var folder = event.target.closest ? event.target.closest("[data-notebook-id]") : null;
    if (!folder) {
      return;
    }
    var notebookId = folder.getAttribute("data-notebook-id") || "";
    event.dataTransfer.setData(notebookType, notebookId);
    event.dataTransfer.setData("text/plain", notebookId);
    event.dataTransfer.effectAllowed = "move";
    folder.classList.add("is-dragging");
    notebookDragged = true;
  });

  document.addEventListener("dragover", function (event) {
    var target = event.target.closest ? event.target.closest("[data-drop-notebook]") : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    clearDropMarks(true);
    if (draggingNotebook(event)) {
      var rect = target.getBoundingClientRect();
      target.classList.add(event.clientY > rect.top + rect.height / 2 ? "is-drop-after" : "is-drop-before");
      return;
    }
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
    target.classList.remove("is-drop-before");
    target.classList.remove("is-drop-after");
  });

  document.addEventListener("drop", function (event) {
    var target = event.target.closest ? event.target.closest("[data-drop-notebook]") : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    var notebookDrag = event.dataTransfer.getData(notebookType);
    if (notebookDrag) {
      var list = target.closest("[data-notebook-list]");
      var draggedFolder = null;
      if (list) {
        Array.prototype.forEach.call(list.querySelectorAll("[data-notebook-id]"), function (item) {
          if (item.getAttribute("data-notebook-id") === notebookDrag) {
            draggedFolder = item;
          }
        });
      }
      var rect = target.getBoundingClientRect();
      var after = event.clientY > rect.top + rect.height / 2;
      clearDropMarks();
      if (!list || !draggedFolder) {
        return;
      }
      var reference = after ? target.nextElementSibling : target;
      if (draggedFolder === target || reference === draggedFolder) {
        return;
      }
      list.insertBefore(draggedFolder, reference);
      var order = [];
      Array.prototype.forEach.call(list.querySelectorAll("[data-notebook-id]"), function (item) {
        order.push(item.getAttribute("data-notebook-id"));
      });
      var data = new FormData();
      data.set("action", "reorder-notebooks");
      data.set("csrf", csrf());
      data.set("order", order.join(","));
      data.set("return", location.search.replace(/^\?/, "") || "view=notes");
      fetch("index.php", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: data
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload || !payload.ok) {
            window.location.reload();
          }
        })
        .catch(function () {
          window.location.reload();
        });
      return;
    }
    clearDropMarks();
    var noteId = event.dataTransfer.getData("text/plain");
    var notebookId = target.getAttribute("data-drop-notebook");
    if (!noteId || !notebookId) {
      return;
    }
    var moveData = new FormData();
    moveData.set("action", "move-note");
    moveData.set("csrf", csrf());
    moveData.set("id", noteId);
    moveData.set("notebook_id", notebookId);
    moveData.set("return", location.search.replace(/^\?/, "") || "view=notes");
    fetch("index.php", {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: moveData
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
    clearDropMarks();
    window.setTimeout(function () {
      notebookDragged = false;
    }, 0);
  });

  document.addEventListener("click", function (event) {
    if (!notebookDragged) {
      return;
    }
    var folder = event.target.closest ? event.target.closest("[data-notebook-id]") : null;
    if (!folder) {
      return;
    }
    event.preventDefault();
    notebookDragged = false;
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

  function draggedFiles(event) {
    var types = event.dataTransfer && event.dataTransfer.types ? event.dataTransfer.types : [];
    return Array.prototype.indexOf.call(types, "Files") !== -1;
  }

  function submitDroppedFiles(fileList, folderId) {
    var form = document.getElementById("file-drop");
    if (!form || !fileList || !fileList.length || typeof DataTransfer === "undefined") {
      return;
    }
    var input = form.querySelector('input[type="file"]');
    var folder = form.querySelector('[name="folder_id"]');
    if (!input) {
      return;
    }
    var transfer = new DataTransfer();
    Array.prototype.forEach.call(fileList, function (file) {
      transfer.items.add(file);
    });
    input.files = transfer.files;
    if (folder) {
      folder.value = String(folderId);
    }
    form.submit();
  }

  var dropZone = document.getElementById("file-drop");
  if (dropZone) {
    ["dragenter", "dragover"].forEach(function (name) {
      dropZone.addEventListener(name, function (event) {
        if (!draggedFiles(event)) {
          return;
        }
        event.preventDefault();
        dropZone.classList.add("is-hot");
      });
    });
    dropZone.addEventListener("dragleave", function () {
      dropZone.classList.remove("is-hot");
    });
    dropZone.addEventListener("drop", function (event) {
      if (!draggedFiles(event)) {
        return;
      }
      event.preventDefault();
      var folder = dropZone.querySelector('[name="folder_id"]');
      submitDroppedFiles(event.dataTransfer.files, folder ? folder.value : "0");
    });
    var browse = document.getElementById("file-browse");
    if (browse) {
      browse.addEventListener("change", function () {
        if (browse.files && browse.files.length) {
          dropZone.submit();
        }
      });
    }
    Array.prototype.forEach.call(document.querySelectorAll("[data-folder-id]"), function (link) {
      link.addEventListener("dragover", function (event) {
        if (!draggedFiles(event)) {
          return;
        }
        event.preventDefault();
        link.classList.add("is-hot");
      });
      link.addEventListener("dragleave", function () {
        link.classList.remove("is-hot");
      });
      link.addEventListener("drop", function (event) {
        if (!draggedFiles(event)) {
          return;
        }
        event.preventDefault();
        submitDroppedFiles(event.dataTransfer.files, link.getAttribute("data-folder-id") || "0");
      });
    });
  }
})();
