<?php

declare(strict_types=1);

final class RunReport
{
    public const HISTORY_LIMIT = 5;
    public const LOG_LIMIT = 100;

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function start(string $mode): self
    {
        self::clearAbort();
        $id = date('Ymd-His');
        $report = new self([
            'id' => $id,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => date('c'),
            'finished_at' => null,
            'current_source' => '',
            'current_source_name' => '',
            'current_url' => '',
            'sources_done' => 0,
            'sources_total' => 0,
            'pages' => 0,
            'new' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'log' => [],
            'sources' => [],
            'error_list' => [],
            'items' => [],
        ]);
        $report->flush();
        return $report;
    }

    public static function resetEvent(): self
    {
        $id = date('Ymd-His');
        $report = new self([
            'id' => $id,
            'mode' => 'reset',
            'status' => 'done',
            'started_at' => date('c'),
            'finished_at' => date('c'),
            'current_source' => '',
            'current_source_name' => '',
            'current_url' => '',
            'sources_done' => 0,
            'sources_total' => 0,
            'pages' => 0,
            'new' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'log' => ['[' . date('Y-m-d H:i:s') . '] Reset: State, Feed-History und RSS gelöscht.'],
            'sources' => [],
            'error_list' => [],
            'items' => [],
        ]);
        $report->flush();
        $report->archive();
        return $report;
    }

    public static function loadProgress(): ?array
    {
        $data = JsonStore::read(App::path('data/progress.json'), null);
        return is_array($data) ? $data : null;
    }

    public static function loadRun(string $id): ?array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}$/', $id)) {
            return null;
        }
        $data = JsonStore::read(App::path('data/runs/' . $id . '.json'), null);
        return is_array($data) ? $data : null;
    }

    public static function history(): array
    {
        $index = JsonStore::read(App::path('data/runs/index.json'), []);
        return is_array($index) ? $index : [];
    }

    public static function isRunning(): bool
    {
        $progress = self::loadProgress();
        return is_array($progress) && ($progress['status'] ?? '') === 'running';
    }

    public static function requestAbort(): void
    {
        file_put_contents(App::path('data/abort.flag'), date('c'), LOCK_EX);
    }

    public static function abortRequested(): bool
    {
        return is_file(App::path('data/abort.flag'));
    }

    public static function clearAbort(): void
    {
        $path = App::path('data/abort.flag');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function setSourcesTotal(int $total): void
    {
        $this->data['sources_total'] = $total;
        $this->flush();
    }

    public function sourceStart(string $id, string $name, string $url): void
    {
        $this->data['current_source'] = $id;
        $this->data['current_source_name'] = $name;
        $this->data['current_url'] = $url;
        $this->data['sources'][$id] = [
            'id' => $id,
            'name' => $name,
            'url' => $url,
            'status' => 'running',
            'pages' => 0,
            'new' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'error_count' => 0,
        ];
        $this->flush();
    }

    public function sourceDone(string $id, string $status = 'done'): void
    {
        if (isset($this->data['sources'][$id])) {
            $this->data['sources'][$id]['status'] = $status;
        }
        $this->data['sources_done'] = (int) $this->data['sources_done'] + 1;
        $this->data['current_url'] = '';
        $this->flush();
    }

    public function setCurrentUrl(string $url): void
    {
        $this->data['current_url'] = $url;
        $this->flush();
    }

    public function page(string $sourceId, string $kind): void
    {
        $this->data['pages']++;
        if (isset($this->data['sources'][$sourceId])) {
            $this->data['sources'][$sourceId]['pages']++;
            if (isset($this->data['sources'][$sourceId][$kind])) {
                $this->data['sources'][$sourceId][$kind]++;
            }
        }
        if (isset($this->data[$kind])) {
            $this->data[$kind]++;
        }
        $this->flush();
    }

    public function addItem(array $item): void
    {
        $this->data['items'][] = [
            'kind' => $item['kind'] ?? '',
            'title' => $item['title'] ?? '',
            'url' => $item['url'] ?? '',
            'source_name' => $item['source_name'] ?? '',
        ];
        if (count($this->data['items']) > 250) {
            $this->data['items'] = array_slice($this->data['items'], -250);
        }
        $this->flush();
    }

    public function addError(string $sourceId, string $url, string $message): void
    {
        $this->data['errors']++;
        if (isset($this->data['sources'][$sourceId])) {
            $this->data['sources'][$sourceId]['error_count']++;
        }
        $this->data['error_list'][] = [
            'source' => $sourceId,
            'url' => $url,
            'message' => $message,
            'at' => date('c'),
        ];
        if (count($this->data['error_list']) > 400) {
            $this->data['error_list'] = array_slice($this->data['error_list'], -400);
        }
        $this->flush();
    }

    public function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        $this->data['log'][] = $line;
        if (count($this->data['log']) > self::LOG_LIMIT) {
            $this->data['log'] = array_slice($this->data['log'], -self::LOG_LIMIT);
        }
        $this->flush();
    }

    public function finish(string $status): void
    {
        $this->data['status'] = $status;
        $this->data['finished_at'] = date('c');
        $this->data['current_url'] = '';
        $this->flush();
        $this->archive();
        self::clearAbort();
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function toArray(): array
    {
        $out = $this->data;
        $out['sources'] = array_values($this->data['sources']);
        return $out;
    }

    public function flush(): void
    {
        try {
            $payload = $this->data;
            $payload['sources'] = array_values($this->data['sources']);
            JsonStore::write(App::path('data/progress.json'), $payload);
        } catch (Throwable) {
            // Statusdatei darf den Crawl nicht abbrechen.
        }
    }

    private function archive(): void
    {
        App::ensureDirs();
        $payload = $this->toArray();
        JsonStore::write(App::path('data/runs/' . $this->id() . '.json'), $payload);
        $index = self::history();
        array_unshift($index, [
            'id' => $this->id(),
            'mode' => $payload['mode'],
            'status' => $payload['status'],
            'started_at' => $payload['started_at'],
            'finished_at' => $payload['finished_at'],
            'pages' => $payload['pages'],
            'new' => $payload['new'],
            'changed' => $payload['changed'],
            'errors' => $payload['errors'],
        ]);
        $keep = [];
        $ids = [];
        foreach ($index as $row) {
            $rid = (string) ($row['id'] ?? '');
            if ($rid === '' || isset($ids[$rid])) {
                continue;
            }
            $ids[$rid] = true;
            $keep[] = $row;
            if (count($keep) >= self::HISTORY_LIMIT) {
                break;
            }
        }
        JsonStore::write(App::path('data/runs/index.json'), $keep);
        foreach (glob(App::path('data/runs/*.json')) ?: [] as $file) {
            $base = basename($file);
            if ($base === 'index.json') {
                continue;
            }
            $fid = preg_replace('/\.json$/', '', $base);
            if (!isset($ids[$fid])) {
                @unlink($file);
            }
        }
    }
}
