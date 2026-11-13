<?php

declare(strict_types=1);

/**
 * A source is a listing, not a whole website.
 * Posts are entries linked from that listing. Pagination is followed only
 * to see those entries. The rest of the host stays out of the feed.
 */
final class ListingRules
{
    /** @var list<string> */
    private const NAV_SEGMENTS = [
        'about', 'account', 'agb', 'api', 'approach', 'assets', 'audience',
        'author', 'cart', 'category', 'cdn-cgi', 'checkout', 'company',
        'contact', 'datenschutz', 'dist', 'feed', 'home', 'impressum',
        'imprint', 'legal', 'login', 'newsletter', 'page', 'privacy',
        'privacy-policy', 'search', 'shop', 'static', 'tag', 'tags',
        'warenkorb', 'widgets', 'wp-content', 'wp-includes',
    ];

    /** @var list<string> */
    private const JUNK_PATHS = [
        '/_next/', '/_nuxt/', '/wp-content/', '/wp-includes/', '/assets/',
        '/static/', '/dist/', '/real/', '/widgets/', '/navigation/', '/cdn-cgi/',
    ];

    public static function isPagination(string $url, string $startUrl): bool
    {
        $url = UrlHelper::normalize($url);
        $startUrl = UrlHelper::normalize($startUrl);
        if ($url === '' || UrlHelper::host($url) !== UrlHelper::host($startUrl)) {
            return false;
        }
        $path = rtrim(UrlHelper::path($url), '/');
        $startPath = rtrim(UrlHelper::path($startUrl), '/');
        if (preg_match('#/page/\d+$#', $path) === 1) {
            $base = rtrim((string) preg_replace('#/page/\d+$#', '', $path), '/');
            return $base === $startPath;
        }
        if ($path !== $startPath) {
            return false;
        }
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return false;
        }
        parse_str($query, $params);
        foreach (['page', 'paged', 'p', 'seite'] as $key) {
            if (isset($params[$key]) && preg_match('/^\d+$/', (string) $params[$key]) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function isPost(string $url, string $startUrl): bool
    {
        return self::accept($url, $startUrl, true);
    }

    /** Same-host entry when the listing itself has no child paths (portfolios). */
    public static function isLoosePost(string $url, string $startUrl): bool
    {
        return self::accept($url, $startUrl, false);
    }

    /**
     * A new listing URL becomes a feed item only when it is actually new
     * since the previous crawl. Undated archive pages do not.
     */
    public static function shouldPublish(bool $bootstrapped, bool $known, ?int $published, ?int $lastRun, bool $onFirstPage): bool
    {
        if (!$bootstrapped || $known) {
            return false;
        }
        if (is_int($published)) {
            return $lastRun !== null && $published >= $lastRun;
        }
        return $onFirstPage;
    }

    private static function accept(string $url, string $startUrl, bool $requireChild): bool
    {
        $url = UrlHelper::normalize($url);
        $startUrl = UrlHelper::normalize($startUrl);
        if ($url === '' || $url === $startUrl) {
            return false;
        }
        if (UrlHelper::host($url) !== UrlHelper::host($startUrl)) {
            return false;
        }
        if (self::isPagination($url, $startUrl)) {
            return false;
        }
        if ((string) (parse_url($url, PHP_URL_QUERY) ?? '') !== '') {
            return false;
        }
        if (Exclusions::isExcluded($url) || UrlHelper::isProbablyFile($url)) {
            return false;
        }
        $path = rtrim(UrlHelper::path($url), '/');
        if ($path === '' || $path === '/') {
            return false;
        }
        $lower = strtolower($path);
        foreach (self::JUNK_PATHS as $junk) {
            if (str_contains($lower, $junk)) {
                return false;
            }
        }
        $prefix = rtrim(UrlHelper::pathPrefix($startUrl), '/');
        if ($requireChild && $prefix !== '' && $prefix !== '/') {
            if (!str_starts_with($path, $prefix . '/')) {
                return false;
            }
            $rest = trim(substr($path, strlen($prefix)), '/');
            if ($rest === '') {
                return false;
            }
            $segments = explode('/', $rest);
        } else {
            $segments = explode('/', trim($path, '/'));
        }
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), self::NAV_SEGMENTS, true)) {
                return false;
            }
        }
        if ($requireChild && ($prefix === '' || $prefix === '/')) {
            // On a site root, a single path segment is a section, not an entry.
            return count($segments) >= 2;
        }
        return true;
    }
}
