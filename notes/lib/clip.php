<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function capture_fields(string $url, string $title, string $selection): ?array
{
    $url = str_replace("\0", '', trim($url));
    if (!valid_http_url($url)) {
        return null;
    }
    $title = str_replace("\0", '', trim(preg_replace('/\s+/u', ' ', $title) ?? ''));
    if ($title === '') {
        $title = $url;
    }
    $title = limit_text($title, 255);
    $selection = str_replace("\0", '', normalize_newlines($selection));
    $selection = trim(limit_text($selection, 100000));
    $body = "Source: " . $url . "\n";
    if ($selection !== '') {
        $body .= "\n" . $selection . "\n";
    }
    return ['title' => $title, 'body' => $body];
}

function notes_index_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return ($https ? 'https://' : 'http://') . $host . $script;
}

function clip_bookmarklet(string $indexUrl): string
{
    $target = json_encode($indexUrl . (str_contains($indexUrl, '?') ? '&' : '?') . 'view=clip', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $script = '(function(){var payload={url:location.href,title:document.title,selection:String(window.getSelection()||"")};var hash=encodeURIComponent(JSON.stringify(payload));window.open(' . $target . '+"#"+hash,"notes-clip","width=520,height=720");})();';
    return 'javascript:' . $script;
}
