<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<section class="content-panel notes-login">
    <span class="eyebrow">Notes</span>
    <h1 class="section-title">The notes database is unavailable.</h1>
    <p class="section-copy mb-0"><?= e($detail ?? 'Check the database user and password in notes/config.php. The database name is porter_notes.') ?></p>
    <p class="notes-back"><a href="../index.html">Back to the portfolio</a></p>
</section>
