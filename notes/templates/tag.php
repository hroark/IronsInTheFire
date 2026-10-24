<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1><?= e($tag['name']) ?></h1>
        <p>Notes and links that use this tag, including archived notes.</p>
    </div>
</div>

<h2 class="notes-subhead">Notes</h2>
<?php if (!$notes): ?>
    <p class="notes-empty">No current notes use this tag.</p>
<?php endif; ?>
<div class="notes-list">
    <?php foreach ($notes as $note): ?>
        <?php $archivedView = false; ?>
        <?php include __DIR__ . '/note_card.php'; ?>
    <?php endforeach; ?>
</div>

<h2 class="notes-subhead">Links</h2>
<?php if (!$links): ?>
    <p class="notes-empty">No links use this tag.</p>
<?php endif; ?>
<div class="notes-list">
    <?php foreach ($links as $item): ?>
        <article class="notes-card">
            <h2 class="notes-card-title"><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['title']) ?></a></h2>
            <p class="notes-meta"><?= e($item['notebook']) ?> · Saved <?= e(format_time($item['created_at'])) ?></p>
            <p class="notes-url"><?= e($item['url']) ?></p>
            <?php if (trim((string) $item['note']) !== ''): ?>
                <p class="notes-excerpt"><?= e($item['note']) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
