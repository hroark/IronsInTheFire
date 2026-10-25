<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/env.php';
refuse_direct_access(__FILE__);

function notes_connection_problem(?array $set = null, bool $clear = false): ?array
{
    static $problem = null;
    if ($clear) {
        $problem = null;
        return null;
    }
    if ($set !== null) {
        $problem = $set;
    }
    return $problem;
}

function db(): PDO
{
    static $pdo = null;
    if (!$pdo instanceof PDO) {
        $config = config();
        $db = is_array($config) && is_array($config['db'] ?? null) ? $config['db'] : [];
        $attempts = database_attempts($db);
        $failure = null;
        foreach ($attempts as $attempt) {
            try {
                $pdo = new PDO(mysql_dsn($attempt), (string) ($attempt['user'] ?? ''), (string) ($attempt['pass'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $failure = null;
                break;
            } catch (PDOException $e) {
                $pdo = null;
                if ($failure === null) {
                    $failure = $e;
                }
            }
        }
        if (!$pdo instanceof PDO) {
            notes_connection_problem([
                'code' => (int) ($failure instanceof PDOException ? ($failure->errorInfo[1] ?? 0) : 0),
                'triedLocalhost' => count($attempts) > 1,
                'connected' => false,
            ]);
            throw $failure instanceof PDOException ? $failure : new PDOException('The notes database could not be opened.');
        }
        notes_connection_problem(null, true);
    }
    try {
        ensure_notes_schema($pdo);
    } catch (PDOException $e) {
        notes_connection_problem([
            'code' => (int) ($e->errorInfo[1] ?? 0),
            'triedLocalhost' => false,
            'connected' => true,
        ]);
        throw $e;
    }
    return $pdo;
}

function notes_database_detail(Throwable $e): string
{
    $config = config();
    $db = is_array($config) && is_array($config['db'] ?? null) ? $config['db'] : [];
    $problem = notes_connection_problem() ?? [];
    return database_failure_message(
        (int) ($problem['code'] ?? ($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0)),
        (string) ($db['host'] ?? ''),
        (string) ($db['user'] ?? ''),
        (string) ($db['name'] ?? ''),
        !empty($problem['triedLocalhost']),
        !empty($problem['connected'])
    );
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
    ensure_journal_schema($pdo);
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
