<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function put_draft(string $key, array $draft): void
{
    if (!isset($_SESSION['draft']) || !is_array($_SESSION['draft'])) {
        $_SESSION['draft'] = [];
    }
    $_SESSION['draft'][$key] = $draft;
}

function take_draft(string $key): ?array
{
    if (empty($_SESSION['draft'][$key]) || !is_array($_SESSION['draft'][$key])) {
        return null;
    }
    $draft = $_SESSION['draft'][$key];
    unset($_SESSION['draft'][$key]);
    return $draft;
}

function handle_login(): void
{
    csrf_check();
    if (login_locked()) {
        render_login('Too many attempts. Try again in a few minutes.');
        exit;
    }
    $password = $_POST['password'] ?? '';
    if (!is_string($password) || !verify_sign_in_password($password)) {
        login_fail();
        $message = login_locked()
            ? 'Too many attempts. Try again in a few minutes.'
            : 'Incorrect password.';
        render_login($message);
        exit;
    }
    login_clear();
    session_regenerate_id(true);
    $_SESSION['notes_auth'] = true;
    redirect_to('view=notes');
}

function handle_choose_password(): void
{
    csrf_check();
    if (sign_in_password_ready()) {
        render_login('A sign-in password is already set.');
        exit;
    }
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if (!is_string($password) || !is_string($confirm) || strlen($password) < 8) {
        render_login('Use at least 8 characters.', true);
        exit;
    }
    if (!hash_equals($password, $confirm)) {
        render_login('Those passwords do not match.', true);
        exit;
    }
    if (!save_sign_in_password($password)) {
        render_login('A sign-in password is already set.');
        exit;
    }
    login_clear();
    session_regenerate_id(true);
    $_SESSION['notes_auth'] = true;
    redirect_to('view=notes');
}

function handle_post(string $action): void
{
    switch ($action) {
        case 'logout':
            logout();
            break;
        case 'save-note':
            save_note_action();
            break;
        case 'delete-note':
            delete_note_action();
            break;
        case 'toggle-pin':
            flip_note_action('pinned');
            break;
        case 'toggle-archive':
            flip_note_action('archived');
            break;
        case 'toggle-task':
            toggle_task_action();
            break;
        case 'save-link':
            save_link_action();
            break;
        case 'delete-link':
            delete_link_action();
            break;
        case 'github-title':
            github_title_action();
            break;
        case 'create-notebook':
            create_notebook_action();
            break;
        case 'rename-notebook':
            rename_notebook_action();
            break;
        case 'delete-notebook':
            delete_notebook_action();
            break;
        case 'journal-seal':
            journal_seal_action();
            break;
        case 'journal-save':
            journal_save_action();
            break;
        case 'journal-delete':
            journal_delete_action();
            break;
        default:
            http_response_code(404);
            exit('Unknown action.');
    }
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    redirect_to('view=notes');
}

function posted_return(string $fallback): string
{
    return safe_query($_POST['return'] ?? '', $fallback);
}

function save_note_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = normalize_newlines((string) ($_POST['body'] ?? ''));
    $tags = (string) ($_POST['tags'] ?? '');
    $notebookId = (int) ($_POST['notebook_id'] ?? 0);
    $pinned = isset($_POST['pinned']);
    $archived = isset($_POST['archived']);
    $return = 'view=edit' . ($id > 0 ? '&id=' . $id : '');

    $draft = [
        'id' => $id,
        'title' => $title,
        'body_md' => $body,
        'tags_text' => $tags,
        'notebook_id' => $notebookId,
        'pinned' => $pinned,
        'archived' => $archived,
    ];

    if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 255) {
        put_draft('note', $draft);
        flash('Give the note a title of 255 characters or fewer.', 'error');
        redirect_to($return);
    }
    if (strlen($body) > 500000) {
        put_draft('note', $draft);
        flash('That note is too long to save.', 'error');
        redirect_to($return);
    }
    if (!notebook_exists(db(), $notebookId)) {
        put_draft('note', $draft);
        flash('Choose a notebook.', 'error');
        redirect_to($return);
    }

    $savedId = note_save(db(), $id, $notebookId, $title, $body, $tags, $pinned, $archived);
    if ($savedId < 1) {
        flash('That note is not available.', 'error');
        redirect_to('view=notes');
    }
    flash('Note saved.');
    redirect_to('view=edit&id=' . $savedId);
}

function delete_note_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $note = note_find(db(), $id);
    if (!$note) {
        flash('That note is not available.', 'error');
        redirect_to('view=notes');
    }
    note_delete(db(), $id);
    flash('Note deleted.');
    redirect_to(posted_return('view=notes'));
}

function flip_note_action(string $flag): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $return = posted_return('view=notes');
    $note = note_find(db(), $id);
    if (!$note || ($flag !== 'pinned' && $flag !== 'archived')) {
        flash('That note is not available.', 'error');
        redirect_to($return);
    }
    $column = $flag === 'pinned' ? 'pinned' : 'archived';
    $stmt = db()->prepare('UPDATE notes SET ' . $column . ' = 1 - ' . $column . ', updated_at = NOW() WHERE id = ?');
    $stmt->execute([$id]);
    flash($flag === 'pinned' ? 'Pin updated.' : 'Archive updated.');
    redirect_to($return);
}

