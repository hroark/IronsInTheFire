<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/env.php';
refuse_direct_access(__FILE__);

function db(): PDO
{
    static $pdo = null;
    if (!$pdo instanceof PDO) {
        $config = config();
        $db = is_array($config) ? ($config['db'] ?? []) : [];
        $pdo = new PDO(mysql_dsn(is_array($db) ? $db : []), (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    ensure_notes_schema($pdo);
    return $pdo;
}

function ensure_notes_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $notesReady = false;
    try {
        $pdo->query('SELECT 1 FROM notebooks LIMIT 1');
        $notesReady = true;
    } catch (PDOException $e) {
        $notesReady = false;
    }
    if (!$notesReady) {
        $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
        if (is_string($sql) && trim($sql) !== '') {
            run_sql_script($pdo, $sql);
        }
    }
    try {
        $pdo->query('SELECT 1 FROM app_password LIMIT 1');
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '42S02') {
            throw $e;
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_password (
                id TINYINT UNSIGNED NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $ready = true;
}

function run_sql_script(PDO $pdo, string $sql): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*(?:\n|$)/', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}
