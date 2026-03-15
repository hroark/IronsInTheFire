
document.addEventListener("DOMContentLoaded", function () {
  const scriptUrl = new URL(document.currentScript?.src || "src/js/navbar.js", window.location.href);
  const navbarUrl = new URL("../partials/navbar.html", scriptUrl);

  fetch(navbarUrl)
    .then((res) => res.text())
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
