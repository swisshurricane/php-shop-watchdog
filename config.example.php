<?php
/**
 * Kopieren nach config.php, Werte eintragen, dann:  chmod 600 config.php
 * config.php gehört NICHT ins Git-Repo (siehe .gitignore).
 */
return [
    // Zu prüfende Seite
    'url'              => 'https://www.example.com/',
    'reference_url'    => 'https://www.google.com/',   // Referenz für "hat der Monitor Internet?"
    'timeout'          => 30,                          // Sekunden bis Abbruch
    'slow_threshold'   => 4.0,                         // ab hier "LANGSAM" (Sekunden)

    // Alarm erst nach so vielen Fehlern hintereinander
    'fail_threshold'   => 2,
    'interval_minutes' => 5,                           // muss zum Cronjob passen

    // Täglicher Bericht ab dieser Stunde (einmal pro Tag)
    'report_hour'      => 8,

    // Optional: Namen in den Meldungen (Standard: Domain der URL bzw. Hostname des Servers)
    // 'site_name'     => 'example.com',
    // 'monitor_name'  => 'monitor-server',

    // CallMeBot (WhatsApp), Telefonnummer mit Ländervorwahl
    'callmebot_phone'  => '+41XXXXXXXXX',
    'callmebot_apikey' => 'XXXXXXX',

    // Cloudflare (optional, leer lassen = Bericht ohne Cloudflare-Teil)
    // API-Token nur mit "Zone → Analytics → Read" für die überwachte Zone
    'cf_api_token'     => '',
    'cf_zone_id'       => '',

    // Optional: eigene Bezeichnungen für die Cloudflare-Zahlen im Bericht.
    // Gezählt wird nach Aktion der Regel: Block, Challenge, Rate Limiting.
    // 'cf_labels' => [
    //     'blocked'    => 'Spam-Regel geblockt',
    //     'challenged' => 'Challenge ausserhalb CH',
    //     'ratelimit'  => 'Rate Limiting',
    // ],
];
