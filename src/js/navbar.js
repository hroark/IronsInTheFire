const navbarScriptUrl = new URL(document.currentScript?.src || "src/js/navbar.js", window.location.href);

function loadNavbar() {
  const navbarMount = document.getElementById("navbar");
  if (!navbarMount) return;

  const navbarUrl = new URL("../partials/navbar.html", navbarScriptUrl);

  fetch(navbarUrl)
    .then((response) => {
      if (!response.ok) throw new Error("Navigation could not be loaded.");
      return response.text();
    })
    .then((html) => {
      navbarMount.innerHTML = html;
      setActiveNavLink();
    })
    .catch((error) => {
      console.error(error);
    });
}

function setActiveNavLink() {
  const page = window.location.pathname.split("/").pop() || "index.html";
  document.querySelectorAll("#navbar .nav-link").forEach((link) => {
    const href = link.getAttribute("href") || "";
    const isCurrent = href === page;
    link.classList.toggle("active", isCurrent);
    if (isCurrent) link.setAttribute("aria-current", "page");
    else link.removeAttribute("aria-current");
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", loadNavbar);
} else {
  loadNavbar();
}
