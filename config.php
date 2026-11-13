<?php

declare(strict_types=1);

/**
 * Website-RSS-Monitor — lokale Einstellungen.
 *
 * base_url: öffentliche Basis-URL dieses Ordners auf dem Webspace,
 *           ohne Slash am Ende. Wird für die Feed-Links in Fever gebraucht.
 *           Beispiel: https://example.com/rss-monitor
 *
 * cron_key: optional. Wenn gesetzt, nur cron.php?key=GEHEIMNIS
 *           (CLI php cron.php braucht keinen Key.)
 *
 * ToDo: Feed-Schutz per Token in der Feed-URL (siehe README).
 */
return [
    'base_url' => '',
    'timezone' => 'Europe/Berlin',
    'cron_key' => '',
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    'max_pages_per_source' => 400,
    'request_delay_ms' => 400,
    'request_timeout' => 30,
    'max_images_per_item' => 6,
    'feed_item_limit' => 30,
    'max_listing_pages' => 8,
    'excerpt_length' => 420,
    'max_text_for_hash' => 8000,
];
