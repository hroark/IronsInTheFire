<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function journal_valid_blob(string $value, int $max): bool
{
    return $value !== '' && strlen($value) <= $max && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $value) === 1;
}

function journal_iterations(string $value): ?int
{
    if (!preg_match('/^[0-9]+$/', $value)) {
        return null;
    }
    $iterations = (int) $value;
    if ($iterations < 100000 || $iterations > 1000000) {
        return null;
    }
    return $iterations;
}

function journal_create_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS journal_seal (
            id TINYINT UNSIGNED NOT NULL,
            kdf_iterations INT UNSIGNED NOT NULL,
            salt VARCHAR(255) NOT NULL,
            verifier_iv VARCHAR(255) NOT NULL,
            verifier VARCHAR(512) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS journal_entries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            iv VARCHAR(255) NOT NULL,
            ciphertext MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_journal_entries_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function journal_column_type(PDO $pdo, string $table, string $column): string
{
    $statement = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
    $row = $statement ? $statement->fetch() : false;
    if (!is_array($row)) {
        return '';
    }
    $type = strtolower((string) ($row['Type'] ?? ''));
    foreach (['datetime', 'bigint', 'varchar', 'int'] as $name) {
        if (str_starts_with($type, $name)) {
            return $name;
        }
    }
    return $type;
}

function ensure_journal_schema(PDO $pdo): void
{
    journal_create_tables($pdo);
    $sealReady = journal_column_type($pdo, 'journal_seal', 'created_at') === 'datetime';
    $entriesReady = journal_column_type($pdo, 'journal_entries', 'id') === 'int';
    if ($sealReady && $entriesReady) {
        return;
    }
    $sealCount = (int) $pdo->query('SELECT COUNT(*) FROM journal_seal')->fetchColumn();
    $entryCount = (int) $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    if ($sealCount !== 0 || $entryCount !== 0) {
        throw new RuntimeException('The journal tables need to be recreated before encrypted entries can be saved.');
    }
    $pdo->exec('DROP TABLE journal_entries');
    $pdo->exec('DROP TABLE journal_seal');
    journal_create_tables($pdo);
}

function journal_seal_row(PDO $pdo): ?array
{
    $statement = $pdo->query('SELECT kdf_iterations, salt, verifier_iv, verifier FROM journal_seal WHERE id = 1');
    $row = $statement ? $statement->fetch() : false;
    return is_array($row) ? $row : null;
}

function journal_data_action(): void
{
    $pdo = db();
    $seal = journal_seal_row($pdo);
    $entries = [];
    $statement = $pdo->query('SELECT id, iv, ciphertext, updated_at FROM journal_entries ORDER BY updated_at DESC, id DESC');
    $rows = $statement ? $statement->fetchAll() : [];
    foreach ($rows as $row) {
        $entries[] = [
            'id' => (int) $row['id'],
            'iv' => (string) $row['iv'],
            'ciphertext' => (string) $row['ciphertext'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
    json_out([
        'ok' => true,
        'configured' => $seal !== null,
        'iterations' => $seal ? (int) $seal['kdf_iterations'] : 0,
        'salt' => $seal ? (string) $seal['salt'] : '',
        'verifierIv' => $seal ? (string) $seal['verifier_iv'] : '',
        'verifier' => $seal ? (string) $seal['verifier'] : '',
        'entries' => $entries,
    ]);
}

function journal_seal_action(): void
{
    $iterations = journal_iterations((string) ($_POST['iterations'] ?? ''));
    $salt = (string) ($_POST['salt'] ?? '');
    $iv = (string) ($_POST['verifierIv'] ?? '');
    $verifier = (string) ($_POST['verifier'] ?? '');
    if ($iterations === null || !journal_valid_blob($salt, 128) || !journal_valid_blob($iv, 64) || !journal_valid_blob($verifier, 512)) {
        json_out(['ok' => false, 'error' => 'The journal password could not be saved.'], 400);
    }
    $pdo = db();
    if (journal_seal_row($pdo) !== null) {
        json_out(['ok' => false, 'error' => 'The journal already has a password.'], 409);
    }
    $statement = $pdo->prepare('INSERT INTO journal_seal (id, kdf_iterations, salt, verifier_iv, verifier) VALUES (1, ?, ?, ?, ?)');
    $statement->execute([$iterations, $salt, $iv, $verifier]);
    json_out(['ok' => true]);
}

function journal_save_action(): void
{
    $iv = (string) ($_POST['iv'] ?? '');
    $ciphertext = (string) ($_POST['ciphertext'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if (!journal_valid_blob($iv, 64) || !journal_valid_blob($ciphertext, 700000)) {
        json_out(['ok' => false, 'error' => 'That journal entry could not be saved.'], 400);
    }
    $pdo = db();
    if (journal_seal_row($pdo) === null) {
        json_out(['ok' => false, 'error' => 'Choose a journal password first.'], 409);
    }
    if ($id > 0) {
        $statement = $pdo->prepare('UPDATE journal_entries SET iv = ?, ciphertext = ? WHERE id = ?');
        $statement->execute([$iv, $ciphertext, $id]);
        if ($statement->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT id FROM journal_entries WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                json_out(['ok' => false, 'error' => 'That journal entry is not available.'], 404);
            }
        }
    } else {
        $statement = $pdo->prepare('INSERT INTO journal_entries (iv, ciphertext) VALUES (?, ?)');
        $statement->execute([$iv, $ciphertext]);
        $id = (int) $pdo->lastInsertId();
    }
    $updated = $pdo->prepare('SELECT updated_at FROM journal_entries WHERE id = ?');
    $updated->execute([$id]);
    json_out([
        'ok' => true,
        'id' => $id,
        'updatedAt' => (string) $updated->fetchColumn(),
    ]);
}

function journal_delete_action(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1) {
        json_out(['ok' => false, 'error' => 'That journal entry could not be deleted.'], 400);
    }
    $statement = db()->prepare('DELETE FROM journal_entries WHERE id = ?');
    $statement->execute([$id]);
    json_out(['ok' => true]);
}
