<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1><?= e($heading) ?></h1>
        <p><?= count($notes) === 1 ? '1 note' : (int) count($notes) . ' notes' ?></p>
    </div>
    <a class="btn btn-brand" href="index.php?view=edit<?= $notebookId ? '&notebook=' . (int) $notebookId : '' ?>">New note</a>
</div>

<?php $isInbox = $notebookId && (int) $notebookId === (int) ($inbox['id'] ?? 0); ?>
<?php if ($isInbox): ?>
    <div class="notes-manage notes-clip-help">
        <div>
            <p class="notes-meta">Clipped pages land here. Open a note to add to it, or move it into another notebook.</p>
            <p class="notes-meta">Drag <a class="notes-bookmarklet" href="<?= e(clip_bookmarklet(notes_index_url())) ?>">Save to Inbox</a> to the bookmarks bar. On a page, select text and click it.</p>
            <p class="notes-meta">The extension is in <code>notes/clipper</code>. In its options, paste this address: <code><?= e(notes_index_url()) ?></code></p>
        </div>
    </div>
<?php endif; ?>
<?php if ($notebookId && empty($archivedView) && !$isInbox): ?>
    <?php $counts = notebook_counts(db(), (int) $notebookId); ?>
    <div class="notes-manage">
        <form method="post" action="index.php" class="notes-inline-form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="rename-notebook">
            <input type="hidden" name="id" value="<?= (int) $notebookId ?>">
            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <label class="visually-hidden" for="rename-notebook">Rename notebook</label>
            <input id="rename-notebook" class="form-control" type="text" name="name" maxlength="120" value="<?= e($heading) ?>" required>
            <button class="btn btn-brand-soft" type="submit">Rename</button>
        </form>
        <form method="post" action="index.php" data-confirm="Delete this empty notebook?">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete-notebook">
            <input type="hidden" name="id" value="<?= (int) $notebookId ?>">
            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <button class="btn btn-brand-outline" type="submit">Delete notebook</button>
        </form>
        <?php if ($counts['notes'] > 0 || $counts['links'] > 0): ?>
            <p class="notes-meta">This notebook still has <?= (int) $counts['notes'] ?> notes and <?= (int) $counts['links'] ?> links.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$notes): ?>
    <p class="notes-empty">Nothing here yet. Start a note, or save a link you want to come back to.</p>
<?php endif; ?>

<div class="notes-list">
    <?php foreach ($notes as $note): ?>
        <?php include __DIR__ . '/note_card.php'; ?>
    <?php endforeach; ?>
</div>
