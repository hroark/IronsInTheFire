<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function render(string $template, array $vars = []): void
{
    $vars['csrf'] = csrf_token();
    $vars['flashMessage'] = take_flash();
    $vars['pageTitle'] = $vars['pageTitle'] ?? 'Notes';
    $vars['bare'] = $vars['bare'] ?? false;
    extract($vars, EXTR_SKIP);
    $contentTemplate = __DIR__ . '/../templates/' . $template . '.php';
    include __DIR__ . '/../templates/layout.php';
}

function render_login(?string $error = null, bool $choosePassword = false): void
{
    render('login', [
        'bare' => true,
        'pageTitle' => $choosePassword ? 'Choose a password' : 'Sign in',
        'error' => $error,
        'problems' => $choosePassword ? [] : config_problems(),
        'choosePassword' => $choosePassword,
    ]);
}

function render_app(string $template, array $vars = []): void
{
    $pdo = db();
    $inbox = inbox_notebook($pdo);
    $vars['inbox'] = $inbox;
    $vars['inboxCount'] = notebook_active_note_count($pdo, (int) $inbox['id']);
    $vars['notebooks'] = notebooks_all($pdo);
    $vars['tagList'] = tags_with_counts($pdo);
    $vars['bare'] = false;
    $vars['active'] = $vars['active'] ?? ['view' => 'notes', 'notebook' => null, 'tag' => null];
    render($template, $vars);
}

function blank_note(int $notebookId): array
{
    return [
        'id' => 0,
        'title' => '',
        'body_md' => '',
        'tags_text' => '',
        'notebook_id' => $notebookId,
        'pinned' => false,
        'archived' => false,
    ];
}

function blank_link(int $notebookId): array
{
    return [
        'id' => 0,
        'url' => '',
        'title' => '',
        'note' => '',
        'tags_text' => '',
        'notebook_id' => $notebookId,
    ];
}

function render_current_view(): void
{
    $view = (string) ($_GET['view'] ?? 'notes');
    $pdo = db();
    $notebookParam = isset($_GET['notebook']) ? (int) $_GET['notebook'] : null;
    $active = [
        'view' => $view,
        'notebook' => $view === 'notes' ? $notebookParam : null,
        'tag' => $view === 'tag' ? (int) ($_GET['id'] ?? 0) : null,
    ];

    if ($view === 'notes') {
        if ($notebookParam !== null && !notebook_exists($pdo, $notebookParam)) {
            flash('That notebook is not available.', 'error');
            redirect_to('view=notes');
        }
        $notebookName = 'All notes';
        if ($notebookParam !== null) {
            foreach (notebooks_all($pdo) as $notebook) {
                if ($notebook['id'] === $notebookParam) {
                    $notebookName = $notebook['name'];
                }
            }
        }
        render_app('notes_list', [
            'pageTitle' => $notebookName,
            'heading' => $notebookName,
            'notes' => notes_list($pdo, $notebookParam, null, false),
            'notebookId' => $notebookParam,
            'archivedView' => false,
            'active' => $active,
            'returnQuery' => $notebookParam ? 'view=notes&notebook=' . $notebookParam : 'view=notes',
        ]);
        return;
    }

    if ($view === 'archive') {
        $active['view'] = 'archive';
        render_app('notes_list', [
            'pageTitle' => 'Archive',
            'heading' => 'Archive',
            'notes' => notes_list($pdo, null, null, true),
            'notebookId' => null,
            'archivedView' => true,
            'active' => $active,
            'returnQuery' => 'view=archive',
        ]);
        return;
    }

    if ($view === 'tag') {
        $tag = tag_find($pdo, (int) ($_GET['id'] ?? 0));
        if (!$tag) {
            flash('That tag is not available.', 'error');
            redirect_to('view=notes');
        }
        render_app('tag', [
            'pageTitle' => $tag['name'],
            'tag' => $tag,
            'notes' => array_merge(
                notes_list($pdo, null, $tag['id'], false),
                notes_list($pdo, null, $tag['id'], true)
            ),
            'links' => links_list($pdo, $tag['id']),
            'active' => $active,
            'returnQuery' => 'view=tag&id=' . $tag['id'],
        ]);
        return;
    }

    if ($view === 'tasks') {
        render_app('tasks', [
            'pageTitle' => 'Open tasks',
            'taskNotes' => notes_with_tasks($pdo),
            'active' => $active,
        ]);
        return;
    }

    if ($view === 'links') {
        $editId = (int) ($_GET['edit'] ?? 0);
        $draft = take_draft('link');
        $link = $draft;
        if ($link === null && $editId > 0) {
            $existing = link_find($pdo, $editId);
            if (!$existing) {
                flash('That link is not available.', 'error');
                redirect_to('view=links');
            }
            $link = $existing;
            $link['tags_text'] = implode(', ', $existing['tags']);
        }
        if ($link === null) {
            $link = blank_link(first_notebook_id($pdo));
        }
        render_app('links', [
            'pageTitle' => 'Links',
            'links' => links_list($pdo, null),
            'link' => $link,
            'active' => $active,
        ]);
        return;
    }

    if ($view === 'search') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $query = limit_text($query, 100);
        render_app('search', [
            'pageTitle' => 'Search',
            'query' => $query,
            'notes' => $query === '' ? [] : search_notes($pdo, $query),
            'links' => $query === '' ? [] : search_links($pdo, $query),
            'active' => $active,
            'returnQuery' => 'view=search&q=' . rawurlencode($query),
        ]);
        return;
    }

    if ($view === 'clip') {
        $active['view'] = 'clip';
        render_app('clip', [
            'pageTitle' => 'Save to Inbox',
            'active' => $active,
        ]);
        return;
    }

    if ($view === 'journal') {
        render_app('journal', [
            'pageTitle' => 'Journal',
            'active' => $active,
        ]);
        return;
    }

    if ($view === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        $draft = take_draft('note');
        $note = $draft;
        if ($note === null && $id > 0) {
            $existing = note_find($pdo, $id);
            if (!$existing) {
                flash('That note is not available.', 'error');
                redirect_to('view=notes');
            }
            $note = $existing;
            $note['tags_text'] = implode(', ', $existing['tags']);
        }
        if ($note === null) {
            $requestedNotebook = (int) ($_GET['notebook'] ?? 0);
            if ($requestedNotebook < 1 || !notebook_exists($pdo, $requestedNotebook)) {
                $requestedNotebook = first_notebook_id($pdo);
            }
            $note = blank_note($requestedNotebook);
        }
        render_app('editor', [
            'pageTitle' => $note['id'] ? $note['title'] : 'New note',
            'note' => $note,
            'active' => $active,
        ]);
        return;
    }

    flash('That page is not available.', 'error');
    redirect_to('view=notes');
}
