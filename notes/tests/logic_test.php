<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib/clip.php';
require_once dirname(__DIR__) . '/lib/records.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/env.php';
require dirname(__DIR__) . '/lib/journal.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/tasks.php';
require dirname(__DIR__) . '/lib/github.php';
require dirname(__DIR__) . '/lib/export.php';
require_once dirname(__DIR__) . '/lib/tags.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "ok  {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$message}\n";
}

$tags = normalize_tag_list(' PHP, php, Research, , C++ ');
check($tags === ['php', 'research', 'c++'], 'tags are trimmed, unique, and lowercase');
check(normalize_tag_name(str_repeat('a', 81)) === null, 'overlong tags are rejected');

$markdown = "# Plan\n\n- [ ] Read\n- [x] Done\nkeep\n";
$tasks = extract_tasks($markdown);
check(count($tasks) === 2 && $tasks[0]['done'] === false && $tasks[1]['text'] === 'Done', 'tasks are extracted in order');
$toggled = toggle_task($markdown, 0);
check(strpos($toggled, '- [x] Read') !== false && strpos($toggled, "keep\n") !== false, 'the first task flips without changing other lines');
check(extract_tasks(toggle_task($toggled, 0))[0]['done'] === false, 'toggling again reopens the task');
check(toggle_task($markdown, 9) === normalize_newlines($markdown), 'a missing task index leaves the note unchanged');
$fencedMarkdown = "```md\n- [ ] Example\n```\n- [ ] Real task\n";
$fencedTasks = extract_tasks($fencedMarkdown);
check(count($fencedTasks) === 1 && $fencedTasks[0]['line'] === 3, 'tasks inside fenced code blocks are ignored');
$fencedToggle = toggle_task($fencedMarkdown, 0);
check(strpos($fencedToggle, "- [ ] Example") !== false && strpos($fencedToggle, "- [x] Real task") !== false, 'toggling a task leaves fenced examples unchanged');

check(github_api_url('https://github.com/hroark/IronsInTheFire') === 'https://api.github.com/repos/hroark/IronsInTheFire', 'a repo URL becomes the repo API');
check(github_api_url('https://github.com/hroark/IronsInTheFire/issues/12') === 'https://api.github.com/repos/hroark/IronsInTheFire/issues/12', 'an issue URL becomes the issue API');
check(github_api_url('https://github.com/hroark/IronsInTheFire/pull/4') === 'https://api.github.com/repos/hroark/IronsInTheFire/issues/4', 'a pull request URL uses the issue API');
check(github_api_url('https://example.com/hroark/IronsInTheFire') === null, 'other hosts are ignored');
check(github_api_url('https://user:token@github.com/hroark/IronsInTheFire') === 'https://api.github.com/repos/hroark/IronsInTheFire', 'userinfo is not forwarded');

check(valid_http_url('https://example.com/a') === true, 'https URLs are accepted');
check(valid_http_url('javascript:alert(1)') === false, 'javascript URLs are rejected');
check(safe_query("view=notes&notebook=1", 'view=notes') === 'view=notes&notebook=1', 'internal return targets are kept');
check(safe_query("https://evil.example", 'view=notes') === 'view=notes', 'external return targets are dropped');

$note = [
    'id' => 3,
    'notebook_id' => 1,
    'notebook' => 'Inbox',
    'title' => 'Say "hello"',
    'body_md' => "Line\n",
    'archived' => false,
    'tags' => ['php'],
    'created_at' => '2026-01-02 03:04:05',
    'updated_at' => '2026-01-03 03:04:05',
];
$markdownNote = note_to_markdown($note);
check(strpos($markdownNote, 'title: "Say \\"hello\\""') !== false, 'front matter quotes the title');
check(strpos($markdownNote, 'tags: ["php"]') !== false, 'front matter includes tags');
check(substr($markdownNote, -5) === "Line\n", 'the note body follows the front matter');

