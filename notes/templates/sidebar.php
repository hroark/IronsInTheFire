<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<nav class="notes-sidebar" aria-label="Notes">
    <a class="btn btn-brand notes-new" href="index.php?view=edit<?= !empty($active['notebook']) ? '&notebook=' . (int) $active['notebook'] : '' ?>">New note</a>
    <p class="notes-side-label">Notebooks</p>
    <a class="notes-side-link<?= ($active['view'] ?? '') === 'notes' && empty($active['notebook']) ? ' is-active' : '' ?>" href="index.php?view=notes">All notes</a>
    <?php foreach ($notebooks as $notebook): ?>
        <a class="notes-side-link<?= ($active['view'] ?? '') === 'notes' && (int) ($active['notebook'] ?? 0) === $notebook['id'] ? ' is-active' : '' ?>" href="index.php?view=notes&notebook=<?= (int) $notebook['id'] ?>"><?= e($notebook['name']) ?></a>
    <?php endforeach; ?>
    <form class="notes-inline-form" method="post" action="index.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create-notebook">
        <input type="hidden" name="return" value="view=notes">
        <label class="visually-hidden" for="new-notebook">New notebook</label>
        <input id="new-notebook" class="form-control" type="text" name="name" maxlength="120" placeholder="New notebook" required>
        <button class="btn btn-brand-soft" type="submit">Add</button>
    </form>

    <p class="notes-side-label">Tags</p>
    <?php if (!$tagList): ?>
        <p class="notes-side-empty">Tags appear when you add them to a note or link.</p>
    <?php endif; ?>
    <?php foreach ($tagList as $sidebarTag): ?>
        <a class="notes-side-link<?= ($active['view'] ?? '') === 'tag' && (int) ($active['tag'] ?? 0) === $sidebarTag['id'] ? ' is-active' : '' ?>" href="index.php?view=tag&id=<?= (int) $sidebarTag['id'] ?>">
            <span><?= e($sidebarTag['name']) ?></span>
            <span class="notes-count"><?= (int) $sidebarTag['count'] ?></span>
        </a>
    <?php endforeach; ?>

    <p class="notes-side-label">Views</p>
    <a class="notes-side-link<?= ($active['view'] ?? '') === 'tasks' ? ' is-active' : '' ?>" href="index.php?view=tasks">Open tasks</a>
    <a class="notes-side-link<?= ($active['view'] ?? '') === 'links' ? ' is-active' : '' ?>" href="index.php?view=links">Links</a>
    <a class="notes-side-link<?= ($active['view'] ?? '') === 'archive' ? ' is-active' : '' ?>" href="index.php?view=archive">Archive</a>
</nav>
