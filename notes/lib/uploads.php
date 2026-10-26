<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function upload_max_bytes(): int
{
    return 8 * 1024 * 1024;
}

function upload_max_folder_depth(): int
{
    return 6;
}

function upload_safe_name(string $name): string
{
    $name = str_replace("\0", '', $name);
    $name = basename(str_replace('\\', '/', $name));
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || $name === '.' || $name === '..') {
        return 'file';
    }
    return limit_text($name, 180);
}

function upload_extension(string $name): ?string
{
    $name = upload_safe_name($name);
    $dot = strrpos($name, '.');
    if ($dot === false) {
        return null;
    }
    $extension = strtolower(substr($name, $dot + 1));
    $allowed = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'csv', 'json',
        'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];
    return in_array($extension, $allowed, true) ? $extension : null;
}

function upload_is_image(string $extension): bool
{
    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function upload_content_type(string $extension): string
{
    $types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    return $types[$extension] ?? 'application/octet-stream';
}

function upload_bytes_match(string $extension, string $bytes): bool
{
    if ($bytes === '' || strlen($bytes) > upload_max_bytes()) {
        return false;
    }
    if ($extension === 'jpg' || $extension === 'jpeg') {
        return str_starts_with($bytes, "\xFF\xD8\xFF");
    }
    if ($extension === 'png') {
        return str_starts_with($bytes, "\x89PNG\r\n\x1A\n");
    }
    if ($extension === 'gif') {
        return str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a');
    }
    if ($extension === 'webp') {
        return strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP';
    }
    if ($extension === 'pdf') {
        return str_starts_with($bytes, '%PDF-');
    }
    if (in_array($extension, ['zip', 'docx', 'xlsx', 'pptx'], true)) {
        return str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06") || str_starts_with($bytes, "PK\x07\x08");
    }
    if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
        return str_starts_with($bytes, "\xD0\xCF\x11\xE0");
    }
    if (in_array($extension, ['txt', 'md', 'csv', 'json'], true)) {
        $sample = substr($bytes, 0, 8192);
        if (str_contains($sample, "\0")) {
            return false;
        }
        return function_exists('mb_check_encoding') ? mb_check_encoding($sample, 'UTF-8') : true;
    }
    return false;
}

function upload_accept(string $filename, string $bytes): ?array
{
    $extension = upload_extension($filename);
    if ($extension === null || !upload_bytes_match($extension, $bytes)) {
        return null;
    }
    return [
        'name' => upload_safe_name($filename),
        'extension' => $extension,
        'mime' => upload_content_type($extension),
    ];
}

function upload_folder_name(string $name): ?string
{
    $name = str_replace("\0", '', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || $name === '.' || $name === '..') {
        return null;
    }
    if (preg_match('/[\\\\\\/\\x00-\\x1F]/', $name) === 1) {
        return null;
    }
    $limited = limit_text($name, 120);
    if ($limited !== $name) {
        return null;
    }
    return $name;
}

function upload_folder_depth(int $parentId, array $parentById): int
{
    $depth = 1;
    $current = $parentId;
    $guard = 0;
    while ($current > 0 && $guard < 30) {
        $depth++;
        $current = (int) ($parentById[$current] ?? 0);
        $guard++;
    }
    return $parentId > 0 ? $depth : 1;
}

function upload_folder_move_cycles(int $folderId, int $newParentId, array $parentById): bool
{
    if ($newParentId === 0) {
        return false;
    }
    if ($newParentId === $folderId) {
        return true;
    }
    $current = $newParentId;
    $guard = 0;
    while ($current > 0 && $guard < 30) {
        if ($current === $folderId) {
            return true;
        }
        $current = (int) ($parentById[$current] ?? 0);
        $guard++;
    }
    return false;
}

function upload_size_label(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1048576, 1) . ' MB';
}

function upload_download_name(string $name): string
{
    $name = str_replace(['"', '\\', "\r", "\n"], '', upload_safe_name($name));
    return $name === '' ? 'file' : $name;
}

