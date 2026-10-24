<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function github_api_url(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
        return null;
    }
    $host = strtolower($parts['host']);
    if ($host === 'www.github.com') {
        $host = 'github.com';
    }
    if ($host !== 'github.com') {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $segments = array_values(array_filter(explode('/', $parts['path']), static function ($segment) {
        return $segment !== '';
    }));
    if (count($segments) < 2) {
        return null;
    }
    $owner = $segments[0];
    $repo = $segments[1];
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $owner) || !preg_match('/^[A-Za-z0-9_.-]+$/', $repo)) {
        return null;
    }

    $api = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
    if (isset($segments[2], $segments[3]) && ($segments[2] === 'issues' || $segments[2] === 'pull') && preg_match('/^[0-9]{1,12}$/', $segments[3])) {
        $api .= '/issues/' . $segments[3];
    }
    return $api;
}

function github_title(string $url): ?string
{
    $api = github_api_url($url);
    if ($api === null) {
        return null;
    }
    $data = http_get_json($api);
    if (!is_array($data)) {
        return null;
    }
    if (isset($data['title']) && is_string($data['title']) && trim($data['title']) !== '') {
        return limit_text(trim($data['title']), 255);
    }
    if (isset($data['full_name']) && is_string($data['full_name']) && trim($data['full_name']) !== '') {
        return limit_text(trim($data['full_name']), 255);
    }
    return null;
}

function http_get_json(string $url): ?array
{
    if (strpos($url, 'https://api.github.com/') !== 0) {
        return null;
    }
    $body = http_get($url);
    if ($body === null || $body === '' || strlen($body) > 1000000) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'IronsInTheFire-Notes',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ];
        if (defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 4,
            'follow_location' => 0,
            'header' => "User-Agent: IronsInTheFire-Notes\r\nAccept: application/vnd.github+json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) ? $body : null;
}
