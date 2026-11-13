<?php

declare(strict_types=1);

require __DIR__ . '/src/App.php';

App::init();
App::ensureDirs();

$isCli = PHP_SAPI === 'cli';
$mode = 'run';

if ($isCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if ($arg === '--dry-run' || $arg === '--dry') {
            $mode = 'dry';
        } elseif ($arg === '--reset') {
            $mode = 'reset';
        } elseif ($arg === '--abort') {
            $mode = 'abort';
        }
    }
} else {
    $mode = (string) ($_POST['mode'] ?? $_GET['mode'] ?? 'run');
    if (!in_array($mode, ['run', 'dry', 'reset', 'abort'], true)) {
        $mode = 'run';
    }
    $key = (string) App::cfg('cron_key', '');
    if ($key !== '') {
        $given = (string) ($_REQUEST['key'] ?? '');
        if (!hash_equals($key, $given)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Forbidden\n";
            exit;
        }
    }
}

if ($mode === 'abort') {
    RunReport::requestAbort();
    Logger::line('Abbruch angefordert.');
    if (!$isCli) {
        header('Location: index.php', true, 303);
        exit;
    }
    echo "Abbruch angefordert.\n";
    exit;
}

if ($mode === 'reset') {
    if (!$isCli) {
        $confirm = (string) ($_POST['confirm_reset'] ?? '');
        if ($confirm !== '1') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Reset braucht Bestätigung.\n";
            exit;
        }
    }
}

$lockPath = App::path('data/crawl.lock');
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $msg = "Crawl läuft bereits.\n";
    if ($isCli) {
        fwrite(STDERR, $msg);
        exit(1);
    }
    if ($mode === 'abort') {
        exit;
    }
    header('Location: index.php?busy=1', true, 303);
    exit;
}

if (!$isCli) {
    ignore_user_abort(true);
    if ($mode !== 'reset') {
        header('Content-Type: text/plain; charset=utf-8');
    }
}

set_time_limit(0);

try {
    if ($mode === 'reset') {
        Monitor::reset();
        if ($isCli) {
            echo "Reset ausgeführt.\n";
        } else {
            header('Location: index.php?reset=1', true, 303);
        }
        exit;
    }

    $label = $mode === 'dry' ? 'Dry-Run' : 'Crawl';
    Logger::$report = RunReport::start($mode);
    Logger::line("=== {$label} start ===");
    $stats = (new Monitor())->run($mode, Logger::$report);
    $summary = sprintf(
        "%s: %d Quellen, %d Seiten, %d neue/geänderte Items, %d Fehler%s\n",
        $label,
        $stats['sources'],
        $stats['pages'],
        $stats['items'],
        $stats['errors'],
        !empty($stats['aborted']) ? ' (abgebrochen)' : ''
    );
    Logger::line(trim($summary));
    Logger::$report = null;
    echo $summary;
    if (!$isCli) {
        echo "Fertig. Statusseite: index.php\n";
    }
} catch (Throwable $e) {
    Logger::line('ABBRUCH: ' . $e->getMessage());
    try {
        Logger::$report?->addError('system', '', $e->getMessage());
        Logger::$report?->finish('error');
    } catch (Throwable) {
        // ignore
    }
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo $e->getMessage() . "\n";
} finally {
    Logger::$report = null;
    flock($lock, LOCK_UN);
    fclose($lock);
}
