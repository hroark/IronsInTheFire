var form = document.getElementById("options");
var input = document.getElementById("notes-url");
var saved = document.getElementById("saved");

chrome.storage.sync.get({ notesUrl: "" }, function (stored) {
  input.value = stored.notesUrl || "";
});

form.addEventListener("submit", function (event) {
  event.preventDefault();
  chrome.storage.sync.set({ notesUrl: input.value.trim() }, function () {
    saved.hidden = false;
  });
});
