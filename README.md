# Web2RSS

PHP-Monitor ohne Datenbank. Er beobachtet Websites, die keinen eigenen RSS-Feed haben, und schreibt Kategorie-Feeds für Fever, Reeder und andere RSS-Reader.

Eine Quelle ist die abonnierte Liste (Start-URL plus Blätterseiten), nicht die ganze Domain. Der erste Lauf merkt sich, was schon da ist, und veröffentlicht nichts.

## Voraussetzungen

- PHP 8.2 oder neuer, mit den Erweiterungen DOM, JSON und entweder cURL oder `allow_url_fopen`
- Ein Webspace, auf dem `data/` und `feeds/` beschreibbar sind
- Ein Cronjob, der `cron.php` per CLI aufruft

Kein Composer, keine Datenbank.

## Verzeichnis

```text
config.php                  Standardwerte, ohne Geheimnisse
config.local.php.example    Vorlage für die lokale Config
cron.php                    Lauf, Dry-Run, Reset, Abbruch
index.php                   Statusseite
src/                        Monitor, Listenregeln, Extraktion, RSS
data/sources.json           Kategorien und Quellen
data/state/                 bekannte URLs, nicht im Git
data/items/                 Feed-History, nicht im Git
feeds/{kategorie}.xml       erzeugte Feeds, nicht im Git
tests/run.php               Tests
```

`data/` ist per `.htaccess` nicht aus dem Web lesbar. `config.php` und `config.local.php` ebenfalls nicht.

## Einrichtung

1. Den Ordner auf den Webspace legen.
2. `data/` und `feeds/` beschreibbar machen (`chmod 775`).
3. `config.local.php.example` nach `config.local.php` kopieren und `base_url` auf die öffentliche URL dieses Ordners setzen, ohne Slash am Ende.
4. Quellen in `data/sources.json` eintragen.
5. Einmal per CLI starten: `php cron.php`. Der erste Lauf legt nur den Stand an.
6. Cron einrichten, zum Beispiel wöchentlich:

```cron
0 6 * * 0 php /pfad/zum/ordner/cron.php
```

7. In Fever die Feeds eintragen: `{base_url}/feeds/fotografie.xml` und die weiteren Kategorien.

`config.local.php` bleibt auf dem Server. Sie enthält keine Hosting-Zugänge. Einen optionalen `cron_key` nur dort setzen, nie ins Repository.

## Quellen

`data/sources.json` hat zwei Ebenen:

```json
{
  "categories": {
    "fotografie": { "title": "Fotografie", "description": "Neue Seiten …" }
  },
  "sources": [
    { "id": "beispiel", "name": "Beispiel", "url": "https://example.com/blog/", "category": "fotografie" }
  ]
}
```

`id` ist stabil und wird der Dateiname des Zustands. `url` ist die Listen-URL, die abonniert wird. Selektoren sind nicht nötig.

## Verhalten

- Blätterseiten der Liste zählen dazu: `/page/2`, `?page=`, `?p=`, `?seite=`. Voreinstellung sind 8 Listenseiten (`max_listing_pages`). Der Lauf stoppt früher, wenn eine Listenseite keine unbekannte URL mehr hat.
- Ein Beitrag ist ein Link von dieser Liste unter demselben Pfad. Shop, Kategorien, Navigation, Dateien und Kontaktseiten bleiben draußen.
- Liegt die Liste auf `/` und der Link hat nur ein Pfadsegment (`/partners`), gilt er als Bereich, nicht als Beitrag.
- Portfolios ohne Kinderpfad unter der Liste (ein `/work`, das auf `/wald` zeigt) nehmen die direkt verlinkten Projektseiten.
- Der erste Lauf, solange für die Quelle noch keine Seiten gespeichert sind, merkt sich alle sichtbaren Einträge und schreibt keinen Feed-Eintrag.
- Danach erscheint ein Eintrag nur, wenn die URL neu ist und das Datum der Seite auf oder nach dem letzten Lauf liegt. Ohne Datum zählt nur eine neue URL auf der ersten Listenseite. Tieferes undatiertes Archiv bleibt stumm.
- `pubDate` ist das Datum der Seite. Gibt es keins, ist es der Zeitpunkt des Laufs.
- Text- oder Template-Änderungen an bekannten Seiten erzeugen keinen Eintrag. Es gibt keine „Aktualisiert“-Meldungen.
- Pro Kategorie bleiben die letzten 30 Einträge (`feed_item_limit`).

Seiten, die hinter einem Bot-Schutz liegen, können mit HTTP 403 scheitern. Der Lauf protokolliert das und veröffentlicht daraus nichts.

## Betrieb

Statusseite `index.php`: Fortschritt, Log, Fehler, die letzten Läufe, Dry-Run, Abbruch und Reset nach Bestätigung.

CLI:

```text
php cron.php             echter Lauf
php cron.php --dry-run   Test, schreibt Zustand und Feeds nicht
php cron.php --reset     Zustand, Feed-History und RSS löschen
php cron.php --abort     laufenden Crawl abbrechen
php tests/run.php        Tests
```

Solange `cron_key` leer ist, kann `cron.php` auch per URL ausgelöst werden. Mit gesetztem Key nur als `cron.php?key=…`. Die CLI braucht den Key nicht. Lange Läufe gehören in die CLI: ein HTTP-Aufruf kann am Gateway-Timeout enden und den Worker abbrechen.

Reset löscht den gemerkten Stand. Der nächste Lauf ist wieder ein stiller erster Lauf und schickt den vorhandenen Bestand nicht erneut an Fever.
