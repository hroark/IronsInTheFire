
document.addEventListener("DOMContentLoaded", function () {
  const scriptUrl = new URL(document.currentScript?.src || "src/js/navbar.js", window.location.href);
  const navbarUrl = new URL("../partials/navbar.html", scriptUrl);

  fetch(navbarUrl)
    .then((res) => res.text())
    .then((data) => {
      document.getElementById("navbar").innerHTML = data;

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
    });
});
