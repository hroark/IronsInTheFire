<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1><?= !empty($link['id']) ? 'Edit link' : 'Links' ?></h1>
        <p>Save a URL with a short note. A public GitHub URL can fill in the title.</p>
    </div>
</div>

<form method="post" action="index.php" class="notes-card notes-link-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save-link">
    <input type="hidden" name="id" value="<?= (int) ($link['id'] ?? 0) ?>">
    <label for="link-url">URL</label>
    <input id="link-url" class="form-control" type="url" name="url" maxlength="2048" value="<?= e($link['url'] ?? '') ?>" placeholder="https://" required>
    <label for="link-title">Title</label>
    <input id="link-title" class="form-control" type="text" name="title" maxlength="255" value="<?= e($link['title'] ?? '') ?>" placeholder="Filled from GitHub when this is empty">
    <div class="notes-editor-row">
        <div>
            <label for="link-notebook">Notebook</label>
            <select id="link-notebook" class="form-select" name="notebook_id">
                <?php foreach ($notebooks as $notebook): ?>
                    <option value="<?= (int) $notebook['id'] ?>"<?= (int) ($link['notebook_id'] ?? 0) === $notebook['id'] ? ' selected' : '' ?>><?= e($notebook['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="link-tags">Tags</label>
            <input id="link-tags" class="form-control" type="text" name="tags" value="<?= e($link['tags_text'] ?? '') ?>" placeholder="php, github">
        </div>
    </div>
    <label for="link-note">Note</label>
    <textarea id="link-note" class="form-control" name="note" rows="4"><?= e($link['note'] ?? '') ?></textarea>
    <div class="notes-form-actions">
        <button class="btn btn-brand" type="submit"><?= !empty($link['id']) ? 'Update link' : 'Save link' ?></button>
        <?php if (!empty($link['id'])): ?>
            <a class="btn btn-brand-soft" href="index.php?view=links">Cancel</a>
        <?php endif; ?>
    </div>
</form>

<div class="notes-list">
    <?php if (!$links): ?>
        <p class="notes-empty">No links saved yet.</p>
    <?php endif; ?>
    <?php foreach ($links as $item): ?>
        <article class="notes-card">
            <div class="notes-card-top">
                <div>
                    <h2 class="notes-card-title"><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['title']) ?></a></h2>
                    <p class="notes-meta"><?= e($item['notebook']) ?> · Saved <?= e(format_time($item['created_at'])) ?></p>
                    <p class="notes-url"><?= e($item['url']) ?></p>
                </div>
                <div class="notes-card-actions">
                    <a class="btn btn-brand-soft" href="index.php?view=links&edit=<?= (int) $item['id'] ?>">Edit</a>
                    <form method="post" action="index.php" data-confirm="Delete this link?">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="delete-link">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
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
            <?php if (trim((string) $item['note']) !== ''): ?>
                <p class="notes-excerpt"><?= e($item['note']) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
