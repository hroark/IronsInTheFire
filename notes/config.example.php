<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// Copy this file to config.php and fill in the values from the hosting panel.
// Use the host, database name, and user exactly as that panel lists them.
// Shared hosts usually want localhost, and the names often start with the account name.
// Keep the database password in single quotes when it contains #.
// A .env file or folder is not required.

return [
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'porter_notes',
        'user' => 'notes',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'password_hash' => '',
    'session_name' => 'iitf_notes',
];
