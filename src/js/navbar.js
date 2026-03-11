
document.addEventListener("DOMContentLoaded", function () {
  fetch("partials/navbar.html")
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
