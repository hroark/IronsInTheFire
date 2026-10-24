<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

// Copy this file to config.php and fill in the values.
// Generate the password hash on the server:
// php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'notes',
        'user' => 'notes',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'password_hash' => '',
    'session_name' => 'iitf_notes',
];
