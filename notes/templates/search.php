<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1>Search</h1>
        <p><?= $query === '' ? 'Enter a word to search notes and links.' : 'Results for “' . e($query) . '”' ?></p>
    </div>
</div>

<?php if ($query !== ''): ?>
    <h2 class="notes-subhead">Notes</h2>
    <?php if (!$notes): ?>
        <p class="notes-empty">No notes matched.</p>
    <?php endif; ?>
    <div class="notes-list">
        <?php foreach ($notes as $note): ?>
            <?php $archivedView = false; ?>
            <?php include __DIR__ . '/note_card.php'; ?>
        <?php endforeach; ?>
    </div>

    <h2 class="notes-subhead">Links</h2>
    <?php if (!$links): ?>
        <p class="notes-empty">No links matched.</p>
    <?php endif; ?>
    <div class="notes-list">
        <?php foreach ($links as $item): ?>
            <?php include __DIR__ . '/link_card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
