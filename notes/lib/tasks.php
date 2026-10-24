<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function task_pattern(): string
{
    return '/^(\s*[-*+]\s+)\[([ xX])\](.*)$/';
}

function extract_tasks(string $markdown): array
{
    $lines = preg_split("/\n/", normalize_newlines($markdown)) ?: [];
    $tasks = [];
    $index = 0;
    foreach ($lines as $lineNumber => $line) {
        if (!preg_match(task_pattern(), $line, $matches)) {
            continue;
        }
        $tasks[] = [
            'index' => $index,
            'line' => $lineNumber,
            'done' => strtolower($matches[2]) === 'x',
            'text' => trim($matches[3]),
        ];
        $index++;
    }
    return $tasks;
}

function toggle_task(string $markdown, int $taskIndex): string
{
    $lines = preg_split("/\n/", normalize_newlines($markdown)) ?: [];
    $index = 0;
    foreach ($lines as $lineNumber => $line) {
        if (!preg_match(task_pattern(), $line, $matches)) {
            continue;
        }
        if ($index === $taskIndex) {
            $mark = strtolower($matches[2]) === 'x' ? ' ' : 'x';
            $lines[$lineNumber] = $matches[1] . '[' . $mark . ']' . $matches[3];
            break;
        }
        $index++;
    }
    return implode("\n", $lines);
}
