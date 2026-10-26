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
    redirect_after_login();
}

function redirect_after_login(): void
{
    $next = 'view=notes';
    if (($_SESSION['after_login'] ?? '') === 'view=clip') {
        $next = 'view=clip';
    }
    unset($_SESSION['after_login']);
    redirect_to($next);
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
    redirect_after_login();
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
        case 'move-note':
            move_note_action();
            break;
        case 'capture':
            capture_action();
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
        case 'refresh-link':
            refresh_link_action();
            break;
        case 'check-link':
            check_link_action();
            break;
        case 'check-links':
            check_links_action();
            break;
        case 'github-title':
            github_title_action();
            break;
        case 'create-notebook':
            create_notebook_action();
            break;
        case 'reorder-notebooks':
            reorder_notebooks_action();
            break;
        case 'rename-notebook':
            rename_notebook_action();
            break;
        case 'delete-notebook':
            delete_notebook_action();
            break;
        case 'create-folder':
            create_folder_action();
            break;
        case 'delete-folder':
            delete_folder_action();
            break;
        case 'upload-files':
            upload_files_action();
            break;
        case 'move-file':
            move_file_action();
            break;
        case 'delete-file':
            delete_file_action();
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
    $existing = $id > 0 ? link_find(db(), $id) : null;
    $capture = snapshot_capture($url);
    if ($title === '') {
        $title = github_title($url) ?? '';
    }
    if ($title === '') {
        $title = $capture['title'] !== '' ? $capture['title'] : $url;
    }
    $draft['title'] = $title;
    $title = limit_text($title, 255);

    $savedId = link_save(db(), $id, $notebookId, $url, $title, $note, $tags);
    if ($savedId < 1) {
        flash('That link is not available.', 'error');
        redirect_to('view=links');
    }
    remember_link_capture($savedId, $capture, $existing, $url);
    flash('Link saved.');
    redirect_to('view=links');
}

function link_return_query(): string
{
    return safe_query((string) ($_POST['return'] ?? ''), 'view=links');
}

function remember_link_capture(int $id, array $capture, ?array $previous, string $url): void
{
    $previousText = (string) ($previous['snapshot_text'] ?? '');
    $preview = $previous['preview_path'] ?? null;
    $preview = is_string($preview) && $preview !== '' ? $preview : null;
    if (!snapshot_replaces_body((string) $capture['state'], $previousText)) {
        link_set_check(db(), $id, (string) $capture['state'], $capture['status'] ?? null);
        return;
    }
    $urlChanged = $previous !== null && (string) $previous['url'] !== $url;
    if ($capture['state'] === 'up' && $capture['image_extension'] !== null && $capture['image_bytes'] !== '') {
        $stored = snapshot_store_image($id, (string) $capture['image_bytes'], (string) $capture['image_extension'], $preview);
        if ($stored !== null) {
            $preview = $stored;
        }
    } elseif ($capture['state'] === 'up' || $urlChanged) {
        snapshot_delete_file($preview);
        $preview = null;
    }
    link_set_snapshot(db(), $id, $capture, $preview);
}

function refresh_link_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $return = link_return_query();
    $link = link_find(db(), $id);
    if (!$link) {
        flash('That link is not available.', 'error');
        redirect_to($return);
    }
    $capture = snapshot_capture((string) $link['url']);
    $kept = !snapshot_replaces_body((string) $capture['state'], (string) $link['snapshot_text']);
    remember_link_capture($id, $capture, $link, (string) $link['url']);
    if ($capture['state'] === 'missing') {
        flash($kept ? 'That page looks gone. The last saved copy was kept.' : 'That page looks gone.');
    } elseif ($capture['state'] === 'unreachable') {
        flash($kept ? 'The page could not be reached. The last saved copy was kept.' : 'The page could not be reached.');
    } elseif ($capture['state'] === 'blocked') {
        flash($kept ? 'That page asks for a sign-in. The last saved copy was kept.' : 'That page asks for a sign-in.');
    } else {
        flash('Snapshot refreshed.');
    }
    redirect_to($return);
}

function check_link_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $return = link_return_query();
    $link = link_find(db(), $id);
    if (!$link) {
        flash('That link is not available.', 'error');
        redirect_to($return);
    }
    $probe = snapshot_probe((string) $link['url']);
    link_set_check(db(), $id, $probe['state'], $probe['status']);
    if ($probe['state'] === 'missing') {
        flash('That page looks gone.');
    } elseif ($probe['state'] === 'unreachable') {
        flash('That page could not be reached.');
    } elseif ($probe['state'] === 'blocked') {
        flash('That page asks for a sign-in. It is still listed as available.');
    } else {
        flash('That page is still there.');
    }
    redirect_to($return);
}

