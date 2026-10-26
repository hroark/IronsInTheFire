<?php
declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/helpers.php';
refuse_direct_access(__FILE__);

function snapshot_classify_status(?int $status): string
{
    if ($status === null || $status < 200 || $status >= 600) {
        return 'unreachable';
    }
    if ($status === 401 || $status === 403) {
        return 'blocked';
    }
    if ($status === 404 || $status === 410) {
        return 'missing';
    }
    if ($status >= 200 && $status < 400) {
        return 'up';
    }
    return 'unreachable';
}

function snapshot_known_state(string $state): string
{
    if (in_array($state, ['unknown', 'up', 'missing', 'blocked', 'unreachable'], true)) {
        return $state;
    }
    return 'unreachable';
}

function snapshot_state_label(string $state): string
{
    switch (snapshot_known_state($state)) {
        case 'up':
            return 'Up';
        case 'missing':
            return 'Gone';
        case 'blocked':
            return 'Blocked';
        case 'unreachable':
            return 'Unreachable';
        default:
            return '';
    }
}

function snapshot_replaces_body(string $state, string $previousText): bool
{
    return $state === 'up' || trim($previousText) === '';
}

function snapshot_ip_is_private(string $ip): bool
{
    $ip = strtolower(trim($ip, '[]'));
    if (str_starts_with($ip, '::ffff:')) {
        $ip = substr($ip, 7);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    $packed = @inet_pton($ip);
    if (!is_string($packed) || strlen($packed) !== 4) {
        return false;
    }
    $unpacked = unpack('N', $packed);
    if (!is_array($unpacked)) {
        return true;
    }
    $value = (int) $unpacked[1];
    $start = unpack('N', inet_pton('100.64.0.0'));
    $end = unpack('N', inet_pton('100.127.255.255'));
    if (!is_array($start) || !is_array($end)) {
        return true;
    }
    return $value >= (int) $start[1] && $value <= (int) $end[1];
}

function snapshot_host_is_local_name(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    return $host === 'localhost' || $host === 'localhost.localdomain' || str_ends_with($host, '.localhost');
}

function snapshot_host_addresses(string $host): ?array
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }
    if (snapshot_host_is_local_name($host)) {
        return ['127.0.0.1'];
    }
    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip']) && $record['ip'] !== '') {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6']) && $record['ipv6'] !== '') {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }
    if (!$ips) {
        $listed = @gethostbynamel($host);
        if (is_array($listed)) {
            foreach ($listed as $ip) {
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
    }
    $ips = array_values(array_unique($ips));
    return $ips === [] ? null : $ips;
}

function snapshot_request_target(string $url): ?array
{
    if (!valid_http_url($url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
        return null;
    }
    $host = (string) $parts['host'];
    $ips = snapshot_host_addresses($host);
    if ($ips === null) {
        return null;
    }
    foreach ($ips as $ip) {
        if (snapshot_ip_is_private($ip)) {
            return null;
        }
    }
    $ip = $ips[0];
    foreach ($ips as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = $candidate;
            break;
        }
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) {
        return null;
    }
    return [
        'url' => $url,
        'host' => trim($host, '[]'),
        'ip' => $ip,
        'port' => $port,
    ];
}

function snapshot_url_is_allowed(string $url): bool
{
    return snapshot_request_target($url) !== null;
}

function snapshot_normalize_http_url(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    $segments = [];
    foreach (explode('/', (string) ($parts['path'] ?? '/')) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $path = '/' . implode('/', $segments);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $built = $scheme . '://' . $parts['host'] . $port . $path . $query;
    return valid_http_url($built) ? $built : null;
}

function snapshot_resolve_url(string $base, string $relative): ?string
{
    $relative = trim(str_replace("\0", '', $relative));
    if ($relative === '' || preg_match('/[\r\n]/', $relative) === 1 || preg_match('#^(javascript|data):#i', $relative) === 1) {
        return null;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    if (str_starts_with($relative, '//')) {
        return snapshot_normalize_http_url($scheme . ':' . $relative);
    }
    if (valid_http_url($relative)) {
        return snapshot_normalize_http_url($relative);
    }
    $origin = $scheme . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    if (str_starts_with($relative, '/')) {
        $path = $relative;
    } else {
        $basePath = (string) ($parts['path'] ?? '/');
        $dir = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';
        $path = $dir . $relative;
    }
    return snapshot_normalize_http_url($origin . $path);
}

function snapshot_clean_meta(string $value, int $max): string
{
    $value = str_replace("\0", '', $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return limit_text($value, $max);
}

function snapshot_clean_text(string $value): string
{
    $value = str_replace("\0", '', $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return limit_text($value, 40000);
}

function snapshot_as_utf8(string $html): string
{
    if (!function_exists('mb_convert_encoding') || preg_match('/charset\s*=\s*["\']?\s*([a-zA-Z0-9._-]+)/i', $html, $match) !== 1) {
        return $html;
    }
    $charset = strtolower($match[1]);
    if ($charset === 'utf-8' || $charset === 'utf8') {
        return $html;
    }
    $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
    return is_string($converted) && $converted !== '' ? $converted : $html;
}

function snapshot_remove_nodes(DOMDocument $dom, array $tags): void
{
    foreach ($tags as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        while ($nodes->length > 0) {
            $node = $nodes->item(0);
            if ($node === null || $node->parentNode === null) {
                break;
            }
            $node->parentNode->removeChild($node);
        }
    }
}

function snapshot_meta(string $html, string $baseUrl): array
{
    $title = '';
    $documentTitle = '';
    $description = '';
    $namedDescription = '';
    $site = '';
    $image = '';
    $text = '';
    if (class_exists(DOMDocument::class)) {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . snapshot_as_utf8($html), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim($meta->getAttribute('property')));
            if ($key === '') {
                $key = strtolower(trim($meta->getAttribute('name')));
            }
            $content = trim($meta->getAttribute('content'));
            if ($key === '' || $content === '') {
                continue;
            }
            if ($key === 'og:title' && $title === '') {
                $title = $content;
            } elseif ($key === 'og:description' && $description === '') {
                $description = $content;
            } elseif ($key === 'description' && $namedDescription === '') {
                $namedDescription = $content;
            } elseif ($key === 'og:site_name' && $site === '') {
                $site = $content;
            } elseif (($key === 'og:image' || $key === 'twitter:image' || $key === 'twitter:image:src') && $image === '') {
                $image = $content;
            }
        }
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0 && $titleNodes->item(0) !== null) {
            $documentTitle = trim((string) $titleNodes->item(0)->textContent);
        }
        snapshot_remove_nodes($dom, ['script', 'style', 'noscript', 'svg', 'template', 'head']);
        $text = (string) $dom->textContent;
    } else {
        if (preg_match('/property=["\']og:title["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $match) === 1) {
            $title = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) === 1) {
            $documentTitle = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/property=["\']og:description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $match) === 1) {
            $description = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $match) === 1) {
            $namedDescription = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/property=["\']og:site_name["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $match) === 1) {
            $site = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/property=["\']og:image["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $match) === 1) {
            $image = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        $stripped = preg_replace('/<(script|style|noscript|head)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($stripped);
    }
    if ($title === '') {
        $title = $documentTitle;
    }
    if ($description === '') {
        $description = $namedDescription;
    }
    $imageUrl = $image === '' ? '' : (snapshot_resolve_url($baseUrl, $image) ?? '');
    return [
        'title' => snapshot_clean_meta($title, 255),
        'description' => snapshot_clean_meta($description, 1000),
        'site_name' => snapshot_clean_meta($site, 255),
        'image_url' => $imageUrl,
        'text' => snapshot_clean_text($text),
    ];
}

function snapshot_image_extension(string $bytes): ?string
{
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'jpg';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
        return 'png';
    }
    if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
        return 'gif';
    }
    if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

function snapshot_empty(string $finalUrl): array
{
    return [
        'state' => 'unreachable',
        'status' => null,
        'final_url' => $finalUrl,
        'body' => '',
        'truncated' => false,
    ];
}

function snapshot_curl_address(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return '[' . $ip . ']';
    }
    return $ip;
}

function snapshot_curl(array $target, int $maxBytes): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($target['url']);
    if ($ch === false) {
        return null;
    }
    $body = '';
    $truncated = false;
    $location = '';
    $options = [
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'IronsInTheFire-Notes',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,image/webp,image/apng,*/*;q=0.8'],
        CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . snapshot_curl_address($target['ip'])],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$truncated, $maxBytes): int {
            if ($truncated || strlen($body) >= $maxBytes) {
                $truncated = true;
                return 0;
            }
            $room = $maxBytes - strlen($body);
            $body .= substr($chunk, 0, $room);
            if (strlen($chunk) > $room) {
                $truncated = true;
                return 0;
            }
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
            if (preg_match('/^Location:\s*(\S+)/i', $header, $match) === 1) {
                $location = trim($match[1]);
            }
            return strlen($header);
        },
    ];
    if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
    }
    curl_setopt_array($ch, $options);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 100 || $status > 599) {
        return null;
    }
    if (preg_match('/[\r\n]/', $location) === 1) {
        $location = '';
    }
    return [
        'status' => $status,
        'body' => $body,
        'truncated' => $truncated,
        'location' => $location,
    ];
}

function snapshot_request(string $url, int $maxBytes): array
{
    $current = $url;
    $redirects = 0;
    while ($redirects <= 3) {
        $target = snapshot_request_target($current);
        if ($target === null) {
            return snapshot_empty($current);
        }
        $response = snapshot_curl($target, $maxBytes);
        if ($response === null) {
            return snapshot_empty($current);
        }
        $status = (int) $response['status'];
        $location = (string) $response['location'];
        $isRedirect = $status >= 300 && $status < 400 && $location !== '';
        if ($isRedirect && $redirects < 3) {
            $next = snapshot_resolve_url($current, $location);
            if ($next === null || snapshot_request_target($next) === null) {
                return snapshot_empty($current);
            }
            $current = $next;
            $redirects++;
            continue;
        }
        return [
            'state' => snapshot_classify_status($status),
            'status' => $status,
            'final_url' => $current,
            'body' => (string) $response['body'],
            'truncated' => (bool) $response['truncated'],
        ];
    }
    return snapshot_empty($current);
}

function snapshot_capture(string $url): array
{
    $page = snapshot_request($url, 1500000);
    $meta = [
        'title' => '',
        'description' => '',
        'site_name' => '',
        'image_url' => '',
        'text' => '',
    ];
    if ($page['body'] !== '') {
        $base = $page['final_url'] !== '' ? $page['final_url'] : $url;
        $meta = snapshot_meta($page['body'], $base);
    }
    $imageBytes = '';
    $imageExtension = null;
    if ($page['state'] === 'up' && $meta['image_url'] !== '') {
        $image = snapshot_request($meta['image_url'], 2000000);
        if ($image['state'] === 'up' && $image['body'] !== '' && !$image['truncated']) {
            $imageExtension = snapshot_image_extension($image['body']);
            if ($imageExtension !== null) {
                $imageBytes = $image['body'];
            }
        }
    }
    return [
        'state' => $page['state'],
        'status' => $page['status'],
        'title' => $meta['title'],
        'description' => $meta['description'],
        'site_name' => $meta['site_name'],
        'text' => $meta['text'],
        'image_bytes' => $imageBytes,
        'image_extension' => $imageExtension,
    ];
}

function snapshot_probe(string $url): array
{
    $page = snapshot_request($url, 8192);
    return [
        'state' => $page['state'],
        'status' => $page['status'],
    ];
}

function snapshot_directory(): string
{
    $dir = dirname(__DIR__) . '/snapshots';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        $rules = "Options -Indexes\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
        file_put_contents($htaccess, $rules);
    }
    return $dir;
}

function snapshot_delete_file(?string $name): void
{
    $path = snapshot_file_path((string) $name);
    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

function snapshot_file_path(string $name): ?string
{
    if (preg_match('/^[0-9]+-[a-f0-9]{16}\.(jpg|png|gif|webp)$/', $name) !== 1) {
        return null;
    }
    $dir = dirname(__DIR__) . '/snapshots';
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        return null;
    }
    $real = realpath($path);
    $root = realpath($dir);
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $real;
}

function snapshot_store_image(int $id, string $bytes, string $extension, ?string $previous): ?string
{
    if ($id < 1 || snapshot_image_extension($bytes) !== $extension) {
        return null;
    }
    if (!in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
        return null;
    }
    $dir = snapshot_directory();
    $name = $id . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (file_put_contents($dir . '/' . $name, $bytes) === false) {
        return null;
    }
    if ($previous !== null && $previous !== $name) {
        snapshot_delete_file($previous);
    }
    return $name;
}

function snapshot_image_type(string $name): ?string
{
    $types = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $types[$extension] ?? null;
}
