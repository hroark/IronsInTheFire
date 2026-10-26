<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
$viewName = (string) ($active['view'] ?? '');
$notebooksOpen = $viewName === 'notes' || $viewName === 'edit';
$tagsOpen = $viewName === 'tag';
$libraryOpen = in_array($viewName, ['tasks', 'links', 'journal', 'files', 'archive', 'clip'], true);
?>
<nav class="notes-sidebar" aria-label="Notes">
    <a class="btn btn-brand notes-new" href="index.php?view=edit<?= !empty($active['notebook']) ? '&notebook=' . (int) $active['notebook'] : '' ?>">New note</a>

    <details class="notes-tree"<?= $notebooksOpen ? ' open' : '' ?>>
        <summary>Notebooks</summary>
        <div class="notes-tree-body">
            <a class="notes-side-link<?= $viewName === 'notes' && empty($active['notebook']) ? ' is-active' : '' ?>" href="index.php?view=notes">All notes</a>
            <div class="notes-notebook-list" data-notebook-list>
                <?php foreach ($notebooks as $notebook): ?>
                    <a class="notes-side-link<?= $viewName === 'notes' && (int) ($active['notebook'] ?? 0) === $notebook['id'] ? ' is-active' : '' ?>" draggable="true" data-notebook-id="<?= (int) $notebook['id'] ?>" data-drop-notebook="<?= (int) $notebook['id'] ?>" href="index.php?view=notes&notebook=<?= (int) $notebook['id'] ?>" title="Drag to reorder">
                        <span><?= e($notebook['name']) ?></span>
                        <?php if ((int) $notebook['id'] === (int) ($inbox['id'] ?? 0)): ?>
                            <span class="notes-count"><?= (int) ($inboxCount ?? 0) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <form class="notes-inline-form" method="post" action="index.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="create-notebook">
                <input type="hidden" name="return" value="view=notes">
                <label class="visually-hidden" for="new-notebook">New notebook</label>
                <input id="new-notebook" class="form-control" type="text" name="name" maxlength="120" placeholder="New notebook" required>
                <button class="btn btn-brand-soft" type="submit">Add</button>
            </form>
        </div>
    </details>

    <details class="notes-tree"<?= $tagsOpen ? ' open' : '' ?>>
        <summary>Tags<?php if ($tagList): ?> <span class="notes-count"><?= count($tagList) ?></span><?php endif; ?></summary>
        <div class="notes-tree-body">
            <?php if (!$tagList): ?>
                <p class="notes-side-empty">Tags appear when you add them to a note or link.</p>
            <?php endif; ?>
            <?php foreach ($tagList as $sidebarTag): ?>
                <a class="notes-side-link<?= $viewName === 'tag' && (int) ($active['tag'] ?? 0) === $sidebarTag['id'] ? ' is-active' : '' ?>" href="index.php?view=tag&id=<?= (int) $sidebarTag['id'] ?>">
                    <span><?= e($sidebarTag['name']) ?></span>
                    <span class="notes-count"><?= (int) $sidebarTag['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </details>

    <details class="notes-tree"<?= $libraryOpen ? ' open' : '' ?>>
        <summary>Library</summary>
        <div class="notes-tree-body">
            <a class="notes-side-link<?= $viewName === 'journal' ? ' is-active' : '' ?>" href="index.php?view=journal">Journal</a>
            <a class="notes-side-link<?= $viewName === 'tasks' ? ' is-active' : '' ?>" href="index.php?view=tasks">Open tasks</a>
            <a class="notes-side-link<?= $viewName === 'links' ? ' is-active' : '' ?>" href="index.php?view=links">Links</a>
            <a class="notes-side-link<?= $viewName === 'files' ? ' is-active' : '' ?>" href="index.php?view=files">Files</a>
            <a class="notes-side-link<?= $viewName === 'archive' ? ' is-active' : '' ?>" href="index.php?view=archive">Archive</a>
        </div>
    </details>
</nav>
