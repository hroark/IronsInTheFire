<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<div class="notes-heading">
    <div>
        <h1>Journal</h1>
        <p>This journal has its own password. Entries are encrypted in this browser before they are saved. The notes sign-in password cannot read them, and a lost journal password cannot be recovered.</p>
    </div>
    <button id="journal-lock" class="btn btn-brand-outline" type="button" hidden>Lock journal</button>
</div>

<section id="journal-gate" class="notes-card journal-gate">
    <h2 id="journal-gate-title">Unlock your journal</h2>
    <p id="journal-gate-copy">Enter the journal password.</p>
    <form id="journal-gate-form" class="notes-login-form">
        <label for="journal-password">Journal password</label>
        <input id="journal-password" class="form-control" type="password" autocomplete="current-password" minlength="8" required>
        <div id="journal-confirm-wrap" hidden>
            <label for="journal-confirm">Confirm journal password</label>
            <input id="journal-confirm" class="form-control" type="password" autocomplete="new-password" minlength="8">
        </div>
        <p id="journal-error" class="notes-flash is-error" role="alert" hidden></p>
        <button id="journal-submit" class="btn btn-brand" type="submit">Unlock</button>
    </form>
</section>

<section id="journal-app" class="journal-layout" hidden>
    <div>
        <button id="journal-new" class="btn btn-brand" type="button">New entry</button>
        <div id="journal-list" class="notes-list"></div>
        <p id="journal-empty" class="notes-empty">No journal entries yet.</p>
    </div>
    <form id="journal-editor" class="notes-editor" hidden>
        <label for="journal-title">Title</label>
        <input id="journal-title" class="form-control" type="text" maxlength="160" required>
        <label for="journal-body">Entry</label>
        <textarea id="journal-body" class="form-control" rows="16" maxlength="100000"></textarea>
        <div class="notes-form-actions">
            <button class="btn btn-brand" type="submit">Save entry</button>
            <button id="journal-delete" class="btn btn-brand-outline" type="button">Delete</button>
            <p id="journal-status" class="notes-meta">Encrypted before it is saved.</p>
        </div>
    </form>
</section>
