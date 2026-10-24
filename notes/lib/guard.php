<?php
declare(strict_types=1);

function refuse_direct_access(string $file): void
{
    if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath($file)) {
        http_response_code(404);
        exit;
    }
}
