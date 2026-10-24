<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function normalize_tag_name(string $name): ?string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return null;
    }
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length > 80 || preg_match('/[\x00-\x1F]/', $name)) {
        return null;
    }
    return $name;
}

function normalize_tag_list(string $raw): array
{
    $parts = preg_split('/\s*,\s*/u', $raw) ?: [];
    $names = [];
    foreach ($parts as $part) {
        $name = normalize_tag_name($part);
        if ($name === null) {
            continue;
        }
        $names[$name] = $name;
    }
    return array_values($names);
}

function sync_tags(PDO $pdo, string $kind, int $ownerId, string $raw): void
{
    if ($kind !== 'note' && $kind !== 'link') {
        throw new InvalidArgumentException('Unknown tag owner.');
    }
    $table = $kind === 'note' ? 'note_tags' : 'link_tags';
    $column = $kind === 'note' ? 'note_id' : 'link_id';
    $names = normalize_tag_list($raw);

    $delete = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' = ?');
    $delete->execute([$ownerId]);

    $insertTag = $pdo->prepare('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $link = $pdo->prepare('INSERT INTO ' . $table . ' (' . $column . ', tag_id) VALUES (?, ?)');
    $linkedIds = [];
    foreach ($names as $name) {
        $insertTag->execute([$name]);
        $tagId = (int) $pdo->lastInsertId();
        if ($tagId > 0 && !isset($linkedIds[$tagId])) {
            $link->execute([$ownerId, $tagId]);
            $linkedIds[$tagId] = true;
        }
    }

    $pdo->exec(
        'DELETE t FROM tags t
         LEFT JOIN note_tags nt ON nt.tag_id = t.id
         LEFT JOIN link_tags lt ON lt.tag_id = t.id
         WHERE nt.tag_id IS NULL AND lt.tag_id IS NULL'
    );
}

function tag_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return ['id' => (int) $row['id'], 'name' => $row['name']];
}

function tags_with_counts(PDO $pdo): array
{
    $sql = 'SELECT t.id, t.name,
            (SELECT COUNT(*) FROM note_tags nt WHERE nt.tag_id = t.id)
            + (SELECT COUNT(*) FROM link_tags lt WHERE lt.tag_id = t.id) AS item_count
            FROM tags t
            ORDER BY t.name';
    $rows = $pdo->query($sql)->fetchAll();
    $tags = [];
    foreach ($rows as $row) {
        if ((int) $row['item_count'] < 1) {
            continue;
        }
        $tags[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'count' => (int) $row['item_count'],
        ];
    }
    return $tags;
}

function tags_grouped(PDO $pdo, string $kind, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if ($kind === 'note') {
        $sql = 'SELECT nt.note_id AS owner_id, t.name
                FROM note_tags nt
                JOIN tags t ON t.id = nt.tag_id
                WHERE nt.note_id IN (' . $placeholders . ')
                ORDER BY t.name';
    } else {
        $sql = 'SELECT lt.link_id AS owner_id, t.name
                FROM link_tags lt
                JOIN tags t ON t.id = lt.tag_id
                WHERE lt.link_id IN (' . $placeholders . ')
                ORDER BY t.name';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $grouped = [];
    foreach ($stmt as $row) {
        $grouped[(int) $row['owner_id']][] = $row['name'];
    }
    return $grouped;
}
