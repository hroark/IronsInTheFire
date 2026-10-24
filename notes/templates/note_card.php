<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<article class="notes-card">
    <div class="notes-card-top">
        <div>
            <?php if (!empty($note['pinned'])): ?>
                <span class="notes-pin">Pinned</span>
            <?php endif; ?>
            <?php if (!empty($note['archived'])): ?>
                <span class="notes-pin">Archived</span>
            <?php endif; ?>
            <h2 class="notes-card-title"><a href="index.php?view=edit&id=<?= (int) $note['id'] ?>"><?= e($note['title']) ?></a></h2>
            <p class="notes-meta"><?= e($note['notebook']) ?> · Updated <?= e(format_time($note['updated_at'])) ?></p>
        </div>
        <div class="notes-card-actions">
            <form method="post" action="index.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="toggle-pin">
                <input type="hidden" name="id" value="<?= (int) $note['id'] ?>">
                <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                <button class="btn btn-brand-soft" type="submit"><?= !empty($note['pinned']) ? 'Unpin' : 'Pin' ?></button>
            </form>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="toggle-archive">
                <input type="hidden" name="id" value="<?= (int) $note['id'] ?>">
                <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                <button class="btn btn-brand-outline" type="submit"><?= !empty($note['archived']) ? 'Restore' : 'Archive' ?></button>
            </form>
        </div>
    </div>
    <?php if (!empty($note['tags'])): ?>
        <div class="tag-list notes-tags">
            <?php foreach ($note['tags'] as $tagName): ?>
                <span class="tag-chip"><?= e($tagName) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php $preview = text_excerpt((string) $note['body_md'], (string) ($query ?? $note['title'])); ?>
    <?php if ($preview !== ''): ?>
        <p class="notes-excerpt"><?= e($preview) ?></p>
    <?php endif; ?>
</article>
