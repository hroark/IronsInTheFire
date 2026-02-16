
/* used to ensure navbar is tracking */
document.addEventListener("DOMContentLoaded", function () {

    fetch("partials/navbar.html")
      .then(res => res.text())
      .then(data => {
        document.getElementById("navbar").innerHTML = data;
  
        // Mobile toggle
        const menuBtn = document.getElementById("menuBtn");
        const mobileMenu = document.getElementById("mobileMenu");
  
        if (menuBtn) {
          menuBtn.addEventListener("click", () => {
            mobileMenu.classList.toggle("hidden");
          });
        }
  
        // Active link highlight
        const currentPage = window.location.pathname.split("/").pop();
        document.querySelectorAll(".nav-link").forEach(link => {
          if (link.getAttribute("href") === currentPage) {
            link.classList.add("text-blue-600", "font-semibold");
          }
        });
  
      });
  
  });
  