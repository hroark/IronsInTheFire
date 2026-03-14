
function getNavbarScriptUrl() {
  const scriptElement = document.querySelector('script[src$="/src/js/navbar.js"], script[src$="src/js/navbar.js"], script[src$="/js/navbar.js"], script[src$="js/navbar.js"]');

  if (scriptElement?.src) {
    return new URL(scriptElement.src, window.location.href);
  }

  return new URL("src/js/navbar.js", window.location.href);
}

function setActiveNavLink() {
  const currentPage = window.location.pathname.split("/").pop() || "index.html";

  document.querySelectorAll(".nav-link").forEach((link) => {
    const isCurrentPage = link.getAttribute("href") === currentPage;

    link.classList.toggle("active", isCurrentPage);

    if (isCurrentPage) {
      link.setAttribute("aria-current", "page");
    } else {
      link.removeAttribute("aria-current");
    }
  });
}

function loadNavbar() {
  const navbarMount = document.getElementById("navbar");

  if (!navbarMount) {
    return;
  }

  const scriptUrl = getNavbarScriptUrl();
  const navbarUrl = new URL("../partials/navbar.html", scriptUrl);

  fetch(navbarUrl)
    .then((res) => {
      if (!res.ok) {
        throw new Error(`Failed to load navbar: ${res.status}`);
      }

      return res.text();
    })
    .then((data) => {
      navbarMount.innerHTML = data;
      setActiveNavLink();
    })
    .catch((error) => {
      console.error(error);
    });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", loadNavbar);
} else {
  loadNavbar();
}
