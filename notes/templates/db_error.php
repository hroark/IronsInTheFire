<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<section class="content-panel notes-login">
    <span class="eyebrow">Notes</span>
    <h1 class="section-title">The notes database is unavailable.</h1>
    <p class="section-copy mb-0">Check the database name, user, and password in <code>notes/config.php</code>, and confirm the schema has been imported. The server log has the database message.</p>
    <p class="notes-back"><a href="../index.html">Back to the portfolio</a></p>
</section>
