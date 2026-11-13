<?php

declare(strict_types=1);

final class Extractor
{
    /**
     * Bump when the hashed content changes. Known pages are rebaselined
     * silently so a tighter hash does not republish the whole archive.
     */
    public const HASH_VERSION = 2;

    /** Class/id tokens that are teasers, chrome, or consent — not the article. */
    private const NOISE_TOKEN = '/(?:^|[\s_-])(?:related|recommended|similar|more-posts|recent-posts|also-read|further-reading|newsletter|cookie|consent|comments?|breadcrumb|pagination|sidebar)(?:$|[\s_-])/i';

    /** @var list<string> */
    private const SKIP_IMAGE_NEEDLES = [
        'logo', 'icon', 'sprite', 'emoji', 'pixel', 'tracking', '1x1',
        'badge', 'spinner', 'placeholder', 'gravatar', 'smilies',
        'wp-includes', 'favicon',
    ];

    public function extract(string $html, string $fetchedUrl): array
    {
        $dom = $this->load($html);
        $xpath = new DOMXPath($dom);

        $canonical = $this->meta($xpath, 'link[@rel="canonical"]/@href')
            ?: $this->meta($xpath, 'meta[@property="og:url"]/@content')
            ?: $fetchedUrl;
        $canonical = UrlHelper::resolve($fetchedUrl, $canonical) ?: UrlHelper::normalize($fetchedUrl);

        $title = $this->firstNonEmpty([
            $this->meta($xpath, 'meta[@property="og:title"]/@content'),
            $this->meta($xpath, 'meta[@name="twitter:title"]/@content'),
            $this->firstText($xpath, '//h1'),
            $this->firstText($xpath, '//title'),
        ]);
        $title = $this->cleanText($title);

        $description = $this->firstNonEmpty([
            $this->meta($xpath, 'meta[@property="og:description"]/@content'),
            $this->meta($xpath, 'meta[@name="description"]/@content'),
            $this->meta($xpath, 'meta[@name="twitter:description"]/@content'),
        ]);

        $base = $canonical ?: $fetchedUrl;
        $clean = $this->contentDocument($dom);
        $links = $this->links($dom, $base);
        $images = $this->images($clean, new DOMXPath($clean), $base);
        $text = $this->mainText($clean);
        $published = $this->publishedAt($xpath, $clean, $base);
        if ($description === '') {
            $description = mb_substr($text, 0, (int) App::cfg('excerpt_length', 420));
        }

        return [
            'url' => $canonical ?: UrlHelper::normalize($fetchedUrl),
            'title' => $title !== '' ? $title : ($canonical ?: $fetchedUrl),
            'description' => $this->cleanText($description),
            'text' => $text,
            'images' => $images,
            'links' => $links,
            'published' => $published,
        ];
    }

    public function hash(array $page): string
    {
        $limit = (int) App::cfg('max_text_for_hash', 8000);
        $ids = [];
        foreach ($page['images'] as $img) {
            $ids[] = UrlHelper::imageIdentity($img);
        }
        sort($ids);
        $payload = mb_strtolower($page['title'] ?? '') . "\n"
            . mb_substr((string) ($page['text'] ?? ''), 0, $limit) . "\n"
            . implode("\n", $ids);
        return hash('sha256', $payload);
    }

    private function load(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8">' . $html;
        $dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $dom;
    }

    private function meta(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query('//' . $query);
        if ($node === false || $node->length === 0) {
            return '';
        }
        return trim($node->item(0)?->nodeValue ?? '');
    }