function check_links_action(): void
{
    set_time_limit(90);
    $rows = links_needing_check(db(), 10);
    foreach ($rows as $row) {
        $probe = snapshot_probe((string) $row['url']);
        link_set_check(db(), (int) $row['id'], $probe['state'], $probe['status']);
    }
    $count = count($rows);
    $remaining = links_unchecked_count(db());
    if ($count === 0) {
        flash('There are no links to check.');
    } elseif ($remaining > 0) {
        $checkedLabel = $count === 1 ? '1 link' : $count . ' links';
        $remainLabel = $remaining === 1 ? '1 still needs a check.' : $remaining . ' still need a check.';
        flash('Checked ' . $checkedLabel . '. ' . $remainLabel);
    } else {
        flash($count === 1 ? 'Checked 1 link.' : 'Checked ' . $count . ' links.');
    }
    redirect_to('view=links');
}

function link_image_action(int $id): void
{
    $link = link_find(db(), $id);
    $name = is_array($link) ? (string) ($link['preview_path'] ?? '') : '';
    $path = snapshot_file_path($name);
    $type = snapshot_image_type($name);
    if ($path === null || $type === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

function delete_link_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $return = link_return_query();
    $link = link_find(db(), $id);
    if (!$link) {
        flash('That link is not available.', 'error');
        redirect_to($return);
    }
    snapshot_delete_file($link['preview_path'] ?? null);
    link_delete(db(), $id);
    flash('Link deleted.');
    redirect_to($return);
}

function files_return(?int $folderId): string
{
    return $folderId !== null && $folderId > 0 ? 'view=files&folder=' . $folderId : 'view=files';
}

function create_folder_action(): void
{
    $parent = (int) ($_POST['folder_id'] ?? 0);
    $parentId = $parent > 0 ? $parent : null;
    $name = upload_folder_name((string) ($_POST['name'] ?? ''));
    $return = files_return($parentId);
    if ($name === null) {
        flash('Enter a folder name without slashes.', 'error');
        redirect_to($return);
    }
    if ($parentId !== null && !upload_folder_find(db(), $parentId)) {
        flash('That folder is not available.', 'error');
        redirect_to('view=files');
    }
    $parents = upload_parent_map(upload_folders_all(db()));
    if ($parentId !== null && upload_folder_depth($parentId, $parents) > upload_max_folder_depth()) {
        flash('That folder is nested too deeply.', 'error');
        redirect_to($return);
    }
    if (upload_folder_name_taken(db(), $parentId, $name)) {
        flash('A folder with that name is already here.', 'error');
        redirect_to($return);
    }
    $id = upload_folder_create(db(), $parentId, $name);
    flash('Folder created.');
    redirect_to(files_return($id));
}

function delete_folder_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $folder = upload_folder_find(db(), $id);
    if (!$folder) {
        flash('That folder is not available.', 'error');
        redirect_to('view=files');
    }
    $counts = upload_folder_counts(db(), $id);
    if ($counts['files'] > 0 || $counts['folders'] > 0) {
        flash('Move or delete the files and folders inside this folder first.', 'error');
        redirect_to(files_return($id));
    }
    upload_folder_delete(db(), $id);
    flash('Folder deleted.');
    redirect_to(files_return($folder['parent_id']));
}

function upload_files_action(): void
{
    $postedFolder = (int) ($_POST['folder_id'] ?? 0);
    $folderId = null;
    if ($postedFolder > 0) {
        if (!upload_folder_find(db(), $postedFolder)) {
            flash('That folder is not available.', 'error');
            redirect_to('view=files');
        }
        $folderId = $postedFolder;
    }
    $incoming = uploaded_file_list('files');
    if (!$incoming) {
        flash('Choose a file to upload.', 'error');
        redirect_to(files_return($folderId));
    }
    if (count($incoming) > 10) {
        flash('Upload up to 10 files at a time.', 'error');
        redirect_to(files_return($folderId));
    }
    $saved = 0;
    $skipped = [];
    foreach ($incoming as $file) {
        $label = upload_safe_name((string) $file['name']);
        if ((int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] < 1 || (int) $file['size'] > upload_max_bytes()) {
            $skipped[] = $label;
            continue;
        }
        $tmp = (string) $file['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $skipped[] = $label;
            continue;
        }
        $bytes = file_get_contents($tmp);
        $accepted = is_string($bytes) ? upload_accept((string) $file['name'], $bytes) : null;
        if ($accepted === null) {
            $skipped[] = $label;
            continue;
        }
        $stored = upload_write_stored($bytes, $accepted['extension']);
        if ($stored === null) {
            $skipped[] = $label;
            continue;
        }
        try {
            upload_insert(db(), $folderId, $accepted['name'], $stored, $accepted['mime'], strlen($bytes));
            $saved++;
        } catch (Throwable $e) {
            upload_delete_stored($stored);
            throw $e;
        }
    }
    if ($saved === 0) {
        flash('None of those files could be saved. Use an image, PDF, text, zip, or Office file under 8 MB.', 'error');
    } elseif ($skipped) {
        flash('Saved ' . $saved . ' ' . ($saved === 1 ? 'file' : 'files') . '. Skipped ' . implode(', ', $skipped) . '.');
    } else {
        flash('Saved ' . $saved . ' ' . ($saved === 1 ? 'file' : 'files') . '.');
    }
    redirect_to(files_return($folderId));
}

