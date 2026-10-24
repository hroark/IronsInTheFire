<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = config();
    $db = $config['db'] ?? [];
    $charset = $db['charset'] ?? 'utf8mb4';
    if (!preg_match('/^[a-z0-9]+$/', (string) $charset)) {
        $charset = 'utf8mb4';
    }
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        $db['name'] ?? '',
        $charset
    );
    $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
