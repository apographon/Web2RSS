<?php

declare(strict_types=1);

final class RssWriter
{
    public function writeCategory(string $slug, array $meta, array $items, ?int $limit = null): string
    {
        $limit ??= (int) App::cfg('feed_item_limit', 30);
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }
        $base = App::baseUrl();
        $feedUrl = $base !== '' ? $base . '/feeds/' . rawurlencode($slug) . '.xml' : '';
        $title = (string) ($meta['title'] ?? $slug);
        $desc = (string) ($meta['description'] ?? '');
        $now = date(DATE_RSS);

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $xml->appendChild($rss);
        $channel = $xml->createElement('channel');
        $rss->appendChild($channel);

        $this->textEl($xml, $channel, 'title', $title);
        $this->textEl($xml, $channel, 'link', $feedUrl !== '' ? $feedUrl : 'https://localhost/');
        $this->textEl($xml, $channel, 'description', $desc);
        $this->textEl($xml, $channel, 'language', 'de');
        $this->textEl($xml, $channel, 'lastBuildDate', $now);
        if ($feedUrl !== '') {
            $atom = $xml->createElement('atom:link');
            $atom->setAttribute('href', $feedUrl);
            $atom->setAttribute('rel', 'self');
            $atom->setAttribute('type', 'application/rss+xml');
            $channel->appendChild($atom);
        }

        foreach ($items as $item) {
            $node = $xml->createElement('item');
            $this->textEl($xml, $node, 'title', (string) $item['title']);
            $this->textEl($xml, $node, 'link', (string) $item['url']);
            $guid = $xml->createElement('guid');
            $guid->setAttribute('isPermaLink', 'false');
            $guid->appendChild($xml->createTextNode((string) $item['guid']));
            $node->appendChild($guid);
            $this->textEl($xml, $node, 'pubDate', date(DATE_RSS, (int) $item['ts']));
            if (!empty($item['source_name'])) {
                $this->textEl($xml, $node, 'category', (string) $item['source_name']);
            }

            $html = $this->itemHtml($item);
            $descEl = $xml->createElement('description');
            $descEl->appendChild($xml->createCDATASection($html));
            $node->appendChild($descEl);
            $content = $xml->createElement('content:encoded');
            $content->appendChild($xml->createCDATASection($html));
            $node->appendChild($content);
            $channel->appendChild($node);
        }

        $path = App::path('feeds/' . $slug . '.xml');
        $xml->save($path);
        return $path;
    }

    private function textEl(DOMDocument $xml, DOMElement $parent, string $name, string $value): void
    {
        $el = $xml->createElement($name);
        $el->appendChild($xml->createTextNode($value));
        $parent->appendChild($el);
    }

    private function itemHtml(array $item): string
    {
        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = [];
        $source = trim((string) ($item['source_name'] ?? ''));
        $excerpt = trim((string) ($item['excerpt'] ?? ''));
        if ($source !== '' && $excerpt !== '') {
            $parts[] = '<p>' . $esc($source) . '<br>' . $esc($excerpt) . '</p>';
        } elseif ($source !== '') {
            $parts[] = '<p>' . $esc($source) . '</p>';
        } elseif ($excerpt !== '') {
            $parts[] = '<p>' . $esc($excerpt) . '</p>';
        }
        foreach ($item['images'] ?? [] as $img) {
            $src = $esc((string) $img);
            $parts[] = '<p><img src="' . $src . '" alt="" /></p>';
        }
        $url = $esc((string) $item['url']);
        $parts[] = '<p><a href="' . $url . '">Zur Seite</a></p>';
        return implode("\n", $parts);
    }
}
