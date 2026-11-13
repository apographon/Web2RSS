<?php

declare(strict_types=1);

require __DIR__ . '/src/App.php';

App::init();
App::ensureDirs();

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function modeLabel(string $mode): string
{
    return match ($mode) {
        'dry' => 'Dry-Run',
        'reset' => 'Reset',
        'run' => 'Crawl',
        default => $mode,
    };
}

function statusLabel(string $status): string
{
    return match ($status) {
        'running' => 'läuft',
        'done' => 'fertig',
        'aborted' => 'abgebrochen',
        'error' => 'Fehler',
        default => $status,
    };
}

$catalog = JsonStore::read(App::path('data/sources.json'), []);
$categories = $catalog['categories'] ?? [];
$sources = $catalog['sources'] ?? [];
$base = App::baseUrl();
$progress = RunReport::loadProgress();
$history = RunReport::history();
$running = RunReport::isRunning();

$viewId = (string) ($_GET['run'] ?? '');
$view = $viewId !== '' ? RunReport::loadRun($viewId) : null;
if ($view === null) {
    $view = $progress;
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'running' => $running,
        'progress' => $progress,
        'history' => $history,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$busy = isset($_GET['busy']);
$resetDone = isset($_GET['reset']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Website-RSS-Monitor</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: system-ui, sans-serif; max-width: 58rem; margin: 1.5rem auto; padding: 0 1rem 3rem; line-height: 1.45; background: #f7f7f5; color: #1a1a1a; }
        h1 { font-size: 1.5rem; margin-bottom: .25rem; }
        h2 { font-size: 1.15rem; margin-top: 1.8rem; }
        .muted { color: #666; }
        .row { display: flex; flex-wrap: wrap; gap: .5rem .75rem; align-items: center; margin: .8rem 0; }
        button, .btn { font: inherit; padding: .4rem .75rem; cursor: pointer; border-radius: .35rem; border: 1px solid #888; background: #f4f4f4; color: inherit; text-decoration: none; display: inline-block; }
        button.primary { background: #1f4b99; color: #fff; border-color: #1f4b99; }
        button.danger { background: #8b1e1e; color: #fff; border-color: #8b1e1e; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .banner { padding: .6rem .8rem; border-radius: .4rem; background: #eee; margin: .8rem 0; }
        .banner.warn { background: #f6e7c1; }
        .banner.ok { background: #dcefdc; }
        .kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: .5rem; }
        .kpi { border: 1px solid #ccc; border-radius: .4rem; padding: .5rem .6rem; }
        .kpi b { display: block; font-size: 1.2rem; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        th, td { text-align: left; vertical-align: top; padding: .35rem .4rem; border-bottom: 1px solid #ddd; }
        code, a { word-break: break-all; }
        pre.log { background: #111; color: #ddd; padding: .75rem; border-radius: .4rem; overflow: auto; max-height: 18rem; font-size: .8rem; }
        .reset-box { border: 1px solid #c9a; padding: .75rem; border-radius: .4rem; }
        .feed-box { border: 1px solid #bcc; background: #fff; padding: .75rem .9rem; border-radius: .4rem; margin: .8rem 0 1.1rem; }
        .feed-box a.feed-url { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .92rem; }
        .pill { display: inline-block; font-size: .75rem; padding: .1rem .4rem; border-radius: 1rem; background: #e4e4e4; }
        .pill.dry { background: #d7e6f7; }
        .pill.reset { background: #f7d7d7; }
        .pill.running { background: #ffe08a; }
        .pill.aborted { background: #f5c16c; }
        .pill.error { background: #f5b0b0; }
        ul.plain { padding-left: 1.1rem; }
    </style>
</head>
<body>
    <h1>Website-RSS-Monitor</h1>
    <p class="muted">Status, letzter Lauf, Dry-Run und Reset.</p>
    <p><a href="dayone-wayback/">Day One · Wayback-Links</a> — Fortschritt der Twitter-Link-Auflösung</p>

    <div class="feed-box">
        <strong>RSS-Feeds für Fever</strong>
        <?php if ($categories === [] || $categories === null): ?>
            <p class="muted">Keine Kategorien in <code>data/sources.json</code>.</p>
        <?php else: ?>
            <ul class="plain">
                <?php foreach ($categories as $slug => $meta): ?>
                    <?php
                    $href = ($base !== '' ? $base : '.') . '/feeds/' . rawurlencode((string) $slug) . '.xml';
                    $hrefFever = preg_replace('#^https://#i', 'http://', $href);
                    ?>
                    <li>
                        <?= h((string) ($meta['title'] ?? $slug)) ?>
                        <div><a class="feed-url" href="<?= h($hrefFever) ?>"><?= h($hrefFever) ?></a></div>
                        <?php if ($hrefFever !== $href): ?>
                            <div class="muted">HTTPS erst nach Let’s Encrypt: <code><?= h($href) ?></code></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($busy): ?>
        <div class="banner warn">Es läuft bereits ein Crawl. Abbrechen geht über den Button unten.</div>
    <?php endif; ?>
    <?php if ($resetDone): ?>
        <div class="banner ok">Reset ausgeführt. Der nächste Crawl merkt sich die Listen, ohne sie zu veröffentlichen.</div>
    <?php endif; ?>

    <iframe name="jobframe" hidden title="Hintergrund-Job"></iframe>
    <div class="row">
        <form id="form-run" method="post" action="cron.php" target="jobframe">
            <input type="hidden" name="mode" value="run">
            <button class="primary" type="submit" <?= $running ? 'disabled' : '' ?>>Crawl starten</button>
        </form>
        <form id="form-dry" method="post" action="cron.php" target="jobframe">
            <input type="hidden" name="mode" value="dry">
            <button type="submit" <?= $running ? 'disabled' : '' ?>>Dry-Run / Test</button>
        </form>
        <form id="form-abort" method="post" action="cron.php">
            <input type="hidden" name="mode" value="abort">
            <button class="danger" type="submit" <?= $running ? '' : 'disabled' ?>>Lauf abbrechen</button>
        </form>
    </div>
    <p class="muted">Crawl schreibt RSS. Dry-Run ändert Hashes und Feeds nicht. Abbrechen gilt ab der nächsten Seite.</p>

    <form class="reset-box" method="post" action="cron.php" onsubmit="return document.getElementById('confirm_reset').checked;">
        <input type="hidden" name="mode" value="reset">
        <strong>Null-Lauf / Reset</strong>
        <p class="muted">Löscht gespeicherte Seiten und die Feeds. Der nächste Crawl merkt sich die Listen erneut, ohne sie zu veröffentlichen.</p>
        <label><input id="confirm_reset" type="checkbox" name="confirm_reset" value="1"> Wirklich alles löschen</label>
        <div class="row">
            <button class="danger" type="submit" <?= $running ? 'disabled' : '' ?>>Reset ausführen</button>
        </div>
    </form>

    <?php
    $show = $view;
    $isLive = is_array($show) && ($show['status'] ?? '') === 'running';
    ?>

    <h2><?= $isLive ? 'Laufender Vorgang' : 'Lauf' ?></h2>
    <?php if (!is_array($show)): ?>
        <p class="muted">Noch kein Lauf. Crawl oder Dry-Run starten.</p>
    <?php else: ?>
        <p>
            <span class="pill <?= h((string) $show['mode']) ?>"><?= h(modeLabel((string) $show['mode'])) ?></span>
            <span class="pill <?= h((string) $show['status']) ?>"><?= h(statusLabel((string) $show['status'])) ?></span>
            <?php if (!empty($show['id'])): ?>
                <span class="muted">#<?= h((string) $show['id']) ?></span>
            <?php endif; ?>
        </p>
        <p class="muted">
            Start: <?= h((string) ($show['started_at'] ?? '')) ?>
            <?php if (!empty($show['finished_at'])): ?>
                · Ende: <?= h((string) $show['finished_at']) ?>
            <?php endif; ?>
        </p>
        <?php if (!empty($show['current_source_name']) && ($show['status'] ?? '') === 'running'): ?>
            <p>Aktuell: <strong><?= h((string) $show['current_source_name']) ?></strong><br>
                <code><?= h((string) ($show['current_url'] ?? '')) ?></code></p>
        <?php endif; ?>

        <div class="kpis">
            <div class="kpi"><span>Quellen</span><b><?= (int) ($show['sources_done'] ?? 0) ?>/<?= (int) ($show['sources_total'] ?? 0) ?></b></div>
            <div class="kpi"><span>Seiten</span><b><?= (int) ($show['pages'] ?? 0) ?></b></div>
            <div class="kpi"><span>Neu</span><b><?= (int) ($show['new'] ?? 0) ?></b></div>
            <div class="kpi"><span>Geändert</span><b><?= (int) ($show['changed'] ?? 0) ?></b></div>
            <div class="kpi"><span>Unverändert</span><b><?= (int) ($show['unchanged'] ?? 0) ?></b></div>
            <div class="kpi"><span>Fehler</span><b><?= (int) ($show['errors'] ?? 0) ?></b></div>
        </div>

        <h3>Quellen</h3>
        <table>
            <thead>
                <tr><th>Quelle</th><th>Status</th><th>Seiten</th><th>Neu</th><th>Geändert</th><th>Fehler</th></tr>
            </thead>
            <tbody>
            <?php foreach ($show['sources'] ?? [] as $src): ?>
                <tr>
                    <td><?= h((string) ($src['name'] ?? $src['id'] ?? '')) ?></td>
                    <td><?= h(statusLabel((string) ($src['status'] ?? ''))) ?></td>
                    <td><?= (int) ($src['pages'] ?? 0) ?></td>
                    <td><?= (int) ($src['new'] ?? 0) ?></td>
                    <td><?= (int) ($src['changed'] ?? 0) ?></td>
                    <td><?= (int) ($src['error_count'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Fehler</h3>
        <?php if (empty($show['error_list'])): ?>
            <p class="muted">Keine Fehler in diesem Lauf.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Quelle</th><th>URL</th><th>Meldung</th></tr></thead>
                <tbody>
                <?php foreach ($show['error_list'] as $err): ?>
                    <tr>
                        <td><?= h((string) ($err['source'] ?? '')) ?></td>
                        <td><code><?= h((string) ($err['url'] ?? '')) ?></code></td>
                        <td><?= h((string) ($err['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Neue Seiten</h3>
        <?php if (empty($show['items'])): ?>
            <p class="muted">Keine neuen Seiten.</p>
        <?php else: ?>
            <ul class="plain">
                <?php foreach ($show['items'] as $item): ?>
                    <li>
                        <span class="pill"><?= h((string) ($item['kind'] ?? '')) ?></span>
                        <a href="<?= h((string) ($item['url'] ?? '')) ?>"><?= h((string) ($item['title'] ?? '')) ?></a>
                        <span class="muted"><?= h((string) ($item['source_name'] ?? '')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Log</h3>
        <pre class="log" id="log"><?php
            $lines = $show['log'] ?? [];
            echo h(implode("\n", $lines));
        ?></pre>
    <?php endif; ?>

    <h2>Letzte Läufe</h2>
    <?php if ($history === []): ?>
        <p class="muted">Noch keine archivierten Läufe.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Zeit</th><th>Art</th><th>Status</th><th>Seiten</th><th>Neu</th><th>Fehler</th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><a href="?run=<?= h((string) ($row['id'] ?? '')) ?>"><?= h((string) ($row['started_at'] ?? $row['id'] ?? '')) ?></a></td>
                    <td><span class="pill <?= h((string) ($row['mode'] ?? '')) ?>"><?= h(modeLabel((string) ($row['mode'] ?? ''))) ?></span></td>
                    <td><?= h(statusLabel((string) ($row['status'] ?? ''))) ?></td>
                    <td><?= (int) ($row['pages'] ?? 0) ?></td>
                    <td><?= (int) ($row['new'] ?? 0) ?></td>
                    <td><?= (int) ($row['errors'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Quellen</h2>
    <ul class="plain">
        <?php foreach ($sources as $source): ?>
            <li>
                <?= h((string) ($source['name'] ?? $source['id'] ?? '')) ?>
                · <?= h((string) ($source['category'] ?? '')) ?>
                <div class="muted"><?= h((string) ($source['url'] ?? '')) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="muted"><a href="index.php">Aktueller Stand</a></p>

    <script>
    (function () {
        const running = <?= $running ? 'true' : 'false' ?>;
        const kick = (formId) => {
            const form = document.getElementById(formId);
            if (!form) return;
            form.addEventListener('submit', function () {
                window.setTimeout(function () { location.href = 'index.php'; }, 800);
            });
        };
        kick('form-run');
        kick('form-dry');
        if (running && !location.search.includes('run=')) {
            window.setTimeout(function () { location.reload(); }, 2500);
        }
    })();
    </script>
</body>
</html>