$entries = export_entries(
    [
        $note,
        [
            'id' => 4,
            'notebook_id' => 1,
            'notebook' => 'Inbox',
            'title' => 'Old',
            'body_md' => "Archived\n",
            'archived' => true,
            'tags' => [],
            'created_at' => '2026-01-02 03:04:05',
            'updated_at' => '2026-01-03 03:04:05',
        ],
    ],
    [
        [
            'id' => 8,
            'notebook_id' => 1,
            'notebook' => 'Inbox',
            'url' => 'https://example.com',
            'title' => 'Example',
            'note' => 'Later',
            'tags' => ['research'],
            'created_at' => '2026-01-04 00:00:00',
        ],
    ]
);
check(isset($entries['Inbox/Say-hello-3.md']), 'an active note is filed under its notebook');
check(isset($entries['archive/Inbox/Old-4.md']), 'an archived note is filed under archive');
check(isset($entries['Inbox/links.md']) && strpos($entries['Inbox/links.md'], 'https://example.com') !== false, 'links.md includes the saved URL');
check(like_contains('100%_done') === '%100\\%\\_done%', 'search wildcards are escaped');

$envText = "# comment\nMYSQL_HOST=127.0.0.1\nMYSQL_PORT=3306\nMYSQL_USER=notes\nMYSQL_PASSWORD=secret#29\nMYSQL_DATABASE=irondb\n";
$parsed = config_from_env(read_env_text($envText));
check(is_array($parsed) && $parsed['db']['name'] === 'porter_notes', 'the renamed database name is porter_notes');
check(is_array($parsed) && $parsed['db']['pass'] === 'secret#29', 'a hash mark inside a password stays in the password');
check(is_array($parsed) && mysql_dsn($parsed['db']) === 'mysql:host=127.0.0.1;port=3306;dbname=porter_notes;charset=utf8mb4', 'the MySQL port is included for 127.0.0.1');
check(mysql_dsn(['host' => 'localhost', 'port' => '3306', 'name' => 'porter_notes']) === 'mysql:host=localhost;dbname=porter_notes;charset=utf8mb4', 'localhost keeps the default socket');
check(config_from_env([]) === null, 'empty settings are not a configuration');
check(notes_database_name('porter_notes') === 'porter_notes', 'porter_notes is kept as the database name');

$signInHash = password_hash('diary-password-1', PASSWORD_DEFAULT);
$mysqlSetting = 'example' . '@' . 'hash' . '#' . 'mark';
$phpConfig = "<?php\n# Copy this file to .env and fill in the MySQL database you created.\nMYSQL_HOST=127.0.0.1\nMYSQL_PORT=3306\nMYSQL_USER=notes\nMYSQL_PASSWORD={$mysqlSetting}\nMYSQL_DATABASE=porter_notes\nNOTES_PASSWORD_HASH='{$signInHash}'\n";
$fromPhpConfig = config_from_text($phpConfig);
check(is_array($fromPhpConfig) && $fromPhpConfig['db']['pass'] === $mysqlSetting, 'a php.config password keeps @ and #');
check(is_array($fromPhpConfig) && $fromPhpConfig['password_hash'] === $signInHash, 'a quoted NOTES_PASSWORD_HASH is kept whole');
check(is_array($fromPhpConfig) && password_verify('diary-password-1', $fromPhpConfig['password_hash']), 'the quoted hash still verifies');

$emptyExample = ['db' => ['host' => '127.0.0.1', 'user' => 'notes', 'name' => 'porter_notes', 'pass' => ''], 'password_hash' => ''];
$realSettings = ['db' => ['host' => '127.0.0.1', 'user' => 'notes', 'name' => 'porter_notes', 'pass' => $mysqlSetting], 'password_hash' => $signInHash];
$preferred = preferred_notes_config([$emptyExample, $realSettings]);
check(is_array($preferred) && ($preferred['db']['pass'] ?? '') === $mysqlSetting, 'settings that include a database password are used');
$attempts = database_attempts(['host' => '127.0.0.1', 'port' => '3306', 'name' => 'porter_notes']);
check(count($attempts) === 2 && $attempts[1]['host'] === 'localhost', '127.0.0.1 also tries the localhost socket');
check(database_failure_message(1045, '127.0.0.1', 'notes', 'porter_notes', true, false) !== '' && strpos(database_failure_message(1045, '127.0.0.1', 'notes', 'porter_notes', true, false), 'localhost') !== false, 'a rejected login mentions localhost');
check(strpos(database_failure_message(1049, '127.0.0.1', 'notes', 'porter_notes', false, false), 'porter_notes') !== false, 'a missing database names the database');
check(journal_valid_blob('abc+/=_-', 20) === true, 'journal ciphertext uses a limited alphabet');
check(journal_valid_blob('hello there', 20) === false, 'journal ciphertext rejects spaces');
check(journal_iterations('310000') === 310000, 'journal key stretching accepts the browser setting');
check(journal_iterations('1000') === null, 'journal key stretching rejects a short setting');

