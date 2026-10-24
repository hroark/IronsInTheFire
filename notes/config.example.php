<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// Copy this file to config.php and fill in the values.
// A .env file or folder is not required.
// The database name is porter_notes. Leave that name as it is.
// On the first visit, the notes page asks you to choose a sign-in password.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'porter_notes',
        'user' => 'notes',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'password_hash' => '',
    'session_name' => 'iitf_notes',
];
