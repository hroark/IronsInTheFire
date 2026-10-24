<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<?php
$openCount = 0;
foreach ($taskNotes as $taskNote) {
    foreach ($taskNote['tasks'] as $task) {
        if (empty($task['done'])) {
            $openCount++;
        }
    }
}
?>
<div class="notes-heading">
    <div>
        <h1>Open tasks</h1>
        <p><?= (int) $openCount === 1 ? '1 open task' : (int) $openCount . ' open tasks' ?> across unarchived notes. Checking a box rewrites that line in the note.</p>
    </div>
</div>

<?php if (!$taskNotes): ?>
    <p class="notes-empty">No tasks yet. Add a Markdown line such as <code>- [ ] Read the docs</code> inside a note.</p>
<?php endif; ?>

<?php foreach ($taskNotes as $taskNote): ?>
    <section class="notes-card">
        <h2 class="notes-card-title"><a href="index.php?view=edit&id=<?= (int) $taskNote['id'] ?>"><?= e($taskNote['title']) ?></a></h2>
        <p class="notes-meta"><?= e($taskNote['notebook']) ?></p>
        <ul class="task-groups">
            <?php foreach ($taskNote['tasks'] as $task): ?>
                <li>
                    <form method="post" action="index.php" class="task-row<?= !empty($task['done']) ? ' is-done' : '' ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="toggle-task">
                        <input type="hidden" name="id" value="<?= (int) $taskNote['id'] ?>">
                        <input type="hidden" name="task_index" value="<?= (int) $task['index'] ?>">
                        <input type="hidden" name="return" value="view=tasks">
                        <button type="submit" aria-pressed="<?= !empty($task['done']) ? 'true' : 'false' ?>">
                            <span class="task-box" aria-hidden="true"></span>
                            <span><?= e($task['text'] !== '' ? $task['text'] : 'Empty task') ?></span>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
