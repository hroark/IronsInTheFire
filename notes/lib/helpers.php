<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
refuse_direct_access(__FILE__);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function slug(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') {
        return 'item';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 60, 'UTF-8');
    }
    return substr($value, 0, 60);
}

function format_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('M j, Y g:i a', $timestamp);
}

function like_contains(string $term): string
{
    $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    return '%' . $term . '%';
}

function valid_http_url(string $url): bool
{
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function safe_query(?string $value, string $fallback): string
{
    $value = $value ?? '';
    if ($value === '' || strpbrk($value, "\r\n") !== false) {
        return $fallback;
    }
    if (preg_match('/^[a-z0-9_=&%.-]+$/i', $value) !== 1) {
        return $fallback;
    }
    return $value;
}

function text_excerpt(string $body, string $query): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    if ($plain === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    $pos = function_exists('mb_stripos') ? mb_stripos($plain, $query, 0, 'UTF-8') : stripos($plain, $query);
    if ($pos === false) {
        $slice = function_exists('mb_substr') ? mb_substr($plain, 0, 160, 'UTF-8') : substr($plain, 0, 160);
        return $length > 160 ? $slice . '…' : $slice;
    }
    $start = max(0, $pos - 40);
    $slice = function_exists('mb_substr') ? mb_substr($plain, $start, 160, 'UTF-8') : substr($plain, $start, 160);
    $prefix = $start > 0 ? '…' : '';
    $end = $start + 160 < $length ? '…' : '';
    return $prefix . $slice . $end;
}

function normalize_newlines(string $value): string
{
    return str_replace(["\r\n", "\r"], "\n", $value);
}

function limit_text(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}