$captured = capture_fields('https://example.com/path', "  A page\n", "Selected line\r\n");
check(is_array($captured) && $captured['title'] === 'A page', 'a clipped title keeps the page title');
check(is_array($captured) && $captured['body'] === "Source: https://example.com/path\n\nSelected line\n", 'a clipped note stores the URL and the selection');
$untitled = capture_fields('https://example.com/path', '   ', '');
check(is_array($untitled) && $untitled['title'] === 'https://example.com/path', 'a blank page title falls back to the URL');
check(is_array($untitled) && $untitled['body'] === "Source: https://example.com/path\n", 'a clip without a selection stores only the URL');
check(capture_fields('javascript:alert(1)', 'Title', 'text') === null, 'a clipped page must use http or https');
$longTitle = capture_fields('https://example.com/path', str_repeat('á', 300), str_repeat('b', 100050));
$titleLength = function_exists('mb_strlen') ? mb_strlen($longTitle['title'], 'UTF-8') : strlen($longTitle['title']);
check(is_array($longTitle) && $titleLength === 255, 'a clipped title stops at 255 characters');
check(is_array($longTitle) && strlen($longTitle['body']) < 500000, 'a clipped selection stays within a note body');
$bookmarklet = clip_bookmarklet('https://example.com/notes/index.php');
check(strpos($bookmarklet, 'javascript:') === 0, 'the bookmarklet is a javascript URL');
check(strpos($bookmarklet, 'https://example.com/notes/index.php?view=clip') !== false, 'the bookmarklet opens the clip page');
check(strpos($bookmarklet, 'notebook') === false, 'the bookmarklet does not choose a notebook');

$reordered = notebook_id_order([1, 2, 3], [3, 1, 2]);
check($reordered === [3, 1, 2], 'notebook order accepts the same folders in a new sequence');
check(notebook_id_order([1, 2], [1]) === null, 'notebook order rejects a missing folder');
check(notebook_id_order([1, 2], [1, 2, 9]) === null, 'notebook order rejects an unknown folder');
check(notebook_id_order([1, 2], [1, 1]) === null, 'notebook order rejects a repeated folder');

check(snapshot_classify_status(200) === 'up', 'a successful response is up');
check(snapshot_classify_status(302) === 'up', 'a redirect is up');
check(snapshot_classify_status(404) === 'missing', '404 means the page is gone');
check(snapshot_classify_status(410) === 'missing', '410 means the page is gone');
check(snapshot_classify_status(401) === 'blocked', '401 is a sign-in wall');
check(snapshot_classify_status(403) === 'blocked', '403 is a sign-in wall');
check(snapshot_classify_status(500) === 'unreachable', 'a server error is unreachable');
check(snapshot_classify_status(null) === 'unreachable', 'a missing status is unreachable');
check(snapshot_classify_status(0) === 'unreachable', 'a zero status is unreachable');

