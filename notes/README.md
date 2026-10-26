# Notes

Password-protected notes for this site. It keeps Markdown notes, notebooks, tags, task lists, and saved links in MySQL. You can download one note as Markdown, or everything as a zip file. The Journal link in the notes sidebar opens a separate diary. That diary has its own password. The browser encrypts each entry before saving it, and MySQL stores only ciphertext. The journal password is never sent to the server, and it cannot be recovered or changed. The zip download does not include journal entries. The journal tables are created on the first signed-in visit when the database user can create tables.

This folder has to be served by PHP. A static host such as GitHub Pages cannot run it.

## Requirements

- PHP 8.0 or newer with the `pdo_mysql`, `mbstring`, `curl`, and `zip` extensions
- MySQL or MariaDB
- HTTPS on the public site

## Setup

The hosted site reads `public_html/notes/config.php`. There is no `.env` folder.

1. Create a MySQL database named `porter_notes` and a user that can use it. If the database was named `irondb`, renaming it to `porter_notes` is the right name.
2. Import [`sql/schema.sql`](sql/schema.sql) into `porter_notes` if the tables are not there yet. The notes page also creates them on the first successful connection.
3. Put the database host, name, user, and password in `public_html/notes/config.php`. A `php.config` file in that folder, or in the site root, is read when `config.php` is missing. Either the PHP settings in `config.example.php`, or the `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`, and `NOTES_PASSWORD_HASH` lines, are accepted. Keep a `#` inside the database password, and keep single quotes around `NOTES_PASSWORD_HASH`. `MYSQL_DATABASE` should be `porter_notes`.
4. Open `/notes/`. When `NOTES_PASSWORD_HASH` is set, sign in with that password. Otherwise the first visit asks you to choose one. That password is only for the notes page. It is separate from the MySQL password. If the page says MySQL rejected the user, set `MYSQL_HOST` to `localhost`. Use the database name exactly as the host panel shows it.

`config.php` is gitignored, so it stays on the server and out of git. Apache is told not to serve it, or the example file, via `notes/.htaccess`. The `lib`, `templates`, `sql`, and `tests` directories are blocked the same way.

## Using it

- Notes are Markdown. The preview is rendered in the browser and sanitized there. A fenced `mermaid` block is drawn as a diagram in that preview. The saved note stays Markdown.
- A task is a line such as `- [ ] Read the RFC`. Checking it in the preview, or on Open tasks, rewrites that line and saves the note.
- Tags are shared by notes and links. Separate names with commas. `PHP` and `php` are the same tag. A tag is removed when nothing uses it.
- Archived notes leave All notes, notebook lists, search, and Open tasks. They still appear under their tags, and they download under `archive/` in the zip.
- Pinned notes stay at the top. Lists then sort by the last update. Links sort newest first.
- Leave a GitHub link title blank to fill it from the public GitHub API. Private GitHub URLs keep whatever title you type. Nothing is written back to GitHub.
- Download the open note as Markdown, or use Download zip for every notebook. Each note starts with title, notebook, tags, and dates. Each notebook folder includes `links.md` when that notebook has links or current notes. The zip does not include uploaded files.
- Files keeps images and documents in folders. Drop them on the page, drop them on a folder, or use Browse. A file can be moved into another folder. Notebooks, tags, and the library stay folded in the sidebar until you open them.

Sign-in allows five failures, then waits fifteen minutes. The password check uses `password_verify`. Opening the notes section from the rest of the site, a bookmark, or a new visit asks for the password again. Moving between notes pages in that visit does not. Forms send a session token so another site cannot submit them for you.
