<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

send_security_headers();
start_notes_session();

try {
    dispatch_notes();
} catch (PDOException $e) {
    error_log('Notes database error: ' . $e->getMessage());
    http_response_code(500);
    render('db_error', [
        'bare' => true,
        'pageTitle' => 'Notes unavailable',
    ]);
}

function dispatch_notes(): void
{
    $problems = config_problems();
    if ($problems) {
        render_login();
        return;
    }

    if (!sign_in_password_ready()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'choose_password') {
            handle_choose_password();
            return;
        }
        render_login(null, true);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'login') {
            handle_login();
            return;
        }
        if (!is_logged_in()) {
            http_response_code(403);
            exit('Sign in required.');
        }
        csrf_check();
        handle_post($action);
        return;
    }

    if (!is_logged_in()) {
        render_login();
        return;
    }

    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'download') {
        download_note_action((int) ($_GET['id'] ?? 0));
        return;
    }
    if ($action === 'export') {
        download_zip_action();
        return;
    }

    render_current_view();
}
