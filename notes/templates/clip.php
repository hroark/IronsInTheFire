<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1>Save to Inbox</h1>
        <p id="clip-status">Open this page from the Save to Inbox bookmark or extension.</p>
    </div>
</div>
<form id="clip-form" method="post" action="index.php" hidden>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="capture">
    <input type="hidden" name="url" value="">
    <input type="hidden" name="title" value="">
    <input type="hidden" name="selection" value="">
</form>