check(snapshot_ip_is_private('127.0.0.1') === true, 'loopback is private');
check(snapshot_ip_is_private('10.1.2.3') === true, '10.0.0.0/8 is private');
check(snapshot_ip_is_private('172.16.0.1') === true, '172.16.0.0/12 is private');
check(snapshot_ip_is_private('192.168.1.20') === true, '192.168.0.0/16 is private');
check(snapshot_ip_is_private('169.254.169.254') === true, 'the link-local metadata address is private');
check(snapshot_ip_is_private('100.64.1.1') === true, 'carrier-grade NAT is private');
check(snapshot_ip_is_private('::1') === true, 'IPv6 loopback is private');
check(snapshot_ip_is_private('fe80::1') === true, 'IPv6 link-local is private');
check(snapshot_ip_is_private('::ffff:127.0.0.1') === true, 'an IPv4-mapped loopback address is private');
check(snapshot_ip_is_private('8.8.8.8') === false, 'a public IPv4 address is allowed');
check(snapshot_ip_is_private('::ffff:8.8.8.8') === false, 'an IPv4-mapped public address is allowed');
check(snapshot_url_is_allowed('http://127.0.0.1/') === false, 'a loopback URL is refused');
check(snapshot_url_is_allowed('http://169.254.169.254/latest/meta-data') === false, 'a metadata URL is refused');
check(snapshot_url_is_allowed('https://localhost/notes') === false, 'localhost is refused');
check(snapshot_url_is_allowed('http://10.0.0.8/a') === false, 'a private-network URL is refused');
check(snapshot_url_is_allowed('http://user:secret@example.com/') === false, 'a URL with a password is refused');
check(snapshot_url_is_allowed('javascript:alert(1)') === false, 'a javascript URL is refused');
check(snapshot_url_is_allowed('https://8.8.8.8/dns') === true, 'a public IP URL is allowed');

$html = '<html><head><title>Document title</title>'
    . '<meta name="description" content="Plain description">'
    . '<meta property="og:title" content="Open title">'
    . '<meta property="og:description" content="Open description">'
    . '<meta property="og:site_name" content="Example">'
    . '<meta property="og:image" content="/cover.jpg">'
    . '</head><body><script>secret()</script><style>.x{}</style><p>Visible words</p></body></html>';
$meta = snapshot_meta($html, 'https://example.com/posts/1');
check($meta['title'] === 'Open title', 'the Open Graph title wins over the document title');
check($meta['description'] === 'Open description', 'the Open Graph description wins over the name description');
check($meta['site_name'] === 'Example', 'the site name is read');
check($meta['image_url'] === 'https://example.com/cover.jpg', 'a root-relative preview image is resolved');
check($meta['text'] === 'Visible words', 'scripts and styles are left out of the saved text');
$named = snapshot_meta('<html><head><title>Only title</title><meta name="description" content="Named"></head><body><p>Body</p></body></html>', 'https://example.com/');
check($named['title'] === 'Only title' && $named['description'] === 'Named', 'a page without Open Graph tags uses the title and description');
check(snapshot_resolve_url('https://example.com/dir/page', 'pic.png') === 'https://example.com/dir/pic.png', 'a relative image URL stays in its directory');
check(snapshot_resolve_url('https://example.com/dir/page', '../pic.png') === 'https://example.com/pic.png', 'a parent-relative image URL is normalized');
check(snapshot_resolve_url('https://example.com/dir/page', '//cdn.example.com/a.jpg') === 'https://cdn.example.com/a.jpg', 'a protocol-relative image URL keeps the page scheme');
check(snapshot_resolve_url('https://example.com/', 'javascript:alert(1)') === null, 'a javascript image URL is dropped');
$longText = snapshot_clean_text(str_repeat('a', 50000));
$longLength = function_exists('mb_strlen') ? mb_strlen($longText, 'UTF-8') : strlen($longText);
check($longLength === 40000, 'saved page text stops at 40000 characters');
check(snapshot_replaces_body('up', 'Already saved') === true, 'a live page replaces the saved copy');
check(snapshot_replaces_body('missing', 'Already saved') === false, 'a missing page keeps the saved copy');
check(snapshot_replaces_body('unreachable', '') === true, 'the first capture stores whatever came back');
check(snapshot_image_extension("\xFF\xD8\xFF\x00") === 'jpg', 'a JPEG preview is recognized');
check(snapshot_image_extension('<html>') === null, 'HTML is not stored as a preview image');
$statusMarkdown = links_to_markdown([
    [
        'title' => 'Example',
        'url' => 'https://example.com',
        'tags' => [],
        'created_at' => '2026-01-04 00:00:00',
        'note' => '',
        'page_description' => 'A page',
        'link_state' => 'missing',
    ],
]);
check(strpos($statusMarkdown, 'Description: A page') !== false && strpos($statusMarkdown, 'Status: Gone') !== false, 'links.md includes the description and the last status');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

echo "all checks passed\n";
