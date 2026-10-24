(function (root, factory) {
  const api = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }
  root.LogMarkdown = api;
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function isSafeUrl(url) {
    const trimmed = String(url || "").trim();
    return /^(https?:|mailto:)/i.test(trimmed) && !/[\s<>"]/.test(trimmed);
  }

  function renderInline(text) {
    let html = "";
    let index = 0;

    while (index < text.length) {
      if (text[index] === "`") {
        const end = text.indexOf("`", index + 1);
        if (end > index + 1) {
          html += "<code>" + escapeHtml(text.slice(index + 1, end)) + "</code>";
          index = end + 1;
          continue;
        }
      }

      if (text.startsWith("**", index) || text.startsWith("__", index)) {
        const marker = text.slice(index, index + 2);
        const end = text.indexOf(marker, index + 2);
        if (end !== -1) {
          html += "<strong>" + renderInline(text.slice(index + 2, end)) + "</strong>";
          index = end + 2;
          continue;
        }
      }

      if (text[index] === "*" || text[index] === "_") {
        const marker = text[index];
        const previous = index === 0 ? "" : text[index - 1];
        const next = text[index + 1] || "";
        const wordChar = /[\w]/;
        if (!wordChar.test(previous) && next && next !== marker && !/\s/.test(next)) {
          const end = text.indexOf(marker, index + 1);
          if (end !== -1 && end > index + 1 && !/\s/.test(text[end - 1])) {
            html += "<em>" + renderInline(text.slice(index + 1, end)) + "</em>";
            index = end + 1;
            continue;
          }
        }
      }

      if (text[index] === "[") {
        const match = /^\[([^\]\n]+)\]\(([^)\s]+)\)/.exec(text.slice(index));
        if (match && isSafeUrl(match[2])) {
          html +=
            '<a href="' +
            escapeHtml(match[2]) +
            '" target="_blank" rel="noopener noreferrer">' +
            renderInline(match[1]) +
            "</a>";
          index += match[0].length;
          continue;
        }
      }

      html += escapeHtml(text[index]);
      index += 1;
    }

    return html;
  }

  function isHeading(line) {
    return /^(#{1,3})\s+\S/.test(line);
  }

  function isRule(line) {
    return /^(-{3,}|\*{3,}|_{3,})$/.test(line.trim());
  }

  function isUnordered(line) {
    return /^\s*[-*]\s+\S/.test(line);
  }

  function isOrdered(line) {
    return /^\s*\d+\.\s+\S/.test(line);
  }

  function isQuote(line) {
    return /^\s*>\s?/.test(line);
  }

  function render(source) {
    const lines = String(source || "").replace(/\r\n/g, "\n").split("\n");
    const blocks = [];
    let index = 0;

    while (index < lines.length) {
      const line = lines[index];

      if (line.trim() === "") {
        index += 1;
        continue;
      }

      if (line.startsWith("```")) {
        const code = [];
        index += 1;
        while (index < lines.length && !lines[index].startsWith("```")) {
          code.push(lines[index]);
          index += 1;
        }
        if (index < lines.length) index += 1;
        blocks.push("<pre><code>" + escapeHtml(code.join("\n")) + "</code></pre>");
        continue;
      }

      if (isHeading(line)) {
        const level = line.match(/^#+/)[0].length;
        blocks.push("<h" + level + ">" + renderInline(line.replace(/^#{1,3}\s+/, "")) + "</h" + level + ">");
        index += 1;
        continue;
      }

      if (isRule(line)) {
        blocks.push("<hr>");
        index += 1;
        continue;
      }

      if (isQuote(line)) {
        const quoted = [];
        while (index < lines.length && isQuote(lines[index])) {
          quoted.push(lines[index].replace(/^\s*>\s?/, ""));
          index += 1;
        }
        blocks.push("<blockquote><p>" + quoted.map(renderInline).join("<br>") + "</p></blockquote>");
        continue;
      }

      if (isUnordered(line) || isOrdered(line)) {
        const ordered = isOrdered(line);
        const items = [];
        const test = ordered ? isOrdered : isUnordered;
        while (index < lines.length && test(lines[index])) {
          items.push(lines[index].replace(ordered ? /^\s*\d+\.\s+/ : /^\s*[-*]\s+/, ""));
          index += 1;
        }
        const tag = ordered ? "ol" : "ul";
        blocks.push(
          "<" + tag + ">" + items.map((item) => "<li>" + renderInline(item) + "</li>").join("") + "</" + tag + ">"
        );
        continue;
      }

      const paragraph = [];
      while (
        index < lines.length &&
        lines[index].trim() !== "" &&
        !lines[index].startsWith("```") &&
        !isHeading(lines[index]) &&
        !isRule(lines[index]) &&
        !isQuote(lines[index]) &&
        !isUnordered(lines[index]) &&
        !isOrdered(lines[index])
      ) {
        paragraph.push(lines[index]);
        index += 1;
      }
      blocks.push("<p>" + paragraph.map(renderInline).join("<br>") + "</p>");
    }

    return blocks.join("");
  }

  return { render, escapeHtml };
});
