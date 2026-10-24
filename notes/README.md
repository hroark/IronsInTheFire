# Notes

Password-protected notes for this site. It keeps Markdown notes, notebooks, tags, task lists, and saved links in MySQL. You can download one note as Markdown, or everything as a zip file.

This folder has to be served by PHP. A static host such as GitHub Pages cannot run it.

## Requirements

- PHP 8.0 or newer with the `pdo_mysql`, `mbstring`, `curl`, and `zip` extensions
- MySQL or MariaDB
- HTTPS on the public site

## Setup

1. Create an empty database and a user that can use it.
2. Import [`sql/schema.sql`](sql/schema.sql). That creates the tables and an Inbox notebook.
3. Copy `config.example.php` to `config.php`.
4. Set the database host, name, user, and password in `config.php`. Use `127.0.0.1` for the host if `localhost` cannot find the MySQL socket.
5. Generate the site password hash on the server and paste it into `password_hash`:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

6. Open `/notes/` and sign in.

`config.php` is gitignored. Apache is also told not to serve it, or the example file, via `notes/.htaccess`. The `lib`, `templates`, `sql`, and `tests` directories are blocked the same way.

## Using it

- Notes are Markdown. The preview is rendered in the browser and sanitized there.
- A task is a line such as `- [ ] Read the RFC`. Checking it in the preview, or on Open tasks, rewrites that line and saves the note.
- Tags are shared by notes and links. Separate names with commas. `PHP` and `php` are the same tag. A tag is removed when nothing uses it.
- Archived notes leave All notes, notebook lists, search, and Open tasks. They still appear under their tags, and they download under `archive/` in the zip.
- Pinned notes stay at the top. Lists then sort by the last update. Links sort newest first.
- Leave a GitHub link title blank to fill it from the public GitHub API. Private GitHub URLs keep whatever title you type. Nothing is written back to GitHub.
- Download the open note as Markdown, or use Download zip for every notebook. Each note starts with title, notebook, tags, and dates. Each notebook folder includes `links.md` when that notebook has links or current notes.

Sign-in allows five failures, then waits fifteen minutes. The password check uses `password_verify`. Forms send a session token so another site cannot submit them for you.