function upload_stored_name(string $extension): string
{
    return bin2hex(random_bytes(8)) . '.' . $extension;
}

function upload_stored_name_ok(string $name): bool
{
    return preg_match('/^[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp|pdf|txt|md|csv|json|zip|doc|docx|xls|xlsx|ppt|pptx)$/', $name) === 1;
}

function upload_directory(): string
{
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        $rules = "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
        file_put_contents($htaccess, $rules);
    }
    return $dir;
}

function upload_file_path(string $name): ?string
{
    if (!upload_stored_name_ok($name)) {
        return null;
    }
    $dir = dirname(__DIR__) . '/uploads';
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        return null;
    }
    $real = realpath($path);
    $root = realpath($dir);
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $real;
}

function upload_delete_stored(?string $name): void
{
    $path = upload_file_path((string) $name);
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

function upload_write_stored(string $bytes, string $extension): ?string
{
    if (!upload_bytes_match($extension, $bytes)) {
        return null;
    }
    $dir = upload_directory();
    $name = upload_stored_name($extension);
    if (file_put_contents($dir . '/' . $name, $bytes) === false) {
        return null;
    }
    return $name;
}

function upload_folders_all(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, parent_id, name, created_at FROM upload_folders ORDER BY name, id')->fetchAll();
    $folders = [];
    foreach ($rows as $row) {
        $folders[] = [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'name' => (string) $row['name'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    return $folders;
}

function upload_parent_map(array $folders): array
{
    $map = [];
    foreach ($folders as $folder) {
        $map[(int) $folder['id']] = (int) ($folder['parent_id'] ?? 0);
    }
    return $map;
}

function upload_folder_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, parent_id, name, created_at FROM upload_folders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        'name' => (string) $row['name'],
        'created_at' => (string) $row['created_at'],
    ];
}

function upload_folder_name_taken(PDO $pdo, ?int $parentId, string $name, ?int $exceptId = null): bool
{
    $sql = 'SELECT id FROM upload_folders WHERE name = ? AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?');
    $params = [$name];
    if ($parentId !== null) {
        $params[] = $parentId;
    }
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function upload_folder_create(PDO $pdo, ?int $parentId, string $name): int
{
    $stmt = $pdo->prepare('INSERT INTO upload_folders (parent_id, name) VALUES (?, ?)');
    $stmt->execute([$parentId, $name]);
    return (int) $pdo->lastInsertId();
}

function upload_folder_counts(PDO $pdo, int $id): array
{
    $files = $pdo->prepare('SELECT COUNT(*) FROM uploads WHERE folder_id = ?');
    $files->execute([$id]);
    $children = $pdo->prepare('SELECT COUNT(*) FROM upload_folders WHERE parent_id = ?');
    $children->execute([$id]);
    return ['files' => (int) $files->fetchColumn(), 'folders' => (int) $children->fetchColumn()];
}

function upload_folder_delete(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM upload_folders WHERE id = ?');
    $stmt->execute([$id]);
}

function upload_breadcrumbs(array $folders, ?int $folderId): array
{
    if ($folderId === null || $folderId < 1) {
        return [];
    }
    $byId = [];
    foreach ($folders as $folder) {
        $byId[(int) $folder['id']] = $folder;
    }
    $chain = [];
    $current = $folderId;
    $guard = 0;
    while ($current > 0 && isset($byId[$current]) && $guard < 30) {
        array_unshift($chain, $byId[$current]);
        $current = (int) ($byId[$current]['parent_id'] ?? 0);
        $guard++;
    }
    return $chain;
}

function upload_folder_lines(array $folders, ?int $parentId = null, int $depth = 0): array
{
    $lines = [];
    foreach (upload_child_folders($folders, $parentId) as $folder) {
        $lines[] = ['id' => (int) $folder['id'], 'name' => (string) $folder['name'], 'depth' => $depth];
        foreach (upload_folder_lines($folders, (int) $folder['id'], $depth + 1) as $line) {
            $lines[] = $line;
        }
    }
    return $lines;
}

function upload_tree_html(array $folders, ?int $parentId, ?int $currentId): string
{
    $children = upload_child_folders($folders, $parentId);
    if (!$children) {
        return '';
    }
    $html = '<ul class="notes-folder-tree">';
    foreach ($children as $folder) {
        $class = 'notes-folder-link' . ((int) $currentId === (int) $folder['id'] ? ' is-active' : '');
        $html .= '<li><a class="' . $class . '" data-folder-id="' . (int) $folder['id'] . '" href="index.php?view=files&folder=' . (int) $folder['id'] . '">' . e((string) $folder['name']) . '</a>';
        $html .= upload_tree_html($folders, (int) $folder['id'], $currentId);
        $html .= '</li>';
    }
    return $html . '</ul>';
}

function upload_child_folders(array $folders, ?int $parentId): array
{
    $children = [];
    foreach ($folders as $folder) {
        $parent = $folder['parent_id'];
        if ($parentId === null) {
            if ($parent === null) {
                $children[] = $folder;
            }
        } elseif ($parent === $parentId) {
            $children[] = $folder;
        }
    }
    return $children;
}

function uploads_in_folder(PDO $pdo, ?int $folderId): array
{
    if ($folderId === null) {
        $stmt = $pdo->query('SELECT id, folder_id, original_name, stored_name, mime, byte_size, created_at FROM uploads WHERE folder_id IS NULL ORDER BY created_at DESC, id DESC');
    } else {
        $stmt = $pdo->prepare('SELECT id, folder_id, original_name, stored_name, mime, byte_size, created_at FROM uploads WHERE folder_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$folderId]);
    }
    $rows = $stmt ? $stmt->fetchAll() : [];
    return array_map('upload_map', $rows);
}

function upload_map(array $row): array
{
    $extension = strtolower(pathinfo((string) $row['stored_name'], PATHINFO_EXTENSION));
    return [
        'id' => (int) $row['id'],
        'folder_id' => $row['folder_id'] === null ? null : (int) $row['folder_id'],
        'original_name' => (string) $row['original_name'],
        'stored_name' => (string) $row['stored_name'],
        'mime' => (string) $row['mime'],
        'byte_size' => (int) $row['byte_size'],
        'created_at' => (string) $row['created_at'],
        'extension' => $extension,
        'image' => upload_is_image($extension),
    ];
}

function upload_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, folder_id, original_name, stored_name, mime, byte_size, created_at FROM uploads WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? upload_map($row) : null;
}

function upload_insert(PDO $pdo, ?int $folderId, string $originalName, string $storedName, string $mime, int $byteSize): int
{
    $stmt = $pdo->prepare('INSERT INTO uploads (folder_id, original_name, stored_name, mime, byte_size) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$folderId, $originalName, $storedName, $mime, $byteSize]);
    return (int) $pdo->lastInsertId();
}

function upload_move(PDO $pdo, int $id, ?int $folderId): void
{
    $stmt = $pdo->prepare('UPDATE uploads SET folder_id = ? WHERE id = ?');
    $stmt->execute([$folderId, $id]);
}

function upload_delete(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM uploads WHERE id = ?');
    $stmt->execute([$id]);
}

function uploaded_file_list(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }
    $bag = $_FILES[$field];
    if (!isset($bag['name'])) {
        return [];
    }
    if (!is_array($bag['name'])) {
        return [[
            'name' => (string) $bag['name'],
            'tmp_name' => (string) ($bag['tmp_name'] ?? ''),
            'error' => (int) ($bag['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($bag['size'] ?? 0),
        ]];
    }
    $list = [];
    foreach ($bag['name'] as $index => $name) {
        $list[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($bag['tmp_name'][$index] ?? ''),
            'error' => (int) ($bag['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($bag['size'][$index] ?? 0),
        ];
    }
    return $list;
}
