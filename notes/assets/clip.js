(function () {
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

  var hash = location.hash;
  var payload = readClipPayload(hash);
  if (payload) {
    sessionStorage.setItem("notes-clip-hash", hash);
  } else {
    payload = readClipPayload(sessionStorage.getItem("notes-clip-hash") || "");
  }

  var form = document.getElementById("clip-form");
  if (!form || !payload) {
    return;
  }
  var status = document.getElementById("clip-status");
  if (status) {
    status.textContent = "Saving to Inbox.";
  }
  form.querySelector('[name="url"]').value = payload.url;
  form.querySelector('[name="title"]').value = typeof payload.title === "string" ? payload.title : "";
  form.querySelector('[name="selection"]').value = typeof payload.selection === "string" ? payload.selection : "";
  sessionStorage.removeItem("notes-clip-hash");
  form.submit();
})();
