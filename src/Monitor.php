<?php

declare(strict_types=1);

final class Monitor
{
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly Extractor $extractor = new Extractor(),
        private readonly RssWriter $rss = new RssWriter(),
    ) {
    }

    /**
     * @return array{sources:int,pages:int,items:int,errors:int,aborted:bool}
     */
    public function run(string $mode, RunReport $report): array
    {
        $dry = $mode === 'dry';
        App::ensureDirs();
        $catalog = JsonStore::read(App::path('data/sources.json'), []);
        $sources = $catalog['sources'] ?? [];
        $categories = $catalog['categories'] ?? [];
        $stats = ['sources' => 0, 'pages' => 0, 'items' => 0, 'errors' => 0, 'aborted' => false];

        $runnable = [];
        foreach ($sources as $source) {
            if (is_array($source) && !empty($source['url']) && !empty($source['category'])) {
                $runnable[] = $source;
            }
        }
        $report->setSourcesTotal(count($runnable));

        foreach ($runnable as $source) {
            if (RunReport::abortRequested()) {
                $stats['aborted'] = true;
                Logger::line('Abbruch angefordert, restliche Quellen werden übersprungen.');
                break;
            }
            $id = (string) ($source['id'] ?? md5((string) $source['url']));
            $name = (string) ($source['name'] ?? $id);
            $stats['sources']++;
            try {
                $result = $this->crawlSource($source, $dry, $report);
            } catch (Throwable $e) {
                Logger::line("Quelle {$id} Fehler (wird ignoriert): " . $e->getMessage());
                $report->addError($id, (string) $source['url'], $e->getMessage());
                $report->sourceDone($id, 'error');
                $stats['errors']++;
                continue;
            }
            $stats['pages'] += $result['pages'];
            $stats['errors'] += $result['errors'];
            $stats['items'] += count($result['items']);
            if ($result['aborted']) {
                $stats['aborted'] = true;
                if (!$dry) {
                    $this->pushItems((string) $source['category'], $result['items']);
                }
                break;
            }
            if (!$dry) {
                $this->pushItems((string) $source['category'], $result['items']);
            }
        }

        if (!$dry) {
            $this->dropUpdateNotices();
            foreach ($categories as $slug => $meta) {
                try {
                    $slug = (string) $slug;
                    $existing = JsonStore::read(App::path('data/items/' . $slug . '.json'), []);
                    if (!is_array($existing)) {
                        $existing = [];
                    }
                    $limit = (int) App::cfg('feed_item_limit', 30);
                    $this->rss->writeCategory($slug, is_array($meta) ? $meta : ['title' => $slug], $existing, $limit);
                } catch (Throwable $e) {
                    Logger::line('RSS-Schreibfehler für ' . $slug . ' (wird ignoriert): ' . $e->getMessage());
                    $report->addError('rss', $slug, $e->getMessage());
                    $stats['errors']++;
                }
            }
        }

        $report->finish($stats['aborted'] ? 'aborted' : 'done');
        return $stats;
    }

    public static function reset(): void
    {
        App::ensureDirs();
        foreach (glob(App::path('data/state/*.json')) ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(App::path('data/items/*.json')) ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob(App::path('feeds/*.xml')) ?: [] as $file) {
            @unlink($file);
        }
        $catalog = JsonStore::read(App::path('data/sources.json'), []);
        $categories = $catalog['categories'] ?? [];
        $writer = new RssWriter();
        foreach ($categories as $slug => $meta) {
            $writer->writeCategory((string) $slug, is_array($meta) ? $meta : ['title' => $slug], []);
        }
        Logger::line('Reset: State, Feed-History und RSS gelöscht. Der nächste Crawl merkt sich die Listen, ohne sie zu veröffentlichen.');
        RunReport::resetEvent();
    }

    private function crawlSource(array $source, bool $dry, RunReport $report): array
    {
        $id = (string) ($source['id'] ?? md5((string) $source['url']));
        $name = (string) ($source['name'] ?? $id);
        $start = UrlHelper::normalize((string) $source['url']);
        $maxListing = (int) App::cfg('max_listing_pages', 8);
        $maxPages = (int) App::cfg('max_pages_per_source', 400);
        $delay = (int) App::cfg('request_delay_ms', 400);
        $statePath = App::path('data/state/' . $id . '.json');
        $state = JsonStore::read($statePath, ['pages' => []]);
        if (!isset($state['pages']) || !is_array($state['pages'])) {
            $state['pages'] = [];
        }
        $bootstrapped = $state['pages'] !== [];
        $lastRun = null;
        if (!empty($state['last_run'])) {
            $parsed = strtotime((string) $state['last_run']);
            if ($parsed !== false) {
                $lastRun = $parsed;
            }
        }

        $report->sourceStart($id, $name, $start);
        Logger::line("Quelle {$id} ({$name}) startet: {$start} [liste]" . ($dry ? ' [dry-run]' : ''));

        $queue = [$start];
        $queued = [$start => true];
        /** @var array<string, bool> $posts url => discovered on the first listing page */
        $posts = [];
        $pages = 0;
        $errors = 0;
        $items = [];
        $remembered = 0;
        $aborted = false;
        $followPagination = true;

        while ($queue !== [] && $pages < $maxListing) {
            if (RunReport::abortRequested()) {
                $aborted = true;
                Logger::line("Quelle {$id}: Abbruch nach {$pages} Seiten.");
                break;
            }
            $url = array_shift($queue);
            $onFirst = $url === $start;
            $fetched = $this->fetchPage($url, $id, $pages, $delay, $report);
            $pages++;
            if ($fetched === null) {
                $errors++;
                continue;
            }
            $child = [];
            $loose = [];
            $nextPages = [];
            foreach ($fetched['links'] as $link) {
                if (ListingRules::isPagination($link, $start)) {
                    $nextPages[] = $link;
                    continue;
                }
                if (ListingRules::isPost($link, $start)) {
                    $child[] = $link;
                } elseif (ListingRules::isLoosePost($link, $start)) {
                    $loose[] = $link;
                }
            }
            $found = $child !== [] ? $child : $loose;
            $pageHasFresh = false;
            foreach ($found as $postUrl) {
                $posts[$postUrl] = $onFirst || !empty($posts[$postUrl]);
                if (!isset($state['pages'][$postUrl])) {
                    $pageHasFresh = true;
                }
            }
            if ($bootstrapped && !$pageHasFresh) {
                $followPagination = false;
            }
            if ($followPagination) {
                foreach ($nextPages as $next) {
                    if (!isset($queued[$next])) {
                        $queued[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
        }

        foreach ($posts as $postUrl => $onFirst) {
            if ($aborted || $pages >= $maxPages) {
                break;
            }
            if (RunReport::abortRequested()) {
                $aborted = true;
                Logger::line("Quelle {$id}: Abbruch nach {$pages} Seiten.");
                break;
            }
            if (isset($state['pages'][$postUrl])) {
                $state['pages'][$postUrl]['last_seen'] = date('c');
                $report->page($id, 'unchanged');
                continue;
            }
            $fetched = $this->fetchPage($postUrl, $id, $pages, $delay, $report);
            $pages++;
            if ($fetched === null) {
                $errors++;
                continue;
            }
            $pageUrl = $fetched['url'] !== '' ? $fetched['url'] : $postUrl;
            if (UrlHelper::host($pageUrl) !== UrlHelper::host($start)) {
                $pageUrl = $postUrl;
            }
            $known = isset($state['pages'][$pageUrl]) || isset($state['pages'][$postUrl]);
            $published = $fetched['published'];
            $emit = ListingRules::shouldPublish($bootstrapped, $known, $published, $lastRun, $onFirst);
            $record = [
                'title' => $fetched['title'],
                'published' => $published,
                'last_seen' => date('c'),
            ];
            $state['pages'][$pageUrl] = $record;
            if ($pageUrl !== $postUrl) {
                $state['pages'][$postUrl] = $record;
            }
            if (!$emit) {
                $remembered++;
                $report->page($id, 'unchanged');
                continue;
            }
            $images = array_slice($fetched['images'], 0, (int) App::cfg('max_images_per_item', 6));
            $excerpt = mb_substr((string) $fetched['description'], 0, (int) App::cfg('excerpt_length', 420));
            $item = [
                'guid' => $pageUrl,
                'url' => $pageUrl,
                'title' => (string) $fetched['title'],
                'excerpt' => $excerpt,
                'images' => $images,
                'source_id' => $id,
                'source_name' => $name,
                'kind' => 'new',
                'ts' => is_int($published) ? $published : time(),
            ];
            $items[] = $item;
            $report->page($id, 'new');
            $report->addItem($item);
            Logger::line("  neu: {$item['title']} ({$pageUrl})");
        }

        if (!$dry) {
            try {
                $state['bootstrapped'] = true;
                $state['last_run'] = date('c');
                $state['pages_fetched'] = $pages;
                JsonStore::write($statePath, $state);
            } catch (Throwable $e) {
                Logger::line('State-Schreibfehler am Ende (wird ignoriert): ' . $e->getMessage());
                $report->addError($id, $start, 'State: ' . $e->getMessage());
            }
        }

        $report->sourceDone($id, $aborted ? 'aborted' : 'done');
        if (!$bootstrapped) {
            Logger::line("Quelle {$id}: erster Lauf, " . count($posts) . " Einträge gemerkt, nichts veröffentlicht.");
        } elseif ($remembered > 0) {
            Logger::line("Quelle {$id}: {$remembered} Einträge nur gemerkt.");
        }
        Logger::line("Quelle {$id} fertig: {$pages} Seiten, " . count($items) . " Items, {$errors} Fehler");
        return ['pages' => $pages, 'errors' => $errors, 'items' => $items, 'aborted' => $aborted];
    }

    /**
     * @return array{url:string,title:string,description:string,images:list<string>,links:list<string>,published:?int}|null
     */
    private function fetchPage(string $url, string $sourceId, int $pagesSoFar, int $delay, RunReport $report): ?array
    {
        $report->setCurrentUrl($url);
        if ($pagesSoFar > 0 && $delay > 0) {
            usleep($delay * 1000);
        }
        try {
            $response = $this->http->get($url);
            if (!$response['ok']) {
                Logger::line("  FEHLER {$url}: {$response['error']}");
                $report->addError($sourceId, $url, (string) $response['error']);
                return null;
            }
            $final = UrlHelper::normalize((string) $response['url']) ?: $url;
            $page = $this->extractor->extract((string) $response['body'], $final);
            $published = $page['published'] ?? null;
            return [
                'url' => (string) ($page['url'] ?? $final),
                'title' => (string) ($page['title'] ?? ''),
                'description' => (string) ($page['description'] ?? ''),
                'images' => is_array($page['images'] ?? null) ? $page['images'] : [],
                'links' => is_array($page['links'] ?? null) ? $page['links'] : [],
                'published' => is_int($published) ? $published : null,
            ];
        } catch (Throwable $e) {
            Logger::line("  FEHLER {$url}: " . $e->getMessage());
            $report->addError($sourceId, $url, $e->getMessage());
            return null;
        }
    }

    private function dropUpdateNotices(): void
    {
        foreach (glob(App::path('data/items/*.json')) ?: [] as $file) {
            try {
                $existing = JsonStore::read($file, []);
                if (!is_array($existing) || $existing === []) {
                    continue;
                }
                $kept = [];
                $removed = 0;
                foreach ($existing as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (str_starts_with((string) ($item['title'] ?? ''), 'Aktualisiert:')) {
                        $removed++;
                        continue;
                    }
                    $kept[] = $item;
                }
                if ($removed === 0) {
                    continue;
                }
                JsonStore::write($file, $kept);
                Logger::line("{$removed} „Aktualisiert“-Meldungen aus " . basename($file, '.json') . " genommen.");
            } catch (Throwable $e) {
                Logger::line('Feed-Bereinigung übersprungen: ' . $e->getMessage());
            }
        }
    }

    private function pushItems(string $category, array $items, bool $keepAll = false): void
    {
        if ($items === []) {
            return;
        }
        try {
            $path = App::path('data/items/' . $category . '.json');
            $existing = JsonStore::read($path, []);
            if (!is_array($existing)) {
                $existing = [];
            }
            $merged = $this->dedupeItems(array_merge($items, $existing));
            usort($merged, static function (array $a, array $b): int {
                return ((int) ($b['ts'] ?? 0)) <=> ((int) ($a['ts'] ?? 0));
            });
            if (!$keepAll) {
                $keep = max((int) App::cfg('feed_item_limit', 30) * 2, 60);
                $merged = array_slice($merged, 0, $keep);
            }
            JsonStore::write($path, $merged);
        } catch (Throwable $e) {
            Logger::line('Item-Schreibfehler (wird ignoriert): ' . $e->getMessage());
        }
    }

    private function dedupeItems(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $guid = (string) ($item['guid'] ?? '');
            if ($guid === '' || isset($seen[$guid])) {
                continue;
            }
            $seen[$guid] = true;
            $out[] = $item;
        }
        return $out;
    }
}
