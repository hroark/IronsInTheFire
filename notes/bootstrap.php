<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/guard.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/github.php';
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/records.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/view.php';

function config(): ?array
{
    static $loaded = false;
    static $config = null;
    if ($loaded) {
        return $config;
    }
    $loaded = true;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        return null;
    }
    $value = require $path;
    $config = is_array($value) ? $value : null;
    return $config;
}

function config_problems(): array
{
    $config = config();
    if ($config === null) {
        return ['Copy notes/config.example.php to notes/config.php, then set the database login and password hash.'];
    }
    $problems = [];
    $hash = trim((string) ($config['password_hash'] ?? ''));
    if ($hash === '' || !password_get_info($hash)['algo']) {
        $problems[] = 'Set password_hash in notes/config.php to a value from password_hash().';
    }
    foreach (['host', 'name', 'user'] as $key) {
        if (trim((string) ($config['db'][$key] ?? '')) === '') {
            $problems[] = 'Set the database ' . $key . ' in notes/config.php.';
        }
    }
    return $problems;
}

function start_notes_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $config = config();
    $name = 'iitf_notes';
    if (is_array($config) && preg_match('/^[A-Za-z0-9]+$/', (string) ($config['session_name'] ?? ''))) {
        $name = (string) $config['session_name'];
    }
    session_name($name);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $path = str_replace('\\', '/', (string) $path);
    if ($path === '/' || $path === '.' || $path === '') {
        $path = '/';
    } else {
        $path = rtrim($path, '/') . '/';
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => $secure,
    ]);
    session_start();
}

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src https://fonts.gstatic.com data:; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
