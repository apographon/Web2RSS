<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/App.php';

final class TestHarness
{
    public int $passed = 0;
    public int $failed = 0;

    /** @var list<string> */
    public array $failures = [];

    public function assertTrue(bool $ok, string $message): void
    {
        if ($ok) {
            $this->passed++;
            echo "  OK  {$message}\n";
            return;
        }
        $this->failed++;
        $this->failures[] = $message;
        echo "  FAIL  {$message}\n";
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertTrue($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }

    public function section(string $title): void
    {
        echo "\n== {$title} ==\n";
    }
}

$t = new TestHarness();
$root = dirname(__DIR__);
App::init();

$t->section('Log-Analyse (Fixtures)');
$failLog = LogAnalyzer::parseFile($root . '/tests/fixtures/crawl-connect-fail.log');
$t->assertTrue($failLog['connect_tunnel'] === 2, 'CONNECT-Fixture erkennt 2 Tunnel-Fehler');
$t->assertTrue($failLog['clean'] === false, 'CONNECT-Fixture ist nicht sauber');
$okLog = LogAnalyzer::parseFile($root . '/tests/fixtures/crawl-ok.log');
$t->assertTrue($okLog['clean'] === true, 'OK-Fixture ist sauber');
$t->assertSame(5, count($okLog['sources']), 'OK-Fixture hat 5 Quellen');
$t->assertTrue(str_contains(LogAnalyzer::report($failLog), 'CONNECT-Tunnel'), 'Report nennt CONNECT-Problem');

$t->section('URL / Ausschlüsse / Policy');
$t->assertTrue(Exclusions::isExcluded('https://steinbornfoto.de/impressum/'), 'Impressum ausgeschlossen');
$t->assertTrue(Exclusions::isExcluded('https://example.com/contact'), 'Kontakt ausgeschlossen');
$t->assertTrue(!Exclusions::isExcluded('https://thiloleipoldt.myportfolio.com/wald'), 'Projektseite nicht ausgeschlossen');
$leipoldt = SourcePolicy::forUrl('https://thiloleipoldt.myportfolio.com/work');
$t->assertSame('domain', $leipoldt->scope, 'Portfolio ist Domain-Scope');
$t->assertTrue($leipoldt->allows('https://thiloleipoldt.myportfolio.com/wald'), 'Portfolio darf Unterseite');
$fc = SourcePolicy::forUrl('https://www.fotocommunity.de/user_photos/2598922?sort=new');
$t->assertSame('prefix', $fc->scope, 'fotocommunity ist Prefix-Scope');
$t->assertTrue(!$fc->allows('https://www.fotocommunity.de/photo/irgendwas/1'), 'fotocommunity fremde Fotos gesperrt');
$t->assertTrue($fc->allows('https://www.fotocommunity.de/user_photos/2598922'), 'fotocommunity User-Pfad erlaubt');

$t->section('Artikel ohne Related-Stories');
$extractor = new Extractor();
$article = <<<'HTML'
<html><head>
<meta property="og:title" content="CarPlay"/>
<meta property="og:description" content="Find chargers on the dashboard."/>
<meta property="og:image" content="https://example.test/post/hero.webp"/>
<link rel="canonical" href="https://example.test/post/"/>
</head><body><main>
<article class="blog-article">
<h1>CarPlay</h1>
<p>Find chargers on the dashboard.</p>
<img src="https://example.test/post/body.webp" width="800" height="400"/>
<section class="blog-article-related" aria-labelledby="rel">
<h2 id="rel">Related stories</h2>
<article class="blog-article-card"><a href="https://example.test/new/"><img src="https://example.test/new/thumb.webp" width="420" height="236"/><h3>Brand new post</h3></a></article>
</section>
</article>
</main></body></html>
HTML;
$swapped = str_replace(
    ['Brand new post', 'https://example.test/new/thumb.webp', 'https://example.test/new/'],
    ['Some other post', 'https://example.test/other/thumb.webp', 'https://example.test/other/'],
    $article
);
$first = $extractor->extract($article, 'https://example.test/post/');
$second = $extractor->extract($swapped, 'https://example.test/post/');
$t->assertSame($extractor->hash($first), $extractor->hash($second), 'Wechselnde Related-Stories ändern den Hash nicht');
$t->assertTrue(!str_contains($first['text'], 'Brand new post'), 'Related-Text ist nicht im Artikeltext');
$t->assertTrue(!in_array('https://example.test/new/thumb.webp', $first['images'], true), 'Related-Bild ist nicht im Artikel');
$t->assertTrue(in_array('https://example.test/post/hero.webp', $first['images'], true), 'Beitragsbild bleibt erhalten');
$t->assertTrue(in_array('https://example.test/new/', $first['links'], true), 'Related-Link bleibt für die Entdeckung erhalten');

$t->section('Listen statt ganze Websites');
$blog = 'https://plugsurfing.com/blog/';
$t->assertTrue(ListingRules::isPost('https://plugsurfing.com/blog/ignitis-on-baltics/', $blog), 'Blogbeitrag unter der Liste');
$t->assertTrue(!ListingRules::isPost('https://plugsurfing.com/network', $blog), 'Shop-Pfad neben dem Blog ist kein Beitrag');
$t->assertTrue(!ListingRules::isPost('https://plugsurfing.com/blog/audience/driver-stories/', $blog), 'Themenliste ist kein Beitrag');
$t->assertTrue(ListingRules::isPagination('https://plugsurfing.com/blog/page/2/', $blog), 'Blog-Blätterseite erkannt');
$t->assertTrue(ListingRules::isPagination('https://www.energieloesung.de/magazin/?p=2', 'https://www.energieloesung.de/magazin/'), 'Magazin-Parameter ist eine Blätterseite');
$t->assertTrue(ListingRules::isPost('https://www.energieloesung.de/magazin/neuer-artikel/', 'https://www.energieloesung.de/magazin/'), 'Magazinartikel ist ein Beitrag');
$t->assertTrue(!ListingRules::isPost('https://www.energieloesung.de/p/rfid-karte', 'https://www.energieloesung.de/magazin/'), 'Produktseite ist kein Magazinbeitrag');
$t->assertTrue(ListingRules::isPagination('https://www.moijn.de/page/2/', 'https://www.moijn.de/'), 'Startseiten-Blätterseite erkannt');
$t->assertTrue(ListingRules::isPost('https://www.moijn.de/2026/08/neuer-beitrag/', 'https://www.moijn.de/'), 'Datiertem Blogpfad auf der Startseite');
$t->assertTrue(!ListingRules::isPost('https://www.moijn.de/category/daily-pic/', 'https://www.moijn.de/'), 'Kategorie ist kein Beitrag');
$t->assertTrue(!ListingRules::isPost('https://thiloleipoldt.myportfolio.com/wald', 'https://thiloleipoldt.myportfolio.com/work'), 'Projekt außerhalb des Listenpfads ist kein Kind');
$t->assertTrue(ListingRules::isLoosePost('https://thiloleipoldt.myportfolio.com/wald', 'https://thiloleipoldt.myportfolio.com/work'), 'Portfolio-Projekt bleibt über die Lose-Regel sichtbar');
$t->assertTrue(!ListingRules::isLoosePost('https://thiloleipoldt.myportfolio.com/contact', 'https://thiloleipoldt.myportfolio.com/work'), 'Kontakt bleibt ausgeschlossen');
$t->assertTrue(ListingRules::isPost('https://dergreif.org/article/some-story', 'https://dergreif.org/'), 'Artikel unter der Startseite');
$t->assertTrue(!ListingRules::isPost('https://dergreif.org/partners', 'https://dergreif.org/'), 'Einstufiger Bereichsname ist kein Beitrag');
$last = strtotime('2026-11-13T10:00:00+01:00');
$t->assertTrue(!ListingRules::shouldPublish(false, false, $last + 10, $last, true), 'Erster Lauf veröffentlicht nichts');
$t->assertTrue(!ListingRules::shouldPublish(true, true, $last + 10, $last, true), 'Bekannte URL bleibt stumm');
$t->assertTrue(!ListingRules::shouldPublish(true, false, $last - 86400, $last, true), 'Datum vor dem letzten Lauf bleibt stumm');
$t->assertTrue(ListingRules::shouldPublish(true, false, $last + 3600, $last, true), 'Datum nach dem letzten Lauf wird gemeldet');
$t->assertTrue(ListingRules::shouldPublish(true, false, null, $last, true), 'Undatiert auf der ersten Seite wird gemeldet');
$t->assertTrue(!ListingRules::shouldPublish(true, false, null, $last, false), 'Undatiertes Archiv bleibt stumm');

$t->section('RSS-Limit Rückstau vs. 30');
$rssTmp = sys_get_temp_dir() . '/rss-writer-' . bin2hex(random_bytes(3));
mkdir($rssTmp . '/feeds', 0775, true);
$prevRoot = App::$root;
$prevCfg = App::$config;
App::$root = $rssTmp;
App::$config = array_replace(App::$config, ['feed_item_limit' => 30, 'base_url' => 'https://example.test']);
$fakeItems = [];
for ($i = 0; $i < 45; $i++) {
    $fakeItems[] = [
        'title' => 'Item ' . $i,
        'url' => 'https://example.test/p' . $i,
        'guid' => 'g' . $i,
        'ts' => 1_700_000_000 + $i,
        'excerpt' => 'x',
        'images' => [],
        'source_name' => 't',
    ];
}
$writer = new RssWriter();
$writer->writeCategory('fotografie', ['title' => 'Fotografie'], $fakeItems);
$capped = (string) file_get_contents($rssTmp . '/feeds/fotografie.xml');
$t->assertSame(30, substr_count($capped, '<item>'), 'Normaler Lauf kaps RSS auf 30');
$writer->writeCategory('fotografie', ['title' => 'Fotografie'], $fakeItems, 0);
$backlog = (string) file_get_contents($rssTmp . '/feeds/fotografie.xml');
$t->assertSame(45, substr_count($backlog, '<item>'), 'Erster Lauf schreibt alle Items');
$t->assertTrue(str_contains($backlog, '>t<br>x</p>'), 'Quellenname, dann br, dann Excerpt');
App::$root = $prevRoot;
App::$config = $prevCfg;

$t->section('Lokaler Start (PHP Built-in Server)');
$port = 8767;
$cmd = sprintf(
    '%s -S 127.0.0.1:%d -t %s',
    escapeshellarg(PHP_BINARY),
    $port,
    escapeshellarg($root)
);
$doc = proc_open(
    $cmd,
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    $root,
    [
        'HTTP_PROXY' => '',
        'HTTPS_PROXY' => '',
        'http_proxy' => '',
        'https_proxy' => '',
        'ALL_PROXY' => '',
    ]
);
$t->assertTrue(is_resource($doc), 'PHP-Server-Prozess gestartet');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    usleep(100000);
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $ready = true;
        break;
    }
}
$t->assertTrue($ready, 'Server lauscht auf 127.0.0.1:' . $port);
$html = '';
$status = 0;
if ($ready) {
    $ch = curl_init('http://127.0.0.1:' . $port . '/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);
    $html = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
}
$t->assertSame(200, $status, 'index.php liefert HTTP 200');
$t->assertTrue(str_contains($html, 'Website-RSS-Monitor'), 'Statusseite enthält Titel');
$t->assertTrue(str_contains($html, 'Dry-Run'), 'Statusseite enthält Dry-Run');
$t->assertTrue(str_contains($html, 'Reset'), 'Statusseite enthält Reset');
if (is_resource($doc)) {
    $statusInfo = proc_get_status($doc);
    if (!empty($statusInfo['pid']) && function_exists('posix_kill')) {
        posix_kill((int) $statusInfo['pid'], defined('SIGTERM') ? SIGTERM : 15);
    }
    proc_terminate($doc);
    proc_close($doc);
}

$t->section('HTTP-Client: fünf Quellen + Proxy-Bypass');
putenv('HTTP_PROXY=http://127.0.0.1:1');
putenv('HTTPS_PROXY=http://127.0.0.1:1');
putenv('http_proxy=http://127.0.0.1:1');
putenv('https_proxy=http://127.0.0.1:1');
putenv('ALL_PROXY=http://127.0.0.1:1');
$client = new HttpClient();
$catalog = JsonStore::read($root . '/data/sources.json', []);
$sourceList = $catalog['sources'] ?? [];
$t->assertTrue(count($sourceList) >= 5, 'sources.json hat mindestens 5 Quellen');
foreach ($sourceList as $source) {
    $id = (string) ($source['id'] ?? '');
    $url = (string) ($source['url'] ?? '');
    $res = $client->get($url);
    $ok = !empty($res['ok']) && $res['status'] < 400 && strlen((string) $res['body']) > 500;
    $tunnel = str_contains((string) ($res['error'] ?? ''), 'CONNECT tunnel');
    $t->assertTrue(!$tunnel, "{$id}: kein CONNECT-Tunnel-Fehler");
    $t->assertTrue($ok, "{$id}: HTTP OK (" . ($res['error'] ?: $res['status']) . ', ' . strlen((string) $res['body']) . ' Bytes)');
}

$t->section('Testlauf Dry-Run + Log-Analyse');
$tmp = sys_get_temp_dir() . '/rss-monitor-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/data/state', 0775, true);
mkdir($tmp . '/data/items', 0775, true);
mkdir($tmp . '/data/runs', 0775, true);
mkdir($tmp . '/feeds', 0775, true);
copy($root . '/config.php', $tmp . '/config.php');
copy($root . '/data/sources.json', $tmp . '/data/sources.json');
file_put_contents($tmp . '/config.local.php', "<?php\nreturn ['request_delay_ms' => 80, 'max_pages_per_source' => 400];\n");

