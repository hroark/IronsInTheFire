<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function read_env_text(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $vars = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $equals = strpos($line, '=');
        if ($equals === false) {
            continue;
        }
        $name = trim(substr($line, 0, $equals));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }
        $value = trim(substr($line, $equals + 1));
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(?:\s+#.*)?$/', $value, $matches)) {
            $value = stripcslashes($matches[1]);
        } elseif (preg_match("/^'(.*?)'(?:\\s+#.*)?$/", $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match('/^(.*?)\s+#.*$/', $value, $matches)) {
            $value = rtrim($matches[1]);
        }
        $vars[$name] = $value;
    }
    return $vars;
}

function notes_database_name(string $name): string
{
    $name = trim($name);
    if ($name === '' || strcasecmp($name, 'irondb') === 0) {
        return 'porter_notes';
    }
    return $name;
}

function config_from_text(string $contents): ?array
{
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    if (trim($contents) === '') {
        return null;
    }
    $contents = preg_replace('/<\?(?:php)?|\?>/i', '', $contents) ?? $contents;
    return config_from_env(read_env_text($contents));
}

function config_from_env(array $env): ?array
{
    $user = trim((string) ($env['MYSQL_USER'] ?? ''));
    $name = notes_database_name((string) ($env['MYSQL_DATABASE'] ?? ''));
    $hash = trim((string) ($env['NOTES_PASSWORD_HASH'] ?? ''));
    $plain = (string) ($env['NOTES_PASSWORD'] ?? '');
    $pass = (string) ($env['MYSQL_PASSWORD'] ?? '');
    if ($user === '' && trim((string) ($env['MYSQL_DATABASE'] ?? '')) === '' && $hash === '' && $plain === '' && $pass === '') {
        return null;
    }
    $host = trim((string) ($env['MYSQL_HOST'] ?? ''));
    if ($host === '') {
        $host = '127.0.0.1';
    }
    $port = trim((string) ($env['MYSQL_PORT'] ?? ''));
    if (!preg_match('/^[0-9]+$/', $port)) {
        $port = '3306';
    }
    return [
        'db' => [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'charset' => 'utf8mb4',
        ],
        'password_hash' => $hash,
        'password_plain' => $plain,
        'session_name' => 'iitf_notes',
    ];
}

function mysql_dsn(array $db): string
{
    $charset = strtolower((string) ($db['charset'] ?? 'utf8mb4'));
    if (!preg_match('/^[a-z0-9]+$/', $charset)) {
        $charset = 'utf8mb4';
    }
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $name = (string) ($db['name'] ?? '');
    $port = (string) ($db['port'] ?? '');
    if ($port !== '' && preg_match('/^[0-9]+$/', $port) && !($host === 'localhost' && $port === '3306')) {
        return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset;
    }
    return 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $charset;
}
