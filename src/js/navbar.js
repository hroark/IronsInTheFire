(function () {
  const script = document.currentScript;

  function loadNavbar() {
    const mount = document.getElementById("navbar");
    if (!mount || !script) {
      return;
    }

    const navbarUrl = new URL("../partials/navbar.html", script.src);
    fetch(navbarUrl)
      .then(function (res) {
        if (!res.ok) {
          throw new Error("Navbar failed to load");
        }
        return res.text();
      })
      .then(function (html) {
        mount.innerHTML = html;
        const current = window.location.pathname.split("/").pop() || "index.html";
        mount.querySelectorAll(".nav-link").forEach(function (link) {
          const href = link.getAttribute("href") || "";
          const file = href.split("/").pop();
          if (file === current || (current === "" && file === "index.html")) {
            link.classList.add("active");
            link.setAttribute("aria-current", "page");
          }
        });
      })
      .catch(function (error) {
        console.error(error);
      });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", loadNavbar);
  } else {
    loadNavbar();
  }
})();