$env = 'RSS_MONITOR_ROOT=' . escapeshellarg($tmp);
$cmd = $env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cron.php') . ' --dry-run';
passthru($cmd, $exitCode);
$t->assertSame(0, $exitCode, 'Dry-Run Exit-Code 0');
$logPath = $tmp . '/data/crawl.log';
$t->assertTrue(is_file($logPath), 'crawl.log existiert nach Dry-Run');
$analysis = LogAnalyzer::parseFile($logPath);
echo LogAnalyzer::report($analysis);
$expectedIds = array_map(static fn($s) => (string) $s['id'], $sourceList);
foreach ($expectedIds as $id) {
    $t->assertTrue(isset($analysis['sources'][$id]), "Log enthält Quelle {$id}");
    $t->assertTrue(!empty($analysis['sources'][$id]['finished']), "Quelle {$id} hat Abschlusszeile");
}
$t->assertSame(0, $analysis['connect_tunnel'], 'Dry-Run ohne CONNECT-Tunnel-Fehler');
$t->assertTrue($analysis['clean'], 'Dry-Run-Log ist sauber (0 Fehler je Quelle)');

echo "\n-----------------------------\n";
echo "{$t->passed} OK, {$t->failed} FAIL\n";
if ($t->failures !== []) {
    echo "Fehlgeschlagen:\n- " . implode("\n- ", $t->failures) . "\n";
    exit(1);
}
exit(0);
