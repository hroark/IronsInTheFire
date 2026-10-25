function clipDestination(base, payload) {
  var cleaned = String(base || "").trim();
  var hashAt = cleaned.indexOf("#");
  if (hashAt >= 0) {
    cleaned = cleaned.slice(0, hashAt);
  }
  var queryAt = cleaned.indexOf("?");
  if (queryAt >= 0) {
    cleaned = cleaned.slice(0, queryAt);
  }
  cleaned = cleaned.replace(/\/+$/, "");
  cleaned = cleaned.replace(/\/index\.php$/i, "");
  return cleaned + "/index.php?view=clip#" + encodeURIComponent(JSON.stringify(payload));
}

chrome.action.onClicked.addListener(function (tab) {
  chrome.storage.sync.get({ notesUrl: "" }, function (stored) {
    var notesUrl = stored.notesUrl || "";
    if (!/^https?:\/\//i.test(notesUrl)) {
      chrome.runtime.openOptionsPage();
      return;
    }
    if (!tab || !tab.id) {
      return;
    }
    chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: function () {
        return {
          url: location.href,
          title: document.title,
          selection: String(window.getSelection() || "")
        };
      }
    }, function (results) {
      if (chrome.runtime.lastError || !results || !results[0] || !results[0].result) {
        chrome.action.setBadgeText({ text: "!" });
        return;
      }
      chrome.action.setBadgeText({ text: "" });
      chrome.windows.create({
        url: clipDestination(notesUrl, results[0].result),
        type: "popup",
        width: 520,
        height: 720
      });
    });
  });
});
