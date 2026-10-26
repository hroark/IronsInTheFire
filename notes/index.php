<?php
declare(strict_types=1);

$notesResponseSent = false;
register_shutdown_function(static function () use (&$notesResponseSent): void {
    if ($notesResponseSent) {
        return;
    }
    $error = error_get_last();
    if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log('Notes fatal: ' . $error['message']);
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Notes unavailable</title></head><body><p>The notes page could not be opened. Check the database user and password in notes/config.php. The database name is porter_notes.</p></body></html>';
});

require __DIR__ . '/bootstrap.php';

try {
    send_security_headers();
    start_notes_session();
    dispatch_notes();
    $notesResponseSent = true;
} catch (Throwable $e) {
    $notesResponseSent = true;
    error_log('Notes error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        render('db_error', [
            'bare' => true,
            'pageTitle' => 'Notes unavailable',
            'detail' => notes_database_detail($e),
        ]);
    } catch (Throwable $renderError) {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Notes unavailable</title></head><body><p>The notes page could not be opened. Check the database user and password in notes/config.php. The database name is porter_notes.</p></body></html>';
    }
}

function dispatch_notes(): void
{
    end_notes_visit_on_entry();

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
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['view'] ?? '') === 'clip') {
            $_SESSION['after_login'] = 'view=clip';
        } else {
            unset($_SESSION['after_login']);
        }
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
    if ($action === 'journal-data') {
        journal_data_action();
        return;
    }
    if ($action === 'file') {
        file_download_action((int) ($_GET['id'] ?? 0));
        return;
    }

    render_current_view();
}
