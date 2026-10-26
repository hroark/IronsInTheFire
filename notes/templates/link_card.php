<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
$linkState = (string) ($item['link_state'] ?? 'unknown');
$linkMark = '';
if ($linkState === 'missing') {
    $linkMark = 'Gone';
} elseif ($linkState === 'unreachable') {
    $linkMark = 'Unreachable';
}
$cardClass = 'notes-card';
if ($linkState === 'missing') {
    $cardClass .= ' is-missing';
} elseif ($linkState === 'unreachable') {
    $cardClass .= ' is-unreachable';
}
$checkedAt = (string) ($item['checked_at'] ?? '');
$returnQuery = $returnQuery ?? 'view=links';
$hasSnapshot = trim((string) ($item['snapshot_text'] ?? '')) !== ''
    || trim((string) ($item['page_description'] ?? '')) !== ''
    || !empty($item['preview_path']);
?>
<article class="<?= e($cardClass) ?>">
    <div class="notes-card-top">
        <div>
            <h2 class="notes-card-title"><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['title']) ?></a></h2>
            <p class="notes-meta"><?= e($item['notebook']) ?> · Saved <?= e(format_time($item['created_at'])) ?></p>
            <?php if ($linkMark !== ''): ?>
                <p class="notes-link-mark<?= $linkState === 'missing' ? ' is-missing' : ' is-unreachable' ?>"><?= e($linkMark) ?><?php if ($checkedAt !== ''): ?> · Checked <?= e(format_time($checkedAt)) ?><?php endif; ?></p>
            <?php elseif ($checkedAt !== ''): ?>
                <p class="notes-meta">Checked <?= e(format_time($checkedAt)) ?><?php if ($linkState === 'blocked'): ?> · Asks for a sign-in<?php endif; ?></p>
            <?php endif; ?>
            <p class="notes-url"><?= e($item['url']) ?></p>
        </div>
        <div class="notes-card-actions">
            <a class="btn btn-brand-soft" href="index.php?view=links&edit=<?= (int) $item['id'] ?>">Edit</a>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="check-link">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                <button class="btn btn-brand-soft" type="submit">Check now</button>
            </form>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="refresh-link">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                <button class="btn btn-brand-soft" type="submit">Refresh snapshot</button>
            </form>
            <?php if ($hasSnapshot): ?>
                <a class="btn btn-brand-soft" href="index.php?view=link&id=<?= (int) $item['id'] ?>">View saved page</a>
            <?php endif; ?>
            <form method="post" action="index.php" data-confirm="Delete this link?">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete-link">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                <button class="btn btn-brand-outline" type="submit">Delete</button>
            </form>
        </div>
    </div>
    <?php if (!empty($item['tags'])): ?>
        <div class="tag-list notes-tags">
            <?php foreach ($item['tags'] as $tagName): ?>
                <span class="tag-chip"><?= e($tagName) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (trim((string) ($item['page_description'] ?? '')) !== ''): ?>
        <p class="notes-excerpt"><?= e($item['page_description']) ?></p>
    <?php endif; ?>
    <?php if (!empty($item['preview_path'])): ?>
        <img class="notes-link-preview" src="index.php?action=link-image&id=<?= (int) $item['id'] ?>" alt="">
    <?php endif; ?>
    <?php if (trim((string) $item['note']) !== ''): ?>
        <p class="notes-excerpt"><?= e($item['note']) ?></p>
    <?php endif; ?>
</article>
