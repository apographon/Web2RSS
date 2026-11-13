<?php

declare(strict_types=1);

final class Exclusions
{
    /** @var list<string> */
    private const PATH_NEEDLES = [
        'impressum', 'imprint', 'datenschutz', 'privacy', 'privacy-policy',
        'kontakt', 'contact', 'login', 'wp-login', 'wp-admin', 'wp-json',
        'xmlrpc', 'warenkorb', 'cart', 'checkout', 'account', 'mein-konto',
        '/suche', '/search', '/feed', '/comments', '/trackback',
        '/tag/', '/tags/', '/author/', '/attachment', '/page/',
        'newsletter', '/agb', 'cookie', 'rechtliches',
        'cdn-cgi', 'wp-cron', '/cgi-bin', 'kartehimmel.php',
    ];

    /** @var list<string> */
    private const PATH_EQUALS = [
        '/feed', '/feed/', '/comments/feed', '/comments/feed/',
        '/robots.txt', '/sitemap.xml', '/favicon.ico',
    ];

    public static function isExcluded(string $url): bool
    {
        $path = strtolower(UrlHelper::path($url));
        if (in_array($path, self::PATH_EQUALS, true)) {
            return true;
        }
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            parse_str($query, $params);
            if (isset($params['page_id']) || isset($params['p']) || isset($params['attachment_id'])) {
                return true;
            }
        }
        foreach (self::PATH_NEEDLES as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }
        if (UrlHelper::isProbablyFile($url)) {
            return true;
        }
        // WordPress date archives: /2024/06/ or /2024/06/12/
        if (preg_match('#^/\d{4}/(\d{2}/)?(\d{2}/)?$#', $path)) {
            return true;
        }
        return false;
    }
}
