<?php

declare(strict_types=1);

final class LogAnalyzer
{
    /**
     * @return array{
     *   mode:string,
     *   started:bool,
     *   summary:?string,
     *   sources:array<string,array{pages:int,items:int,errors:int,error_lines:list<string>}>,
     *   connect_tunnel:int,
     *   error_lines:list<string>,
     *   clean:bool,
     *   issues:list<string>
     * }
     */
    public static function parse(string $log): array
    {
        $lastStart = 0;
        if (preg_match_all('/^\[.*?\] === (Crawl|Dry-Run) start ===\s*$/m', $log, $starts, PREG_OFFSET_CAPTURE)) {
            $last = end($starts[0]);
            $lastStart = (int) $last[1];
        }
        $chunk = substr($log, $lastStart);

        $mode = 'unknown';
        if (preg_match('/=== (Crawl|Dry-Run) start ===/', $chunk, $m)) {
            $mode = $m[1] === 'Dry-Run' ? 'dry' : 'run';
        }

        $sources = [];
        if (preg_match_all('/Quelle ([a-z0-9\-]+) \((.*?)\) startet:/', $chunk, $sm, PREG_SET_ORDER)) {
            foreach ($sm as $row) {
                $sources[$row[1]] = [
                    'name' => $row[2],
                    'pages' => 0,
                    'items' => 0,
                    'errors' => 0,
                    'finished' => false,
                    'error_lines' => [],
                ];
            }
        }
        if (preg_match_all('/Quelle ([a-z0-9\-]+) fertig: (\d+) Seiten, (\d+) Items, (\d+) Fehler/', $chunk, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $row) {
                $id = $row[1];
                if (!isset($sources[$id])) {
                    $sources[$id] = ['name' => $id, 'pages' => 0, 'items' => 0, 'errors' => 0, 'finished' => false, 'error_lines' => []];
                }
                $sources[$id]['pages'] = (int) $row[2];
                $sources[$id]['items'] = (int) $row[3];
                $sources[$id]['errors'] = (int) $row[4];
                $sources[$id]['finished'] = true;
            }
        }

        $errorLines = [];
        $connect = 0;
        if (preg_match_all('/^\s*(?:\[.*?\]\s*)?FEHLER .+$/m', $chunk, $em)) {
            foreach ($em[0] as $line) {
                $line = trim($line);
                $errorLines[] = $line;
                if (stripos($line, 'CONNECT tunnel') !== false) {
                    $connect++;
                }
                if (preg_match('/Quelle ([a-z0-9\-]+)/', $line, $qm) && isset($sources[$qm[1]])) {
                    $sources[$qm[1]]['error_lines'][] = $line;
                }
            }
        }
        // Attach FEHLER lines to the most recently started source by scanning sequentially
        $current = null;
        foreach (preg_split("/\n/", $chunk) ?: [] as $line) {
            if (preg_match('/Quelle ([a-z0-9\-]+) \(.*?\) startet:/', $line, $m)) {
                $current = $m[1];
            }
            if ($current !== null && preg_match('/FEHLER /', $line)) {
                if (isset($sources[$current]) && !in_array(trim($line), $sources[$current]['error_lines'], true)) {
                    $sources[$current]['error_lines'][] = trim($line);
                }
            }
        }

        $summary = null;
        if (preg_match('/(?:Crawl|Dry-Run): \d+ Quellen.+$/m', $chunk, $sum)) {
            $summary = trim($sum[0]);
        }

        $issues = [];
        if ($connect > 0) {
            $issues[] = "CONNECT-Tunnel-Fehler: {$connect} (Sandbox-Proxy?)";
        }
        foreach ($sources as $id => $src) {
            if (!$src['finished']) {
                $issues[] = "Quelle {$id} ohne Abschlusszeile";
            }
            if ($src['errors'] > 0) {
                $issues[] = "Quelle {$id}: {$src['errors']} Fehler";
            }
        }
        if ($sources === []) {
            $issues[] = 'Keine Quellen im Log gefunden';
        }

        return [
            'mode' => $mode,
            'started' => str_contains($chunk, 'start ==='),
            'summary' => $summary,
            'sources' => $sources,
            'connect_tunnel' => $connect,
            'error_lines' => $errorLines,
            'clean' => $issues === [],
            'issues' => $issues,
        ];
    }

    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            return self::parse('');
        }
        $raw = file_get_contents($path);
        return self::parse($raw === false ? '' : $raw);
    }

    public static function report(array $analysis): string
    {
        $lines = [];
        $lines[] = 'Log-Analyse (' . $analysis['mode'] . ')';
        if ($analysis['summary']) {
            $lines[] = $analysis['summary'];
        }
        foreach ($analysis['sources'] as $id => $src) {
            $state = $src['finished'] ? 'fertig' : 'offen';
            $lines[] = sprintf(
                '  %s: %s, %d Seiten, %d Items, %d Fehler',
                $id,
                $state,
                $src['pages'],
                $src['items'],
                $src['errors']
            );
            foreach ($src['error_lines'] as $err) {
                $lines[] = '    ' . $err;
            }
        }
        if ($analysis['clean']) {
            $lines[] = 'Ergebnis: sauber';
        } else {
            $lines[] = 'Ergebnis: PROBLEME';
            foreach ($analysis['issues'] as $issue) {
                $lines[] = '  - ' . $issue;
            }
        }
        return implode("\n", $lines) . "\n";
    }
}
