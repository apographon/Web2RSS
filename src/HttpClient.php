<?php

declare(strict_types=1);

final class HttpClient
{
    public function get(string $url): array
    {
        $timeout = (int) App::cfg('request_timeout', 30);
        $ua = (string) App::cfg('user_agent');
        $restore = $this->withoutProxyEnv();

        try {
            if (function_exists('curl_init')) {
                return $this->curl($url, $timeout, $ua);
            }
            return $this->fopen($url, $timeout, $ua);
        } finally {
            $this->restoreEnv($restore);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function withoutProxyEnv(): array
    {
        $keys = [
            'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY',
            'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy',
            'SOCKS_PROXY', 'SOCKS5_PROXY', 'socks_proxy', 'socks5_proxy',
        ];
        $restore = [];
        foreach ($keys as $key) {
            $restore[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        putenv('NO_PROXY=*');
        putenv('no_proxy=*');
        return $restore;
    }

    /**
     * @param array<string, string|false> $restore
     */
    private function restoreEnv(array $restore): void
    {
        foreach ($restore as $key => $value) {
            if ($value === false || $value === null) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }
    }

    private function curl(string $url, int $timeout, string $ua): array
    {
        $ch = curl_init($url);
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
        ];
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host !== '') {
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            $headers[] = 'Referer: ' . $scheme . '://' . $host . '/';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
        if (defined('CURLPROXY_HTTP')) {
            $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }
        if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_2TLS')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'url' => $final ?: $url, 'body' => '', 'error' => $error ?: 'curl failed'];
        }
        if ($this->looksLikeChallenge((string) $body, $status, $final)) {
            return ['ok' => false, 'status' => $status ?: 403, 'url' => $final ?: $url, 'body' => (string) $body, 'error' => 'HTTP ' . ($status ?: 403) . ' (Bot-Schutz/Challenge)'];
        }
        if ($status >= 400 || $status === 0) {
            return ['ok' => false, 'status' => $status, 'url' => $final ?: $url, 'body' => (string) $body, 'error' => 'HTTP ' . $status];
        }
        return ['ok' => true, 'status' => $status, 'url' => $final ?: $url, 'body' => (string) $body, 'error' => ''];
    }

    private function fopen(string $url, int $timeout, string $ua): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: {$ua}\r\nAccept: text/html\r\nAccept-Language: de-DE,de;q=0.9\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'url' => $url, 'body' => '', 'error' => 'file_get_contents failed'];
        }
        return ['ok' => true, 'status' => 200, 'url' => $url, 'body' => $body, 'error' => ''];
    }

    private function looksLikeChallenge(string $body, int $status, string $finalUrl): bool
    {
        if ($status === 403 || $status === 429 || $status === 503) {
            if (stripos($body, 'cf-mitigated') !== false || stripos($body, 'Just a moment') !== false || stripos($body, 'challenge-platform') !== false) {
                return true;
            }
        }
        $host = strtolower((string) (parse_url($finalUrl, PHP_URL_HOST) ?: ''));
        if (str_contains($host, 'fotocommunity.') && (strlen($body) < 8000) && stripos($body, 'Just a moment') !== false) {
            return true;
        }
        return false;
    }
}