function toggle_task_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $index = (int) ($_POST['task_index'] ?? -1);
    $return = posted_return('view=tasks');
    $note = note_find(db(), $id);
    if (!$note || $index < 0) {
        if (wants_json()) {
            json_out(['ok' => false, 'error' => 'That task is not available.'], 404);
        }
        flash('That task is not available.', 'error');
        redirect_to($return);
    }
    $body = array_key_exists('body', $_POST) ? normalize_newlines((string) $_POST['body']) : $note['body_md'];
    if (strlen($body) > 500000) {
        if (wants_json()) {
            json_out(['ok' => false, 'error' => 'That note is too long.'], 400);
        }
        flash('That note is too long.', 'error');
        redirect_to($return);
    }
    $updated = toggle_task($body, $index);
    note_update_body(db(), $id, $updated);
    if (wants_json()) {
        json_out(['ok' => true, 'body' => $updated]);
    }
    redirect_to($return);
}

function save_link_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $url = trim((string) ($_POST['url'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $note = trim(normalize_newlines((string) ($_POST['note'] ?? '')));
    $tags = (string) ($_POST['tags'] ?? '');
    $notebookId = (int) ($_POST['notebook_id'] ?? 0);
    $return = 'view=links' . ($id > 0 ? '&edit=' . $id : '');
    $draft = [
        'id' => $id,
        'url' => $url,
        'title' => $title,
        'note' => $note,
        'tags_text' => $tags,
        'notebook_id' => $notebookId,
    ];

    if (!valid_http_url($url)) {
        put_draft('link', $draft);
        flash('Enter a full http or https URL.', 'error');
        redirect_to($return);
    }
    if ($title === '') {
        $title = github_title($url) ?? '';
        $draft['title'] = $title;
    }
    if ($title === '') {
        $title = $url;
    }
    $title = limit_text($title, 255);
    if (strlen($note) > 10000) {
        put_draft('link', $draft);
        flash('The link note is too long.', 'error');
        redirect_to($return);
    }
    if (!notebook_exists(db(), $notebookId)) {
        put_draft('link', $draft);
        flash('Choose a notebook.', 'error');
        redirect_to($return);
    }

    $savedId = link_save(db(), $id, $notebookId, $url, $title, $note, $tags);
    if ($savedId < 1) {
        flash('That link is not available.', 'error');
        redirect_to('view=links');
    }
    flash('Link saved.');
    redirect_to('view=links');
}

function delete_link_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if (!link_find(db(), $id)) {
        flash('That link is not available.', 'error');
        redirect_to('view=links');
    }
    link_delete(db(), $id);
    flash('Link deleted.');
    redirect_to('view=links');
}

function github_title_action(): void
{
    $url = trim((string) ($_POST['url'] ?? ''));
    $title = valid_http_url($url) ? github_title($url) : null;
    json_out(['ok' => true, 'title' => $title]);
}

function create_notebook_action(): void
{
    $name = clean_notebook_name((string) ($_POST['name'] ?? ''));
    $return = posted_return('view=notes');
    if ($name === null) {
        flash('Enter a notebook name of 120 characters or fewer.', 'error');
        redirect_to($return);
    }
    if (notebook_name_taken(db(), $name)) {
        flash('A notebook with that name already exists.', 'error');
        redirect_to($return);
    }
    $id = notebook_create(db(), $name);
    flash('Notebook created.');
    redirect_to('view=notes&notebook=' . $id);
}

function rename_notebook_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $name = clean_notebook_name((string) ($_POST['name'] ?? ''));
    $return = posted_return('view=notes&notebook=' . $id);
    if (!notebook_exists(db(), $id) || $name === null) {
        flash('That notebook could not be renamed.', 'error');
        redirect_to('view=notes');
    }
    if (notebook_name_taken(db(), $name, $id)) {
        flash('A notebook with that name already exists.', 'error');
        redirect_to($return);
    }
    notebook_rename(db(), $id, $name);
    flash('Notebook renamed.');
    redirect_to($return);
}

function delete_notebook_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $return = posted_return('view=notes&notebook=' . $id);
    if (!notebook_exists(db(), $id)) {
        flash('That notebook is not available.', 'error');
        redirect_to('view=notes');
    }
    $total = (int) db()->query('SELECT COUNT(*) FROM notebooks')->fetchColumn();
    if ($total <= 1) {
        flash('Keep at least one notebook.', 'error');
        redirect_to($return);
    }
    $counts = notebook_counts(db(), $id);
    if ($counts['notes'] > 0 || $counts['links'] > 0) {
        flash('Move or delete the notes and links in this notebook first.', 'error');
        redirect_to($return);
    }
    notebook_delete(db(), $id);
    flash('Notebook deleted.');
    redirect_to('view=notes');
}

function download_note_action(int $id): void
{
    $note = note_find(db(), $id);
    if (!$note) {
        flash('That note is not available.', 'error');
        redirect_to('view=notes');
    }
    send_download(
        export_filename($note['title'], $note['id']),
        note_to_markdown($note),
        'text/markdown; charset=utf-8'
    );
}

function download_zip_action(): void
{
    $bundle = export_bundle(db());
    $entries = export_entries($bundle['notes'], $bundle['links']);
    if (!$entries) {
        $entries['README.md'] = "No notes yet.\n";
    }
    $bytes = zip_bytes($entries);
    if ($bytes === null) {
        flash('Zip download needs the PHP zip extension on the server.', 'error');
        redirect_to('view=notes');
    }
    send_download('notes-' . date('Y-m-d') . '.zip', $bytes, 'application/zip');
}
