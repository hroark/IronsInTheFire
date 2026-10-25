<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1><?= $note['id'] ? 'Edit note' : 'New note' ?></h1>
        <?php if (!empty($note['updated_at'])): ?>
            <p>Updated <?= e(format_time($note['updated_at'])) ?>. A mermaid block is drawn in the preview.</p>
        <?php else: ?>
            <p>Markdown is saved as you wrote it. A mermaid block is drawn in the preview. Check a task in the preview to update that line.</p>
        <?php endif; ?>
    </div>
    <?php if (!empty($note['id'])): ?>
        <a class="btn btn-brand-outline" href="index.php?action=download&id=<?= (int) $note['id'] ?>">Download Markdown</a>
    <?php endif; ?>
</div>

<form id="note-form" method="post" action="index.php" class="notes-editor">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save-note">
    <input type="hidden" name="id" value="<?= (int) $note['id'] ?>">

    <label for="note-title">Title</label>
    <input id="note-title" class="form-control" type="text" name="title" maxlength="255" value="<?= e($note['title']) ?>" required>

    <div class="notes-editor-row">
        <div>
            <label for="note-notebook">Notebook</label>
            <select id="note-notebook" class="form-select" name="notebook_id">
                <?php foreach ($notebooks as $notebook): ?>
                    <option value="<?= (int) $notebook['id'] ?>"<?= (int) $note['notebook_id'] === $notebook['id'] ? ' selected' : '' ?>><?= e($notebook['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="note-tags">Tags</label>
            <input id="note-tags" class="form-control" type="text" name="tags" value="<?= e($note['tags_text'] ?? '') ?>" placeholder="php, research, home">
        </div>
    </div>

    <div class="notes-checks">
        <label><input type="checkbox" name="pinned"<?= !empty($note['pinned']) ? ' checked' : '' ?>> Pinned</label>
        <label><input type="checkbox" name="archived"<?= !empty($note['archived']) ? ' checked' : '' ?>> Archived</label>
    </div>

    <div class="notes-split">
        <div>
            <label for="note-body">Markdown</label>
            <textarea id="note-body" class="form-control" name="body" rows="18"><?= e($note['body_md']) ?></textarea>
        </div>
        <div>
            <p class="notes-preview-label" id="preview-label">Preview</p>
            <div id="note-preview" class="markdown-preview" aria-labelledby="preview-label">Preview is ready when JavaScript loads.</div>
        </div>
    </div>

    <div class="notes-form-actions">
        <button class="btn btn-brand" type="submit">Save note</button>
        <a class="btn btn-brand-soft" href="index.php?view=notes<?= !empty($note['notebook_id']) ? '&notebook=' . (int) $note['notebook_id'] : '' ?>">Back to notes</a>
    </div>
</form>

<?php if (!empty($note['id'])): ?>
    <form method="post" action="index.php" data-confirm="Delete this note?" class="notes-delete">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="delete-note">
        <input type="hidden" name="id" value="<?= (int) $note['id'] ?>">
        <input type="hidden" name="return" value="view=notes">
        <button class="btn btn-brand-outline" type="submit">Delete note</button>
    </form>
<?php endif; ?>
