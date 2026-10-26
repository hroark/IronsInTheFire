<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/snapshot.php';
refuse_direct_access(__FILE__);

function yaml_quote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function note_to_markdown(array $note): string
{
    $tags = $note['tags'] ?? [];
    $quotedTags = [];
    foreach ($tags as $tag) {
        $quotedTags[] = yaml_quote((string) $tag);
    }
    $body = normalize_newlines((string) ($note['body_md'] ?? ''));
    if ($body !== '' && substr($body, -1) !== "\n") {
        $body .= "\n";
    }

    return "---\n"
        . 'title: ' . yaml_quote((string) $note['title']) . "\n"
        . 'notebook: ' . yaml_quote((string) $note['notebook']) . "\n"
        . 'tags: [' . implode(', ', $quotedTags) . "]\n"
        . 'created: ' . yaml_quote((string) $note['created_at']) . "\n"
        . 'updated: ' . yaml_quote((string) $note['updated_at']) . "\n"
        . "---\n\n"
        . $body;
}

function links_to_markdown(array $links): string
{
    $lines = ["# Links", ''];
    if (!$links) {
        $lines[] = 'No saved links.';
        $lines[] = '';
        return implode("\n", $lines);
    }
    foreach ($links as $link) {
        $lines[] = '## ' . str_replace(["\r", "\n"], ' ', (string) $link['title']);
        $lines[] = '';
        $lines[] = '- URL: ' . (string) $link['url'];
        $tags = $link['tags'] ?? [];
        $lines[] = '- Tags: ' . ($tags ? implode(', ', $tags) : 'none');
        $lines[] = '- Saved: ' . (string) $link['created_at'];
        $description = trim(str_replace(["\r", "\n"], ' ', (string) ($link['page_description'] ?? '')));
        if ($description !== '') {
            $lines[] = '- Description: ' . $description;
        }
        $status = snapshot_state_label((string) ($link['link_state'] ?? ''));
        if ($status !== '') {
            $lines[] = '- Status: ' . $status;
        }
        $note = trim(normalize_newlines((string) ($link['note'] ?? '')));
        if ($note !== '') {
            $lines[] = '';
            $lines[] = $note;
        }
        $lines[] = '';
    }
    return implode("\n", $lines);
}

function export_filename(string $title, int $id): string
{
    return slug($title) . '-' . $id . '.md';
}

function unique_folder(string $name, int $id, array &$used): string
{
    $base = slug($name);
    $folder = $base;
    if (isset($used[$folder])) {
        $folder = $base . '-' . $id;
        $suffix = 2;
        while (isset($used[$folder])) {
            $folder = $base . '-' . $id . '-' . $suffix++;
        }
    }
    $used[$folder] = true;
    return $folder;
}

function export_entries(array $notes, array $links): array
{
    $linksByNotebook = [];
    foreach ($links as $link) {
        $notebookId = (int) $link['notebook_id'];
        $linksByNotebook[$notebookId][] = $link;
    }

    $notebooks = [];
    foreach ($notes as $note) {
        $notebookId = (int) $note['notebook_id'];
        $notebooks[$notebookId] = (string) $note['notebook'];
    }
    foreach ($links as $link) {
        $notebookId = (int) $link['notebook_id'];
        $notebooks[$notebookId] = (string) $link['notebook'];
    }

    $used = [];
    $folders = [];
    foreach ($notebooks as $notebookId => $name) {
        $folders[$notebookId] = unique_folder($name, (int) $notebookId, $used);
    }

    $entries = [];
    foreach ($notes as $note) {
        $notebookId = (int) $note['notebook_id'];
        $folder = $folders[$notebookId] ?? 'notebook';
        $prefix = !empty($note['archived']) ? ('archive/' . $folder) : $folder;
        $path = $prefix . '/' . export_filename((string) $note['title'], (int) $note['id']);
        $entries[$path] = note_to_markdown($note);
    }

    foreach ($folders as $notebookId => $folder) {
        $notebookLinks = $linksByNotebook[$notebookId] ?? [];
        if (!$notebookLinks && !notebook_has_active_note($notes, (int) $notebookId)) {
            continue;
        }
        $entries[$folder . '/links.md'] = links_to_markdown($notebookLinks);
    }

    ksort($entries);
    return $entries;
}

function notebook_has_active_note(array $notes, int $notebookId): bool
{
    foreach ($notes as $note) {
        if ((int) $note['notebook_id'] === $notebookId && empty($note['archived'])) {
            return true;
        }
    }
    return false;
}

function zip_bytes(array $entries): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'notes');
    if ($tmp === false) {
        return null;
    }
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        @unlink($tmp);
        return null;
    }
    foreach ($entries as $path => $contents) {
        $zip->addFromString($path, $contents);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return is_string($bytes) ? $bytes : null;
}
