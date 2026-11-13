<?php

declare(strict_types=1);

final class UrlHelper
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && !self::isDefaultPort($scheme, (int) $port)) {
            $host .= ':' . $port;
        }
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $path = self::collapsePath($path);
        $query = $parts['query'] ?? '';
        $query = self::cleanQuery($query);
        $out = $scheme . '://' . $host . $path;
        if ($query !== '') {
            $out .= '?' . $query;
        }
        return $out;
    }

    public static function resolve(string $base, string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:') || str_starts_with(strtolower($href), 'mailto:') || str_starts_with(strtolower($href), 'tel:')) {
            return '';
        }
        if (str_starts_with($href, '//')) {
            $baseParts = parse_url($base);
            $scheme = $baseParts['scheme'] ?? 'https';
            return self::normalize($scheme . ':' . $href);
        }
        if (preg_match('#^https?://#i', $href)) {
            return self::normalize($href);
        }
        $baseParts = parse_url($base);
        if ($baseParts === false || empty($baseParts['host'])) {
            return '';
        }
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $origin = $scheme . '://' . $host . $port;
        if (str_starts_with($href, '/')) {
            return self::normalize($origin . $href);
        }
        $basePath = $baseParts['path'] ?? '/';
        if (!str_ends_with($basePath, '/')) {
            $basePath = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';
        }
        return self::normalize($origin . $basePath . $href);
    }

    public static function host(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    }

    public static function path(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        return $path === '' ? '/' : $path;
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    public static function pathPrefix(string $url): string
    {
        $path = rtrim(self::path($url), '/');
        return $path === '' ? '/' : $path;
    }

    public static function extension(string $url): string
    {
        $path = self::path($url);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext;
    }

    public static function imageIdentity(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $file = basename($path);
        $dir = dirname($path);
        if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $file, $m)) {
            return $host . $dir . '/' . strtolower($m[1]);
        }
        $file = preg_replace('/[_-](rw_\d+|carw?_\d+x\d+(?:x\d+)?|scaled|thumb|thumbnail|small|medium|large|\d+x\d+)(?=\.[a-z0-9]+$)/i', '', $file) ?? $file;
        return $host . $dir . '/' . strtolower($file);
    }

    public static function imageScore(string $url): int
    {
        $u = strtolower($url);
        $score = 0;
        if (preg_match('/(?:rw_|x)(\d{2,5})/i', $u, $m)) {
            $score += (int) $m[1];
        }
        if (preg_match('/(\d{3,4})w/i', $u, $m)) {
            $score += (int) $m[1];
        }
        if (str_contains($u, 'x32') || str_contains($u, 'x64') || str_contains($u, 'thumb')) {
            $score -= 10_000;
        }
        return $score;
    }

    public static function isProbablyFile(string $url): bool
    {
        $ext = self::extension($url);
        if ($ext === '') {
            return false;
        }
        $skip = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff',
            'pdf', 'zip', 'rar', '7z', 'mp3', 'mp4', 'mov', 'avi', 'css', 'js', 'json', 'xml',
            'woff', 'woff2', 'ttf', 'eot', 'map', 'php', 'ics',
        ];
        return in_array($ext, $skip, true);
    }

    public static function sameHost(string $a, string $b): bool
    {
        return self::host($a) === self::host($b) && self::host($a) !== '';
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    private static function collapsePath(string $path): string
    {
        $path = preg_replace('#/{2,}#', '/', $path) ?: $path;
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $i => $seg) {
            if ($seg === '.' || ($seg === '' && $i !== 0 && $i !== count($segments) - 1)) {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $joined = implode('/', $out);
        if (!str_starts_with($joined, '/')) {
            $joined = '/' . $joined;
        }
        return $joined;
    }

    private static function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        if (!is_array($params)) {
            return '';
        }
        $drop = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', 'PHPSESSID'];
        foreach ($drop as $key) {
            unset($params[$key]);
        }
        if ($params === []) {
            return '';
        }
        ksort($params);
        return http_build_query($params);
    }
}
