document.addEventListener("DOMContentLoaded", function () {
  const printButton = document.getElementById("print-resume-button");
  const printContainer = document.getElementById("resume-print-container");

  if (!printButton || !printContainer) {
    return;
  }

  const resumePdfPath = "../Patrick Porter Resume.pdf";
  let renderPromise;

  if (window.pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
  }

  async function renderResumeForPrint() {
    if (!window.pdfjsLib) {
      throw new Error("PDF.js is unavailable.");
    }

    printContainer.innerHTML = "";

    const pdf = await pdfjsLib.getDocument(resumePdfPath).promise;

    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
      const page = await pdf.getPage(pageNumber);
      const viewport = page.getViewport({ scale: 2 });
      const canvas = document.createElement("canvas");
      const context = canvas.getContext("2d");
      const pageWrapper = document.createElement("div");

      canvas.width = viewport.width;
      canvas.height = viewport.height;
      pageWrapper.className = "resume-print-page";
      pageWrapper.appendChild(canvas);
      printContainer.appendChild(pageWrapper);

      await page.render({
        canvasContext: context,
        viewport,
      }).promise;
    }
  }

  function ensureRenderedResume() {
    if (!renderPromise) {
      renderPromise = renderResumeForPrint().catch(function (error) {
        renderPromise = undefined;
        throw error;
      });
    }

    return renderPromise;
  }

  printButton.addEventListener("click", async function () {
    const originalLabel = printButton.textContent;

    printButton.disabled = true;
    printButton.textContent = "Preparing PDF...";

    try {
      await ensureRenderedResume();
      printButton.textContent = "Opening Print Dialog...";
      window.print();
    } catch (error) {
      window.open(resumePdfPath, "_blank", "noopener,noreferrer");
    } finally {
      window.setTimeout(function () {
        printButton.disabled = false;
        printButton.textContent = originalLabel;
      }, 300);
    }
  });
});