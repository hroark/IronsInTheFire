<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
$linkState = (string) ($link['link_state'] ?? 'unknown');
$linkMark = '';
if ($linkState === 'missing') {
    $linkMark = 'Gone';
} elseif ($linkState === 'unreachable') {
    $linkMark = 'Unreachable';
}
$checkedAt = (string) ($link['checked_at'] ?? '');
$capturedAt = (string) ($link['captured_at'] ?? '');
?>
<div class="notes-heading">
    <div>
        <h1><?= e($link['title']) ?></h1>
        <p>Saved copy of this page. It stays here if the site goes offline.</p>
    </div>
    <div class="notes-card-actions">
        <a class="btn btn-brand-soft" href="index.php?view=links">Back to links</a>
        <form method="post" action="index.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="check-link">
            <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <button class="btn btn-brand-soft" type="submit">Check now</button>
        </form>
        <form method="post" action="index.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="refresh-link">
            <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <button class="btn btn-brand" type="submit">Refresh snapshot</button>
        </form>
    </div>
</div>

<article class="notes-card<?= $linkState === 'missing' ? ' is-missing' : ($linkState === 'unreachable' ? ' is-unreachable' : '') ?>">
    <p class="notes-meta"><?= e($link['notebook']) ?> · Saved <?= e(format_time($link['created_at'])) ?></p>
    <?php if ($linkMark !== ''): ?>
        <p class="notes-link-mark<?= $linkState === 'missing' ? ' is-missing' : ' is-unreachable' ?>"><?= e($linkMark) ?><?php if ($checkedAt !== ''): ?> · Checked <?= e(format_time($checkedAt)) ?><?php endif; ?></p>
    <?php elseif ($checkedAt !== ''): ?>
        <p class="notes-meta">Checked <?= e(format_time($checkedAt)) ?><?php if ($linkState === 'blocked'): ?> · Asks for a sign-in<?php endif; ?></p>
    <?php endif; ?>
    <?php if ($capturedAt !== ''): ?>
        <p class="notes-meta">Snapshot <?= e(format_time($capturedAt)) ?><?php if (trim((string) $link['site_name']) !== ''): ?> · <?= e($link['site_name']) ?><?php endif; ?></p>
    <?php endif; ?>
    <p class="notes-url"><a href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($link['url']) ?></a></p>
    <?php if (trim((string) $link['page_description']) !== ''): ?>
        <p class="notes-excerpt"><?= e($link['page_description']) ?></p>
    <?php endif; ?>
    <?php if (!empty($link['preview_path'])): ?>
        <img class="notes-link-preview" src="index.php?action=link-image&id=<?= (int) $link['id'] ?>" alt="">
    <?php endif; ?>
    <?php if (trim((string) $link['note']) !== ''): ?>
        <p class="notes-excerpt"><?= e($link['note']) ?></p>
    <?php endif; ?>
    <?php if (trim((string) $link['snapshot_text']) !== ''): ?>
        <pre class="notes-snapshot"><?= e($link['snapshot_text']) ?></pre>
    <?php else: ?>
        <p class="notes-empty">No saved copy of this page yet.</p>
    <?php endif; ?>
</article>
