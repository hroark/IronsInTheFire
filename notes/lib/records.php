<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/tags.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/snapshot.php';
refuse_direct_access(__FILE__);

function notebooks_all(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, sort_order FROM notebooks ORDER BY sort_order, name')->fetchAll();
    $notebooks = [];
    foreach ($rows as $row) {
        $notebooks[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
    return $notebooks;
}

function notebook_exists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM notebooks WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

function notebook_name_taken(PDO $pdo, string $name, ?int $exceptId = null): bool
{
    $sql = 'SELECT id FROM notebooks WHERE name = ?';
    $params = [$name];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function notebook_create(PDO $pdo, string $name): int
{
    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM notebooks')->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO notebooks (name, sort_order) VALUES (?, ?)');
    $stmt->execute([$name, $sort]);
    return (int) $pdo->lastInsertId();
}

function notebook_rename(PDO $pdo, int $id, string $name): void
{
    $stmt = $pdo->prepare('UPDATE notebooks SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);
}

function notebook_id_order(array $existingIds, array $requestedIds): ?array
{
    if ($existingIds === [] || count($existingIds) !== count($requestedIds)) {
        return null;
    }
    $known = [];
    foreach ($existingIds as $id) {
        $known[(int) $id] = true;
    }
    $order = [];
    foreach ($requestedIds as $id) {
        $id = (int) $id;
        if ($id < 1 || !isset($known[$id]) || isset($order[$id])) {
            return null;
        }
        $order[$id] = $id;
    }
    if (count($order) !== count($known)) {
        return null;
    }
    return array_values($order);
}

function notebook_reorder(PDO $pdo, array $requestedIds): bool
{
    $existing = [];
    foreach (notebooks_all($pdo) as $notebook) {
        $existing[] = $notebook['id'];
    }
    $order = notebook_id_order($existing, $requestedIds);
    if ($order === null) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE notebooks SET sort_order = ? WHERE id = ?');
        foreach ($order as $index => $id) {
            $stmt->execute([$index, $id]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function notebook_counts(PDO $pdo, int $id): array
{
    $notes = $pdo->prepare('SELECT COUNT(*) FROM notes WHERE notebook_id = ?');
    $notes->execute([$id]);
    $links = $pdo->prepare('SELECT COUNT(*) FROM links WHERE notebook_id = ?');
    $links->execute([$id]);
    return ['notes' => (int) $notes->fetchColumn(), 'links' => (int) $links->fetchColumn()];
}

function notebook_delete(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM notebooks WHERE id = ?');
    $stmt->execute([$id]);
}

function first_notebook_id(PDO $pdo): int
{
    $id = $pdo->query('SELECT id FROM notebooks ORDER BY sort_order, id LIMIT 1')->fetchColumn();
    return (int) $id;
}

function inbox_notebook(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached) && notebook_exists($pdo, (int) $cached['id'])) {
        return $cached;
    }
    $stmt = $pdo->prepare('SELECT id, name, sort_order FROM notebooks WHERE name = ? ORDER BY id LIMIT 1');
    $stmt->execute(['Inbox']);
    $row = $stmt->fetch();
    if (!$row) {
        try {
            $insert = $pdo->prepare('INSERT INTO notebooks (name, sort_order) VALUES (?, 0)');
            $insert->execute(['Inbox']);
            $cached = ['id' => (int) $pdo->lastInsertId(), 'name' => 'Inbox', 'sort_order' => 0];
            return $cached;
        } catch (PDOException $e) {
            $stmt->execute(['Inbox']);
            $row = $stmt->fetch();
            if (!$row) {
                throw $e;
            }
        }
    }
    $cached = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'sort_order' => (int) $row['sort_order'],
    ];
    return $cached;
}

function notebook_is_inbox(PDO $pdo, int $id): bool
{
    return $id > 0 && $id === (int) inbox_notebook($pdo)['id'];
}

function notebook_active_note_count(PDO $pdo, int $id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notes WHERE notebook_id = ? AND archived = 0');
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn();
}

function note_move(PDO $pdo, int $id, int $notebookId): bool
{
    $stmt = $pdo->prepare('UPDATE notes SET notebook_id = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$notebookId, $id]);
    return $stmt->rowCount() > 0 || note_find($pdo, $id) !== null;
}

function attach_note_tags(PDO $pdo, array $notes): array
{
    $ids = [];
    foreach ($notes as $note) {
        $ids[] = (int) $note['id'];
    }
    $grouped = tags_grouped($pdo, 'note', $ids);
    foreach ($notes as $index => $note) {
        $notes[$index]['tags'] = $grouped[(int) $note['id']] ?? [];
    }
    return $notes;
}

function attach_link_tags(PDO $pdo, array $links): array
{
    $ids = [];
    foreach ($links as $link) {
        $ids[] = (int) $link['id'];
    }
    $grouped = tags_grouped($pdo, 'link', $ids);
    foreach ($links as $index => $link) {
        $links[$index]['tags'] = $grouped[(int) $link['id']] ?? [];
    }
    return $links;
}

function map_note(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'notebook_id' => (int) $row['notebook_id'],
        'notebook' => $row['notebook'],
        'title' => $row['title'],
        'body_md' => $row['body_md'],
        'pinned' => (int) $row['pinned'] === 1,
        'archived' => (int) $row['archived'] === 1,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'tags' => [],
    ];
}

function link_select_columns(): string
{
    return 'l.id, l.notebook_id, nb.name AS notebook, l.url, l.title, l.note, l.page_title, l.page_description, l.site_name, l.snapshot_text, l.preview_path, l.http_status, l.link_state, l.checked_at, l.captured_at, l.created_at';
}

function map_link(array $row): array
{
    $preview = $row['preview_path'] ?? null;
    $status = $row['http_status'] ?? null;
    return [
        'id' => (int) $row['id'],
        'notebook_id' => (int) $row['notebook_id'],
        'notebook' => $row['notebook'],
        'url' => $row['url'],
        'title' => $row['title'],
        'note' => $row['note'],
        'page_title' => (string) ($row['page_title'] ?? ''),
        'page_description' => (string) ($row['page_description'] ?? ''),
        'site_name' => (string) ($row['site_name'] ?? ''),
        'snapshot_text' => (string) ($row['snapshot_text'] ?? ''),
        'preview_path' => is_string($preview) && $preview !== '' ? $preview : null,
        'http_status' => $status === null || $status === '' ? null : (int) $status,
        'link_state' => (string) ($row['link_state'] ?? 'unknown'),
        'checked_at' => $row['checked_at'] ?? null,
        'captured_at' => $row['captured_at'] ?? null,
        'created_at' => $row['created_at'],
        'tags' => [],
    ];
}

function notes_list(PDO $pdo, ?int $notebookId, ?int $tagId, bool $archived): array
{
    $sql = 'SELECT n.id, n.notebook_id, nb.name AS notebook, n.title, n.body_md, n.pinned, n.archived, n.created_at, n.updated_at
            FROM notes n
            JOIN notebooks nb ON nb.id = n.notebook_id';
    $params = [];
    $where = ['n.archived = ?'];
    $params[] = $archived ? 1 : 0;
    if ($notebookId !== null) {
        $where[] = 'n.notebook_id = ?';
        $params[] = $notebookId;
    }
    if ($tagId !== null) {
        $sql .= ' JOIN note_tags nt ON nt.note_id = n.id';
        $where[] = 'nt.tag_id = ?';
        $params[] = $tagId;
    }
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY n.pinned DESC, n.updated_at DESC, n.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return attach_note_tags($pdo, array_map('map_note', $stmt->fetchAll()));
}

function note_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT n.id, n.notebook_id, nb.name AS notebook, n.title, n.body_md, n.pinned, n.archived, n.created_at, n.updated_at
         FROM notes n
         JOIN notebooks nb ON nb.id = n.notebook_id
         WHERE n.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $notes = attach_note_tags($pdo, [map_note($row)]);
    return $notes[0];
}

function note_save(PDO $pdo, int $id, int $notebookId, string $title, string $body, string $tags, bool $pinned, bool $archived): int
{
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE notes
                 SET notebook_id = ?, title = ?, body_md = ?, pinned = ?, archived = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$notebookId, $title, $body, $pinned ? 1 : 0, $archived ? 1 : 0, $id]);
            if ($stmt->rowCount() === 0 && note_find($pdo, $id) === null) {
                $pdo->rollBack();
                return 0;
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO notes (notebook_id, title, body_md, pinned, archived, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$notebookId, $title, $body, $pinned ? 1 : 0, $archived ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
        }
        sync_tags($pdo, 'note', $id, $tags);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function note_update_body(PDO $pdo, int $id, string $body): bool
{
    $stmt = $pdo->prepare('UPDATE notes SET body_md = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$body, $id]);
    return $stmt->rowCount() > 0 || note_find($pdo, $id) !== null;
}

function note_delete(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->exec(
            'DELETE t FROM tags t
             LEFT JOIN note_tags nt ON nt.tag_id = t.id
             LEFT JOIN link_tags lt ON lt.tag_id = t.id
             WHERE nt.tag_id IS NULL AND lt.tag_id IS NULL'
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function search_notes(PDO $pdo, string $query): array
{
    $like = like_contains($query);
    $sql = 'SELECT DISTINCT n.id, n.notebook_id, nb.name AS notebook, n.title, n.body_md, n.pinned, n.archived, n.created_at, n.updated_at
            FROM notes n
            JOIN notebooks nb ON nb.id = n.notebook_id
            LEFT JOIN note_tags nt ON nt.note_id = n.id
            LEFT JOIN tags t ON t.id = nt.tag_id
            WHERE n.archived = 0
              AND (n.title LIKE ? ESCAPE \'\\\\\' OR n.body_md LIKE ? ESCAPE \'\\\\\' OR t.name LIKE ? ESCAPE \'\\\\\')
            ORDER BY n.pinned DESC, n.updated_at DESC, n.id DESC
            LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like, $like]);
    $notes = attach_note_tags($pdo, array_map('map_note', $stmt->fetchAll()));
    foreach ($notes as $index => $note) {
        $notes[$index]['excerpt'] = text_excerpt($note['body_md'], $query);
    }
    return $notes;
}

function search_links(PDO $pdo, string $query): array
{
    $like = like_contains($query);
    $sql = 'SELECT DISTINCT ' . link_select_columns() . '
            FROM links l
            JOIN notebooks nb ON nb.id = l.notebook_id
            LEFT JOIN link_tags lt ON lt.link_id = l.id
            LEFT JOIN tags t ON t.id = lt.tag_id
            WHERE l.url LIKE ? ESCAPE \'\\\\\'
               OR l.title LIKE ? ESCAPE \'\\\\\'
               OR l.note LIKE ? ESCAPE \'\\\\\'
               OR t.name LIKE ? ESCAPE \'\\\\\'
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like, $like, $like]);
    $links = attach_link_tags($pdo, array_map('map_link', $stmt->fetchAll()));
    foreach ($links as $index => $link) {
        $links[$index]['excerpt'] = text_excerpt($link['note'], $query);
    }
    return $links;
}

function links_list(PDO $pdo, ?int $tagId): array
{
    $sql = 'SELECT ' . link_select_columns() . '
            FROM links l
            JOIN notebooks nb ON nb.id = l.notebook_id';
    $params = [];
    if ($tagId !== null) {
        $sql .= ' JOIN link_tags lt ON lt.link_id = l.id WHERE lt.tag_id = ?';
        $params[] = $tagId;
    }
    $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return attach_link_tags($pdo, array_map('map_link', $stmt->fetchAll()));
}

function link_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ' . link_select_columns() . '
         FROM links l
         JOIN notebooks nb ON nb.id = l.notebook_id
         WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $links = attach_link_tags($pdo, [map_link($row)]);
    return $links[0];
}

function link_save(PDO $pdo, int $id, int $notebookId, string $url, string $title, string $note, string $tags): int
{
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE links SET notebook_id = ?, url = ?, title = ?, note = ? WHERE id = ?');
            $stmt->execute([$notebookId, $url, $title, $note, $id]);
            if ($stmt->rowCount() === 0 && link_find($pdo, $id) === null) {
                $pdo->rollBack();
                return 0;
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO links (notebook_id, url, title, note, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$notebookId, $url, $title, $note]);
            $id = (int) $pdo->lastInsertId();
        }
        sync_tags($pdo, 'link', $id, $tags);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function link_set_check(PDO $pdo, int $id, string $state, ?int $status): void
{
    $stmt = $pdo->prepare('UPDATE links SET link_state = ?, http_status = ?, checked_at = NOW() WHERE id = ?');
    $stmt->bindValue(1, snapshot_known_state($state));
    if ($status === null) {
        $stmt->bindValue(2, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(2, $status, PDO::PARAM_INT);
    }
    $stmt->bindValue(3, $id, PDO::PARAM_INT);
    $stmt->execute();
}

function link_set_snapshot(PDO $pdo, int $id, array $capture, ?string $previewPath): void
{
    $stmt = $pdo->prepare(
        'UPDATE links
         SET page_title = ?, page_description = ?, site_name = ?, snapshot_text = ?, preview_path = ?,
             http_status = ?, link_state = ?, checked_at = NOW(), captured_at = NOW()
         WHERE id = ?'
    );
    $stmt->bindValue(1, (string) ($capture['title'] ?? ''));
    $stmt->bindValue(2, (string) ($capture['description'] ?? ''));
    $stmt->bindValue(3, (string) ($capture['site_name'] ?? ''));
    $stmt->bindValue(4, (string) ($capture['text'] ?? ''));
    if ($previewPath === null) {
        $stmt->bindValue(5, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(5, $previewPath);
    }
    if (!isset($capture['status']) || $capture['status'] === null) {
        $stmt->bindValue(6, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(6, (int) $capture['status'], PDO::PARAM_INT);
    }
    $stmt->bindValue(7, snapshot_known_state((string) ($capture['state'] ?? '')));
    $stmt->bindValue(8, $id, PDO::PARAM_INT);
    $stmt->execute();
}

function links_needing_check(PDO $pdo, int $limit): array
{
    $limit = max(1, min(10, $limit));
    $stmt = $pdo->query(
        'SELECT id, url FROM links ORDER BY checked_at IS NULL DESC, checked_at ASC, id ASC LIMIT ' . $limit
    );
    return $stmt ? $stmt->fetchAll() : [];
}

function links_unchecked_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM links WHERE checked_at IS NULL')->fetchColumn();
}

function link_delete(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM links WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->exec(
            'DELETE t FROM tags t
             LEFT JOIN note_tags nt ON nt.tag_id = t.id
             LEFT JOIN link_tags lt ON lt.tag_id = t.id
             WHERE nt.tag_id IS NULL AND lt.tag_id IS NULL'
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function notes_with_tasks(PDO $pdo): array
{
    $notes = notes_list($pdo, null, null, false);
    $withTasks = [];
    foreach ($notes as $note) {
        $tasks = extract_tasks($note['body_md']);
        if (!$tasks) {
            continue;
        }
        $note['tasks'] = $tasks;
        $withTasks[] = $note;
    }
    return $withTasks;
}

function export_bundle(PDO $pdo): array
{
    $noteStmt = $pdo->query(
        'SELECT n.id, n.notebook_id, nb.name AS notebook, n.title, n.body_md, n.pinned, n.archived, n.created_at, n.updated_at
         FROM notes n
         JOIN notebooks nb ON nb.id = n.notebook_id
         ORDER BY nb.sort_order, n.title'
    );
    $linkStmt = $pdo->query(
        'SELECT ' . link_select_columns() . '
         FROM links l
         JOIN notebooks nb ON nb.id = l.notebook_id
         ORDER BY l.created_at, l.id'
    );
    return [
        'notes' => attach_note_tags($pdo, array_map('map_note', $noteStmt->fetchAll())),
        'links' => attach_link_tags($pdo, array_map('map_link', $linkStmt->fetchAll())),
    ];
}

function clean_notebook_name(string $name): ?string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return null;
    }
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length > 120 || preg_match('/[\x00-\x1F]/', $name)) {
        return null;
    }
    return $name;
}
