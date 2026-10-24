(function () {
  var taskPattern = /^(\s*[-*+]\s+)\[([ xX])\](.*)$/;

  function toggleTaskLine(markdown, taskIndex) {
    var lines = String(markdown).replace(/\r\n/g, "\n").replace(/\r/g, "\n").split("\n");
    var seen = 0;
    for (var i = 0; i < lines.length; i++) {
      var match = lines[i].match(taskPattern);
      if (!match) {
        continue;
      }
      if (seen === taskIndex) {
        var mark = match[2].toLowerCase() === "x" ? " " : "x";
        lines[i] = match[1] + "[" + mark + "]" + match[3];
        break;
      }
      seen++;
    }
    return lines.join("\n");
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
    Array.prototype.forEach.call(boxes, function (box, index) {
      box.disabled = false;
      box.setAttribute("data-task-index", String(index));
    });
  }

  var body = document.getElementById("note-body");
  var preview = document.getElementById("note-preview");
  var form = document.getElementById("note-form");
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
      var data = new FormData();
      data.set("action", "toggle-task");
      data.set("csrf", csrf());
      data.set("id", noteId);
      data.set("task_index", String(index));
      data.set("body", body.value);
      fetch("index.php", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: data
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (payload && payload.ok && typeof payload.body === "string") {
            body.value = payload.body;
            renderPreview();
          }
        })
        .catch(function () {
          body.value = toggleTaskLine(body.value, index);
          renderPreview();
        });
    });
  }

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
          if (payload && payload.title && linkTitle.value.trim() === "") {
            linkTitle.value = payload.title;
          }
        })
        .catch(function () {});
    });
  }
})();
