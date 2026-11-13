<?php

declare(strict_types=1);

final class SourcePolicy
{
    /** Hosts that are shared platforms, not a personal site. */
    private const PLATFORM_HOSTS = [
        'fotocommunity.de',
        'www.fotocommunity.de',
        'en.fotocommunity.de',
    ];

    public function __construct(
        public readonly string $startUrl,
        public readonly string $scope, // domain|prefix
        public readonly string $host,
        public readonly string $prefix,
    ) {
    }

    public static function forUrl(string $startUrl): self
    {
        $startUrl = UrlHelper::normalize($startUrl);
        $host = UrlHelper::host($startUrl);
        $scope = self::isPlatform($host) ? 'prefix' : 'domain';
        $prefix = UrlHelper::pathPrefix($startUrl);
        return new self($startUrl, $scope, $host, $prefix);
    }

    public static function isPlatform(string $host): bool
    {
        $host = strtolower($host);
        foreach (self::PLATFORM_HOSTS as $platform) {
            if ($host === $platform || str_ends_with($host, '.' . $platform)) {
                return true;
            }
        }
        return false;
    }

    public function allows(string $url): bool
    {
        $url = UrlHelper::normalize($url);
        if ($url === '') {
            return false;
        }
        if (UrlHelper::host($url) !== $this->host) {
            return false;
        }
        if (Exclusions::isExcluded($url)) {
            return false;
        }
        if ($this->scope === 'prefix') {
            $path = UrlHelper::path($url);
            $prefix = $this->prefix;
            if ($prefix === '/' ) {
                return true;
            }
            return $path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '?');
        }
        return true;
    }
}