    private function firstText(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query);
        if ($node === false || $node->length === 0) {
            return '';
        }
        return trim($node->item(0)?->textContent ?? '');
    }

    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    private function links(DOMDocument $dom, string $base): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            $abs = UrlHelper::resolve($base, $href);
            if ($abs !== '') {
                $out[$abs] = true;
            }
        }
        return array_keys($out);
    }

    private function images(DOMDocument $dom, DOMXPath $xpath, string $base): array
    {
        $found = [];
        $og = $this->meta($xpath, 'meta[@property="og:image"]/@content');
        if ($og !== '') {
            $abs = UrlHelper::resolve($base, $og);
            if ($abs !== '') {
                $found[$abs] = true;
            }
        }
        $root = $this->contentRoot($dom);
        $imgs = (new DOMXPath($dom))->query('.//img', $root);
        if ($imgs === false) {
            $imgs = [];
        }
        foreach ($imgs as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }
            foreach (['src', 'data-src', 'data-original', 'data-lazy-src', 'data-full-url'] as $attr) {
                $val = $img->getAttribute($attr);
                foreach ($this->urlsFromSrc($val) as $raw) {
                    $abs = UrlHelper::resolve($base, $raw);
                    if ($abs !== '' && $this->isContentImage($abs, $img)) {
                        $found[$abs] = true;
                    }
                }
            }
            $srcset = $img->getAttribute('srcset') ?: $img->getAttribute('data-srcset');
            $best = $this->largestFromSrcset($srcset);
            if ($best !== '') {
                $abs = UrlHelper::resolve($base, $best);
                if ($abs !== '' && $this->isContentImage($abs, $img)) {
                    $found[$abs] = true;
                }
            }
        }
        $nodes = (new DOMXPath($dom))->query('.//*', $root);
        if ($nodes === false) {
            $nodes = [];
        }
        foreach ($nodes as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $dataSrc = $el->getAttribute('data-src');
            if ($dataSrc !== '' && preg_match('#\.(jpe?g|png|webp|gif|avif)(\?|$)#i', $dataSrc)) {
                $abs = UrlHelper::resolve($base, $dataSrc);
                if ($abs !== '' && $this->isContentImage($abs, $el)) {
                    $found[$abs] = true;
                }
            }
        }
        return $this->uniqueBestImages(array_keys($found));
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function uniqueBestImages(array $urls): array
    {
        $best = [];
        foreach ($urls as $url) {
            $id = UrlHelper::imageIdentity($url);
            $score = UrlHelper::imageScore($url);
            if (!isset($best[$id]) || $score > $best[$id]['score']) {
                $best[$id] = ['url' => $url, 'score' => $score];
            }
        }
        $out = [];
        foreach ($best as $row) {
            $out[] = $row['url'];
        }
        return $out;
    }

    private function urlsFromSrc(string $val): array
    {
        $val = trim($val);
        if ($val === '' || str_starts_with($val, 'data:')) {
            return [];
        }
        return [$val];
    }

    private function largestFromSrcset(string $srcset): string
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return '';
        }
        $best = '';
        $bestW = -1;
        foreach (preg_split('/\s*,\s*/', $srcset) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^(\S+)(?:\s+(\d+)w)?/', $part, $m)) {
                continue;
            }
            $w = isset($m[2]) ? (int) $m[2] : 0;
            if ($w >= $bestW) {
                $bestW = $w;
                $best = $m[1];
            }
        }
        return $best;
    }

    private function isContentImage(string $url, DOMElement $el): bool
    {
        $lower = strtolower($url);
        if (str_starts_with($lower, 'data:')) {
            return false;
        }
        foreach (self::SKIP_IMAGE_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }
        $w = (int) $el->getAttribute('width');
        $h = (int) $el->getAttribute('height');
        if ($w > 0 && $h > 0 && $w < 80 && $h < 80) {
            return false;
        }
        $ext = UrlHelper::extension($url);
        if ($ext !== '' && !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', ''], true)) {
            return false;
        }
        return true;
    }

    private function publishedAt(DOMXPath $xpath, DOMDocument $clean, string $url): ?int
    {
        $raw = $this->firstNonEmpty([
            $this->meta($xpath, 'meta[@property="article:published_time"]/@content'),
            $this->meta($xpath, 'meta[@property="og:published_time"]/@content'),
            $this->meta($xpath, 'meta[@itemprop="datePublished"]/@content'),
        ]);
        $fromMeta = $this->parseTime($raw);
        if ($fromMeta !== null) {
            return $fromMeta;
        }
        $time = $this->meta(new DOMXPath($clean), 'article//time[@datetime]/@datetime')
            ?: $this->meta(new DOMXPath($clean), 'time[@datetime]/@datetime');
        $fromTime = $this->parseTime($time);
        if ($fromTime !== null) {
            return $fromTime;
        }
        if (preg_match('#/(20\d{2})/(\d{2})(?:/(\d{2}))?(?:/|$)#', $url, $m) === 1) {
            $day = (($m[3] ?? '') !== '') ? $m[3] : '01';
            return $this->parseTime(sprintf('%s-%s-%s', $m[1], $m[2], $day));
        }
        return null;
    }

    private function parseTime(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false || $ts < strtotime('1995-01-01') || $ts > time() + 3 * 86400) {
            return null;
        }
        return $ts;
    }

    private function contentDocument(DOMDocument $dom): DOMDocument
    {
        $clone = $dom->cloneNode(true);
        if (!$clone instanceof DOMDocument) {
            return $dom;
        }
        $this->removeTags($clone, ['script', 'style', 'noscript', 'svg', 'nav', 'footer', 'form', 'iframe']);
        $this->removeNoise($clone);
        $this->removeNestedArticles($clone);
        return $clone;
    }

    /** @param list<string> $tags */
    private function removeTags(DOMDocument $dom, array $tags): void
    {
        foreach ($tags as $tag) {
            $remove = [];
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $remove[] = $node;
            }
            foreach ($remove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function removeNoise(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@class or @id]');
        if ($nodes === false) {
            return;
        }
        $remove = [];
        foreach ($nodes as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $blob = $el->getAttribute('class') . ' ' . $el->getAttribute('id');
            if (preg_match(self::NOISE_TOKEN, $blob) === 1) {
                $remove[] = $el;
            }
        }
        foreach ($remove as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    private function removeNestedArticles(DOMDocument $dom): void
    {
        $articles = [];
        foreach ($dom->getElementsByTagName('article') as $article) {
            $articles[] = $article;
        }
        foreach ($articles as $article) {
            if (!$article instanceof DOMElement) {
                continue;
            }
            $parent = $article->parentNode;
            $nested = false;
            while ($parent !== null) {
                if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'article') {
                    $nested = true;
                    break;
                }
                $parent = $parent->parentNode;
            }
            if ($nested) {
                $article->parentNode?->removeChild($article);
            }
        }
    }

    private function contentRoot(DOMDocument $dom): DOMNode
    {
        return $dom->getElementsByTagName('article')->item(0)
            ?? $dom->getElementsByTagName('main')->item(0)
            ?? $dom->getElementsByTagName('body')->item(0)
            ?? $dom;
    }

    private function mainText(DOMDocument $dom): string
    {
        $text = $this->contentRoot($dom)->textContent ?? '';
        return $this->cleanText($text);
    }

    private function cleanText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252') ?: $text;
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\x{00A0}/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
