<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<?php if (!empty($flashMessage)): ?>
    <p class="notes-flash <?= ($flashMessage['type'] ?? '') === 'error' ? 'is-error' : 'is-success' ?>" role="status">
        <?= e($flashMessage['message'] ?? '') ?>
    </p>
<?php endif; ?>
