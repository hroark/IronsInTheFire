<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
$folderId = (int) ($folder['id'] ?? 0);
$folderLines = upload_folder_lines($folders);
?>
<div class="notes-heading">
    <div>
        <h1><?= $folder ? e($folder['name']) : 'Files' ?></h1>
        <p class="notes-crumbs">
            <a href="index.php?view=files">Files</a>
            <?php foreach ($breadcrumbs as $crumb): ?>
                <span aria-hidden="true">/</span>
                <?php if ((int) $crumb['id'] === $folderId): ?>
                    <span><?= e($crumb['name']) ?></span>
                <?php else: ?>
                    <a href="index.php?view=files&folder=<?= (int) $crumb['id'] ?>"><?= e($crumb['name']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </p>
    </div>
    <?php if ($folder): ?>
        <form method="post" action="index.php" data-confirm="Delete this empty folder?">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete-folder">
            <input type="hidden" name="id" value="<?= $folderId ?>">
            <button class="btn btn-brand-outline" type="submit">Delete folder</button>
        </form>
    <?php endif; ?>
</div>

<div class="notes-files">
    <aside class="notes-file-tree" aria-label="Folders">
        <a class="notes-folder-link<?= $folder ? '' : ' is-active' ?>" data-folder-id="0" href="index.php?view=files">All files</a>
        <?= upload_tree_html($folders, null, $folder ? $folder['id'] : null) ?>
        <form class="notes-inline-form" method="post" action="index.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="create-folder">
            <input type="hidden" name="folder_id" value="<?= $folderId ?>">
            <label class="visually-hidden" for="new-folder">New folder</label>
            <input id="new-folder" class="form-control" type="text" name="name" maxlength="120" placeholder="New folder" required>
            <button class="btn btn-brand-soft" type="submit">Add</button>
        </form>
    </aside>

    <div>
        <form id="file-drop" class="notes-dropzone" method="post" action="index.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="upload-files">
            <input type="hidden" name="folder_id" value="<?= $folderId ?>">
            <p class="notes-drop-title">Drop images or files here</p>
            <p class="notes-meta">Or browse. Images, PDF, text, zip, and Office files up to 8 MB.</p>
            <label class="btn btn-brand-soft" for="file-browse">Browse</label>
            <input id="file-browse" class="visually-hidden" type="file" name="files[]" multiple>
            <button class="btn btn-brand" type="submit">Upload</button>
        </form>

        <?php if (!$childFolders && !$files): ?>
            <p class="notes-empty">Nothing in this folder yet.</p>
        <?php endif; ?>

        <?php if ($childFolders): ?>
            <div class="notes-list">
                <?php foreach ($childFolders as $child): ?>
                    <article class="notes-card">
                        <h2 class="notes-card-title"><a data-folder-id="<?= (int) $child['id'] ?>" href="index.php?view=files&folder=<?= (int) $child['id'] ?>"><?= e($child['name']) ?></a></h2>
                        <p class="notes-meta">Folder</p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="notes-list">
            <?php foreach ($files as $file): ?>
                <article class="notes-card notes-file-card">
                    <?php if ($file['image']): ?>
                        <img class="notes-file-preview" src="index.php?action=file&id=<?= (int) $file['id'] ?>" alt="">
                    <?php endif; ?>
                    <div>
                        <h2 class="notes-card-title"><a href="index.php?action=file&id=<?= (int) $file['id'] ?>&download=1"><?= e($file['original_name']) ?></a></h2>
                        <p class="notes-meta"><?= e(upload_size_label((int) $file['byte_size'])) ?> · <?= e(format_time($file['created_at'])) ?></p>
                        <div class="notes-card-actions">
                            <form method="post" action="index.php" class="notes-move-form">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="move-file">
                                <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                <label class="visually-hidden" for="move-file-<?= (int) $file['id'] ?>">Move <?= e($file['original_name']) ?></label>
                                <select id="move-file-<?= (int) $file['id'] ?>" class="form-select" name="folder_id">
                                    <option value="0">All files</option>
                                    <?php foreach ($folderLines as $line): ?>
                                        <option value="<?= (int) $line['id'] ?>"<?= (int) ($file['folder_id'] ?? 0) === (int) $line['id'] ? ' selected' : '' ?>><?= e(str_repeat('· ', (int) $line['depth']) . $line['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-brand-soft" type="submit">Move</button>
                            </form>
                            <form method="post" action="index.php" data-confirm="Delete this file?">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="delete-file">
                                <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                <button class="btn btn-brand-outline" type="submit">Delete</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>
