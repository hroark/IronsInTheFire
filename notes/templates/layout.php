<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title><?= e($pageTitle) ?> | Notes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="../src/css/styles.css">
    <link rel="stylesheet" href="assets/notes.css">
</head>
<body class="site-page notes-app">
<?php if (!empty($bare)): ?>
    <main class="notes-auth">
        <?php include $contentTemplate; ?>
    </main>
<?php else: ?>
    <header class="notes-topbar">
        <a class="notes-brand" href="../index.html">Patrick Porter</a>
        <span class="notes-kicker">Notes</span>
        <form class="notes-search" method="get" action="index.php">
            <label class="visually-hidden" for="search-q">Search notes and links</label>
            <input type="hidden" name="view" value="search">
            <input id="search-q" class="form-control" type="search" name="q" value="<?= e($query ?? '') ?>" placeholder="Search notes and links">
            <button class="btn btn-brand-soft" type="submit">Search</button>
        </form>
        <a class="btn btn-brand-outline" href="index.php?action=export">Download zip</a>
        <form method="post" action="index.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-brand-soft" type="submit">Log out</button>
        </form>
    </header>
    <div class="notes-shell">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <main class="notes-main">
            <?php include __DIR__ . '/flash.php'; ?>
            <?php include $contentTemplate; ?>
        </main>
    </div>
<?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/marked@4.3.0/marked.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.4/dist/purify.min.js" crossorigin="anonymous"></script>
    <script src="assets/notes.js"></script>
    <?php if (($active['view'] ?? '') === 'clip'): ?>
        <script src="assets/clip.js"></script>
    <?php endif; ?>
    <?php if (($active['view'] ?? '') === 'journal'): ?>
        <script src="assets/journal-crypto.js"></script>
        <script src="assets/journal-page.js"></script>
    <?php endif; ?>
</body>
</html>
