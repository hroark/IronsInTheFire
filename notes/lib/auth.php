<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function wants_json(): bool
{
    $header = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strcasecmp((string) $header, 'XMLHttpRequest') === 0;
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    $valid = is_string($sent) && is_string($known) && $known !== '' && hash_equals($known, $sent);
    if ($valid) {
        return;
    }
    if (wants_json()) {
        json_out(['ok' => false, 'error' => 'Invalid request.'], 400);
    }
    http_response_code(400);
    exit('Invalid request.');
}

function is_logged_in(): bool
{
    return !empty($_SESSION['notes_auth']);
}

function notes_referrer_is_notes(string $referer, string $host): bool
{
    if ($referer === '' || $host === '') {
        return false;
    }
    $parts = parse_url($referer);
    if (!is_array($parts)) {
        return false;
    }
    $refererHost = strtolower((string) ($parts['host'] ?? ''));
    $siteHost = strtolower($host);
    $siteHost = preg_replace('/:\d+$/', '', $siteHost) ?? $siteHost;
    if ($refererHost === '' || $refererHost !== $siteHost) {
        return false;
    }
    $path = (string) ($parts['path'] ?? '/');
    return $path === '/notes' || str_starts_with($path, '/notes/');
}

function notes_request_is_new_entry(string $method, string $referer, string $host, string $fetchMode): bool
{
    if (strtoupper($method) !== 'GET') {
        return false;
    }
    if ($fetchMode !== '' && strcasecmp($fetchMode, 'navigate') !== 0) {
        return false;
    }
    return !notes_referrer_is_notes($referer, $host);
}

function end_notes_visit_on_entry(): void
{
    if (!notes_request_is_new_entry(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        (string) ($_SERVER['HTTP_HOST'] ?? ''),
        (string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')
    )) {
        return;
    }
    unset($_SESSION['notes_auth']);
}

function configured_password_hash(): string
{
    $config = config() ?? [];
    $hash = $config['password_hash'] ?? '';
    if (!is_string($hash)) {
        return '';
    }
    $hash = trim($hash);
    if ($hash === '' || !password_get_info($hash)['algo']) {
        return '';
    }
    return $hash;
}

function configured_password_plain(): string
{
    $config = config() ?? [];
    $plain = $config['password_plain'] ?? '';
    return is_string($plain) ? $plain : '';
}

function saved_sign_in_hash(): string
{
    $statement = db()->query('SELECT password_hash FROM app_password WHERE id = 1');
    $hash = $statement ? $statement->fetchColumn() : false;
    return is_string($hash) ? $hash : '';
}

function sign_in_password_ready(): bool
{
    if (configured_password_hash() !== '' || configured_password_plain() !== '') {
        return true;
    }
    return saved_sign_in_hash() !== '';
}

function verify_sign_in_password(string $password): bool
{
    $hash = configured_password_hash();
    if ($hash !== '') {
        return password_verify($password, $hash);
    }
    $plain = configured_password_plain();
    if ($plain !== '') {
        return hash_equals($plain, $password);
    }
    $saved = saved_sign_in_hash();
    return $saved !== '' && password_verify($password, $saved);
}

function save_sign_in_password(string $password): bool
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        return false;
    }
    try {
        $statement = db()->prepare('INSERT INTO app_password (id, password_hash) VALUES (1, ?)');
        return $statement->execute([$hash]);
    } catch (PDOException $e) {
        if ((string) ($e->errorInfo[1] ?? '') !== '1062') {
            throw $e;
        }
        return false;
    }
}

function login_state_path(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return sys_get_temp_dir() . '/iitf-notes-login-' . hash('sha256', $ip) . '.json';
}

function login_state(): array
{
    $path = login_state_path();
    if (!is_file($path)) {
        return ['failures' => 0, 'locked_until' => 0];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return ['failures' => 0, 'locked_until' => 0];
    }
    return [
        'failures' => (int) ($decoded['failures'] ?? 0),
        'locked_until' => (int) ($decoded['locked_until'] ?? 0),
    ];
}

function login_write(array $state): void
{
    $path = login_state_path();
    file_put_contents($path, json_encode($state), LOCK_EX);
    @chmod($path, 0600);
}

function login_locked(): bool
{
    return login_state()['locked_until'] > time();
}

function login_fail(): void
{
    $state = login_state();
    $state['failures']++;
    if ($state['failures'] >= 5) {
        $state['locked_until'] = time() + (15 * 60);
    }
    login_write($state);
}

function login_clear(): void
{
    $path = login_state_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function redirect_to(string $query): void
{
    header('Location: index.php' . ($query === '' ? '' : '?' . $query));
    exit;
}

function send_download(string $filename, string $contents, string $type): void
{
    $filename = str_replace(['"', "\r", "\n"], '', $filename);
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($contents));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $contents;
    exit;
}