function move_file_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $file = upload_find(db(), $id);
    if (!$file) {
        flash('That file is not available.', 'error');
        redirect_to('view=files');
    }
    $folderId = (int) ($_POST['folder_id'] ?? 0);
    $target = null;
    if ($folderId > 0) {
        if (!upload_folder_find(db(), $folderId)) {
            flash('That folder is not available.', 'error');
            redirect_to(files_return($file['folder_id']));
        }
        $target = $folderId;
    }
    upload_move(db(), $id, $target);
    flash('File moved.');
    redirect_to(files_return($target));
}

function delete_file_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $file = upload_find(db(), $id);
    if (!$file) {
        flash('That file is not available.', 'error');
        redirect_to('view=files');
    }
    upload_delete_stored($file['stored_name']);
    upload_delete(db(), $id);
    flash('File deleted.');
    redirect_to(files_return($file['folder_id']));
}

function file_download_action(int $id): void
{
    $file = upload_find(db(), $id);
    $path = $file ? upload_file_path($file['stored_name']) : null;
    if ($file === null || $path === null) {
        http_response_code(404);
        exit;
    }
    $download = (string) ($_GET['download'] ?? '') === '1' || !$file['image'];
    $disposition = $download ? 'attachment' : 'inline';
    header('Content-Type: ' . upload_content_type($file['extension']));
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: ' . $disposition . '; filename="' . upload_download_name($file['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function github_title_action(): void
{
    $url = trim((string) ($_POST['url'] ?? ''));
    $title = valid_http_url($url) ? github_title($url) : null;
    json_out(['ok' => true, 'title' => $title]);
}

function move_note_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $notebookId = (int) ($_POST['notebook_id'] ?? 0);
    $return = posted_return('view=notes');
    if (!note_find(db(), $id) || !notebook_exists(db(), $notebookId)) {
        if (wants_json()) {
            json_out(['ok' => false, 'error' => 'That note could not be moved.'], 400);
        }
        flash('That note could not be moved.', 'error');
        redirect_to($return);
    }
    note_move(db(), $id, $notebookId);
    if (wants_json()) {
        json_out(['ok' => true, 'notebook_id' => $notebookId]);
    }
    flash('Note moved.');
    redirect_to($return);
}

function capture_action(): void
{
    $fields = capture_fields(
        (string) ($_POST['url'] ?? ''),
        (string) ($_POST['title'] ?? ''),
        (string) ($_POST['selection'] ?? '')
    );
    if ($fields === null) {
        if (wants_json()) {
            json_out(['ok' => false, 'error' => 'Enter a full http or https URL.'], 400);
        }
        flash('Enter a full http or https URL.', 'error');
        redirect_to('view=clip');
    }
    $inbox = inbox_notebook(db());
    $savedId = note_save(db(), 0, (int) $inbox['id'], $fields['title'], $fields['body'], '', false, false);
    if ($savedId < 1) {
        if (wants_json()) {
            json_out(['ok' => false, 'error' => 'That page could not be saved.'], 400);
        }
        flash('That page could not be saved.', 'error');
        redirect_to('view=clip');
    }
    if (wants_json()) {
        json_out(['ok' => true, 'id' => $savedId]);
    }
    flash('Saved to Inbox.');
    redirect_to('view=notes&notebook=' . (int) $inbox['id']);
}

function reorder_notebooks_action(): void
{
    $requested = [];
    foreach (preg_split('/\s*,\s*/', (string) ($_POST['order'] ?? '')) ?: [] as $part) {
        if ($part === '') {
            continue;
        }
        $requested[] = (int) $part;
    }
    if (!notebook_reorder(db(), $requested)) {
        if (wants_json()) {
            json_out(['ok' => false, 'error' => 'Those notebooks could not be reordered.'], 400);
        }
        flash('Those notebooks could not be reordered.', 'error');
        redirect_to(posted_return('view=notes'));
    }
    if (wants_json()) {
        json_out(['ok' => true]);
    }
    redirect_to(posted_return('view=notes'));
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
    if (notebook_is_inbox(db(), $id)) {
        flash('Inbox stays available for pages you clip.', 'error');
        redirect_to($return);
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
    if (notebook_is_inbox(db(), $id)) {
        flash('Inbox stays available for pages you clip.', 'error');
        redirect_to($return);
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
