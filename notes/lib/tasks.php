<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function task_pattern(): string
{
    return '/^(\s*[-*+]\s+)\[([ xX])\](.*)$/';
}

function task_lines(string $markdown): array
{
    $lines = preg_split("/\n/", normalize_newlines($markdown)) ?: [];
    $tasks = [];
    $fenceCharacter = null;
    $fenceLength = 0;
    foreach ($lines as $lineNumber => $line) {
        if ($fenceCharacter !== null) {
            $closingFence = '/^ {0,3}' . preg_quote($fenceCharacter, '/') . '{' . $fenceLength . ',}[ \t]*$/';
            if (preg_match($closingFence, $line)) {
                $fenceCharacter = null;
                $fenceLength = 0;
            }
            continue;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $fence)) {
            $fenceCharacter = $fence[1][0];
            $fenceLength = strlen($fence[1]);
            continue;
        }
        if (preg_match(task_pattern(), $line, $matches)) {
            $tasks[] = [
                'line' => $lineNumber,
                'matches' => $matches,
            ];
        }
    }
    return $tasks;
}

function extract_tasks(string $markdown): array
{
    $tasks = [];
    foreach (task_lines($markdown) as $index => $task) {
        $matches = $task['matches'];
        $tasks[] = [
            'index' => $index,
            'line' => $task['line'],
            'done' => strtolower($matches[2]) === 'x',
            'text' => trim($matches[3]),
        ];
    }
    return $tasks;
}

function toggle_task(string $markdown, int $taskIndex): string
{
    $lines = preg_split("/\n/", normalize_newlines($markdown)) ?: [];
    foreach (task_lines($markdown) as $index => $task) {
        if ($index === $taskIndex) {
            $matches = $task['matches'];
            $mark = strtolower($matches[2]) === 'x' ? ' ' : 'x';
            $lines[$task['line']] = $matches[1] . '[' . $mark . ']' . $matches[3];
            break;
        }
    }
    return implode("\n", $lines);
}
