<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<section class="content-panel notes-login">
    <span class="eyebrow">Notes</span>
    <h1 class="section-title">A private place for research.</h1>
    <?php if (!empty($problems)): ?>
        <p class="section-copy">Finish setup before signing in.</p>
        <ul class="notes-setup">
            <?php foreach ($problems as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="section-copy mb-0">The database is named <code>porter_notes</code>. No <code>.env</code> folder is required.</p>
    <?php elseif (!empty($choosePassword)): ?>
        <p class="section-copy">Choose a password for these notes. This is separate from the database password.</p>
        <?php if (!empty($error)): ?>
            <p class="notes-flash is-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="index.php" class="notes-login-form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="choose_password">
            <label for="setup_secret">Setup secret</label>
            <input id="setup_secret" class="form-control" type="password" name="setup_secret" autocomplete="off" required>
            <label for="password">Password</label>
            <input id="password" class="form-control" type="password" name="password" autocomplete="new-password" minlength="8" required>
            <label for="confirm">Confirm password</label>
            <input id="confirm" class="form-control" type="password" name="confirm" autocomplete="new-password" minlength="8" required>
            <button class="btn btn-brand" type="submit">Save password</button>
        </form>
    <?php else: ?>
        <p class="section-copy">Sign in with the site password. Notes stay on this server.</p>
        <?php if (!empty($error)): ?>
            <p class="notes-flash is-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="index.php" class="notes-login-form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="login">
            <label for="password">Password</label>
            <input id="password" class="form-control" type="password" name="password" autocomplete="current-password" required>
            <button class="btn btn-brand" type="submit">Sign in</button>
        </form>
    <?php endif; ?>
    <p class="notes-back"><a href="../index.html">Back to the portfolio</a></p>
</section>
