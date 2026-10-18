<?php
/**
 * php-shop-watchdog
 *
 * Leichtgewichtiges Monitoring für Webshops mit WhatsApp-Alerts (CallMeBot)
 * und täglichem Bericht inkl. Cloudflare-Statistiken.
 *
 * - Läuft NUR per Cronjob (CLI), Browser-Aufrufe werden mit 404 abgewiesen
 * - Gehört ausserhalb des Webroots
 * - Alarm erst nach X Fehlern hintereinander
 * - Referenz-Check bei Verbindungsfehlern (Problem beim Monitor statt beim Shop?)
 * - Täglicher Bericht mit Cloudflare-Zahlen (geblockt / challenged / rate limited)
 *
 * Test:  php monitor.php --report   (schickt den Tagesbericht sofort)
 */

// 1) Schutz: Aufruf über den Browser wird abgewiesen
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Zurich');

$config = require __DIR__ . '/config.php';
$config['site_name']    = $config['site_name']    ?? (parse_url($config['url'], PHP_URL_HOST) ?: $config['url']);
$config['monitor_name'] = $config['monitor_name'] ?? (gethostname() ?: 'Monitor');
$config['cf_api_token'] = $config['cf_api_token'] ?? '';
$config['cf_zone_id']   = $config['cf_zone_id']   ?? '';

// 2) Überlappende Läufe verhindern
$lock = fopen(__DIR__ . '/monitor.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$stateFile   = __DIR__ . '/status.json';
$logFile     = __DIR__ . '/monitor.log';
$forceReport = in_array('--report', $argv ?? [], true);

$state  = loadState($stateFile);
$now    = time();
$nowStr = date('Y-m-d H:i:s', $now);

// ------------------------------------------------------------------
// Check
// ------------------------------------------------------------------
$check  = httpCheck($config['url'], $config['timeout']);
$status = classify($check, $config['slow_threshold']); // ok | slow | down | netz

// Keine Verbindung? Dann prüfen, ob der Monitor selbst Internet hat
if ($status === 'down' && $check['http_code'] === 0) {
    $ref = httpCheck($config['reference_url'], 15);
    if ($ref['http_code'] === 0) {
        $status = 'netz';
    }
}

logLine($logFile, sprintf('%s;%s;%.2f;%d;%s',
    $nowStr, $status, $check['latency'], $check['http_code'], $check['error']));

$state['last_check'] = [
    'latency'   => $check['latency'],
    'http_code' => $check['http_code'],
    'status'    => $status,
    'error'     => $check['error'],
    'timestamp' => $nowStr,
];

// Statistik für den Tagesbericht
$state['stats']['checks']++;
if ($status !== 'netz') {
    $state['stats']['latency_sum'] += $check['latency'];
    $state['stats']['latency_max']  = max($state['stats']['latency_max'], $check['latency']);
}
if ($status !== 'ok') {
    $state['stats']['problems']++;
}

// ------------------------------------------------------------------
// Alarm-Logik
// ------------------------------------------------------------------
if ($status === 'ok') {
    if ($state['alert_sent']) {
        $dauer = formatDuration($now - $state['problem_since']);
        $msg = $state['alert_type'] === 'netz'
            ? "Monitor {$config['monitor_name']} wieder online.\n{$config['site_name']}: {$check['latency']}s\nStörung dauerte: {$dauer}\nZeit: {$nowStr}"
            : "{$config['site_name']} wieder normal!\nAntwortzeit: {$check['latency']}s\nDauer: {$dauer}\nZeit: {$nowStr}";
        sendWhatsApp($config, $msg);
    }
    $state['consecutive_problems'] = 0;
    $state['alert_sent']    = false;
    $state['alert_type']    = null;
    $state['problem_since'] = 0;
} else {
    if ($state['consecutive_problems'] === 0) {
        $state['problem_since'] = $now;
    }
    $state['consecutive_problems']++;
    $state['last_problem'] = $state['last_check'];

    $schwelleErreicht = $state['consecutive_problems'] >= $config['fail_threshold'];
    $eskalation = $state['alert_sent'] && $status === 'down' && $state['alert_type'] !== 'down';

    if (($schwelleErreicht && !$state['alert_sent']) || $eskalation) {
        $msg = buildAlert($status, $check, $nowStr, $state['consecutive_problems'], $config);
        // Nur als gesendet markieren, wenn CallMeBot es angenommen hat,
        // sonst beim nächsten Lauf erneut versuchen
        if (sendWhatsApp($config, $msg)) {
            $state['alert_sent']    = true;
            $state['alert_type']    = $status;
            $state['alert_sent_at'] = $now;
        }
    }
}

// ------------------------------------------------------------------
// Täglicher Bericht
// ------------------------------------------------------------------
$today = date('Y-m-d', $now);
$isReportTime = (int)date('G', $now) >= $config['report_hour']
             && ($state['last_report'] ?? '') !== $today;

if ($isReportTime || $forceReport) {
    $msg = buildReport($config, $state, $check, $status, $now, $logFile);
    if (sendWhatsApp($config, $msg) && !$forceReport) {
        $state['last_report'] = $today;
        $state['stats'] = emptyStats();
    }
}

saveState($stateFile, $state);
exit(0);


// ==================================================================
// Funktionen
// ==================================================================

function httpCheck(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; php-shop-watchdog/2.0)',
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ]);
    curl_exec($ch);
    $result = [
        'latency'   => round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
        'http_code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'error'     => curl_error($ch),
    ];
    unset($ch);
    return $result;
}

function classify(array $check, float $slowThreshold): string
{
    if ($check['http_code'] === 0 || $check['http_code'] >= 400) {
        return 'down';
    }
    if ($check['latency'] > $slowThreshold) {
        return 'slow';
    }
    return 'ok';
}

function buildAlert(string $status, array $c, string $zeit, int $anzahl, array $cfg): string
{
    $minuten = $anzahl * $cfg['interval_minutes'];
    $site    = $cfg['site_name'];
    $mon     = $cfg['monitor_name'];
    switch ($status) {
        case 'netz':
            return "Monitor {$mon}: Netzwerkstörung!\n{$site} UND Referenzseite nicht erreichbar.\n"
                 . "Problem liegt wahrscheinlich beim Monitor, nicht beim Shop.\nSeit ca. {$minuten} Min\nZeit: {$zeit}";
        case 'down':
            $fehler = $c['error'] !== '' ? "\nFehler: {$c['error']}" : '';
            return "{$site} ALERT: DOWN!\nHTTP: {$c['http_code']}\nAntwortzeit: {$c['latency']}s"
                 . "\nSeit ca. {$minuten} Min{$fehler}\nZeit: {$zeit}";
        default:
            return "{$site} ALERT: LANGSAM!\nAntwortzeit: {$c['latency']}s\nHTTP: {$c['http_code']}"
                 . "\nSeit ca. {$minuten} Min\nZeit: {$zeit}";
    }
}

function buildReport(array $cfg, array $state, array $check, string $status, int $now, string $logFile): string
{
    $s = $state['stats'];
    $gemessen = max(1, $s['checks']);
    $avg = round($s['latency_sum'] / $gemessen, 2);

    $lines   = [];
    $lines[] = $cfg['site_name'] . ' Tagesbericht ' . date('d.m.Y', $now);
    $lines[] = 'Shop aktuell: ' . ($status === 'ok' ? 'ok' : strtoupper($status)) . " ({$check['latency']}s)";
    $lines[] = "Checks: {$s['checks']} | Ø {$avg}s | max {$s['latency_max']}s | Probleme: {$s['problems']}";

    if ($cfg['cf_api_token'] === '' || $cfg['cf_zone_id'] === '') {
        return implode("\n", $lines); // Cloudflare nicht konfiguriert
    }
    $labels = array_merge([
        'blocked'    => 'Geblockt (Block-Regeln)',
        'challenged' => 'Challenges',
        'ratelimit'  => 'Rate Limiting',
    ], $cfg['cf_labels'] ?? []);

    $cf = cloudflareStats($cfg['cf_api_token'], $cfg['cf_zone_id'], $now - 86400, $now);
    if ($cf === null) {
        $lines[] = 'Cloudflare: Daten nicht verfügbar (siehe monitor.log)';
    } else {
        $lines[] = '';
        $lines[] = 'Cloudflare letzte 24h:';
        if ($cf['req_ok']) {
            $ratio = $cf['visitors'] > 0 ? round($cf['requests'] / $cf['visitors'], 1) : 0;
            $lines[] = 'Requests: ' . num($cf['requests']);
            $lines[] = 'Besucher (IPs): ca. ' . num($cf['visitors']) . " | Ø {$ratio} Req./Besucher";
            if ($cf['peak_visitors']) {
                $p = $cf['peak_visitors'];
                $lines[] = 'Spitze Besucher: ' . date('d.m. H:00', $p['time']) . ' – '
                         . num($p['visitors']) . ' Besucher / ' . num($p['requests']) . ' Req.';
            }
            if ($cf['peak_requests']) {
                $p = $cf['peak_requests'];
                $lines[] = 'Spitze Requests: ' . date('d.m. H:00', $p['time']) . ' – '
                         . num($p['requests']) . ' Req. / ' . num($p['visitors']) . ' Besucher';
            }
            $lines[] = '';
        }
        if ($cf['fw_ok']) {
            // Zuordnung über die Aktion der Regel (Block / Challenge / Rate Limiting).
            // Bezeichnungen lassen sich in config.php über 'cf_labels' anpassen.
            $plus = $cf['capped'] ? '+' : '';
            $lines[] = $labels['blocked'] . ': ' . num($cf['blocked']) . $plus;
            $lines[] = $labels['challenged'] . ': ' . num($cf['challenged']) . ' (gelöst: ' . num($cf['solved']) . ')';
            $lines[] = $labels['ratelimit'] . ': ' . num($cf['ratelimit']);
            if ($cf['other'] > 0) {
                $lines[] = 'Sonstige: ' . num($cf['other']);
            }
        } else {
            $lines[] = 'Firewall-Ereignisse: nicht verfügbar (siehe monitor.log)';
        }
    }
    return implode("\n", $lines);
}

/**
 * Holt Request-Zahlen und Firewall-Ereignisse über die Cloudflare GraphQL API.
 * Free-Abo: firewallEventsAdaptiveGroups ist nicht verfügbar, darum werden die
 * einzelnen Ereignisse (firewallEventsAdaptive, max. 24h, 10'000 pro Abfrage)
 * geholt und hier gezählt. Bei mehr Ereignissen wird seitenweise weitergeblättert.
 * Benötigt ein API-Token mit "Zone → Analytics → Read" nur für die überwachte Zone.
 */
function cloudflareStats(string $token, string $zoneId, int $since, int $until): ?array
{
    // Etwas Abstand zur 24h-Aufbewahrungsgrenze des Free-Abos
    $since = max($since, $until - 86000);

    $out = [
        'requests' => 0, 'blocked' => 0, 'challenged' => 0, 'solved' => 0,
        'ratelimit' => 0, 'other' => 0, 'capped' => false, 'fw_ok' => false, 'req_ok' => false,
        'visitors' => 0, 'peak_visitors' => null, 'peak_requests' => null,
    ];

    // 1) Requests (stündliche Gruppen)
    $q1 = <<<'GQL'
query ($zone: string, $since: Time, $until: Time) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      httpRequests1hGroups(limit: 48, filter: {datetime_geq: $since, datetime_lt: $until}) {
        dimensions { datetime }
        sum { requests }
        uniq { uniques }
      }
    }
  }
}
GQL;
    $zone = cfQuery($token, $q1, ['zone' => $zoneId, 'since' => cfTime($since), 'until' => cfTime($until)]);
    if ($zone !== null) {
        $out['req_ok'] = true;
        foreach ($zone['httpRequests1hGroups'] ?? [] as $h) {
            $hour = [
                'time'     => strtotime($h['dimensions']['datetime']),
                'requests' => (int)$h['sum']['requests'],
                'visitors' => (int)$h['uniq']['uniques'],
            ];
            $out['requests'] += $hour['requests'];
            // Summe der Stunden-Besucher: wer in mehreren Stunden kommt, zählt mehrfach,
            // für Bots mit 1 Request pro IP ist die Zahl aber genau
            $out['visitors'] += $hour['visitors'];
            if ($out['peak_visitors'] === null || $hour['visitors'] > $out['peak_visitors']['visitors']) {
                $out['peak_visitors'] = $hour;
            }
            if ($out['peak_requests'] === null || $hour['requests'] > $out['peak_requests']['requests']) {
                $out['peak_requests'] = $hour;
            }
        }
    }

    // 2) Firewall-Ereignisse einzeln holen, neueste zuerst, seitenweise
    $q2 = <<<'GQL'
query ($zone: string, $since: Time, $until: Time) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      firewallEventsAdaptive(limit: 10000, orderBy: [datetime_DESC], filter: {datetime_geq: $since, datetime_lt: $until}) {
        action
        source
        datetime
      }
    }
  }
}
GQL;
    $pageUntil = $until;
    for ($page = 0; $page < 5; $page++) {
        $zone = cfQuery($token, $q2, ['zone' => $zoneId, 'since' => cfTime($since), 'until' => cfTime($pageUntil)]);
        if ($zone === null) {
            break; // Firewall-Daten nicht verfügbar, Requests evtl. trotzdem
        }
        $out['fw_ok'] = true;
        $events = $zone['firewallEventsAdaptive'] ?? [];

        foreach ($events as $e) {
            $action = (string)$e['action'];
            $source = (string)$e['source'];
            if ($source === 'ratelimit') {
                $out['ratelimit']++;
            } elseif (str_contains($action, 'solved')) {
                $out['solved']++;
            } elseif (str_contains($action, 'challenge')) {
                $out['challenged']++;
            } elseif ($action === 'block') {
                $out['blocked']++;
            } else {
                $out['other']++;
            }
        }

        if (count($events) < 10000) {
            break; // alles geholt
        }
        // Nächste Seite: alles vor dem ältesten Ereignis dieser Seite
        $pageUntil = strtotime(end($events)['datetime']);
        if ($page === 4) {
            $out['capped'] = true;
        }
    }
    return ($out['req_ok'] || $out['fw_ok']) ? $out : null;
}

function cfTime(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/** Führt eine GraphQL-Abfrage aus und liefert die erste Zone oder null (Fehler wird geloggt). */
function cfQuery(string $token, string $query, array $vars): ?array
{
    global $logFile;

    $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query, 'variables' => $vars]),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . trim($token),
            'Content-Type: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    $data = json_decode((string)$raw, true);
    if ($code !== 200 || !empty($data['errors']) || !isset($data['data']['viewer']['zones'][0])) {
        $err = $data['errors'][0]['message'] ?? "HTTP {$code}";
        logLine($logFile, date('Y-m-d H:i:s') . ';cloudflare_error;0;0;' . $err);
        return null;
    }
    return $data['data']['viewer']['zones'][0];
}

function sendWhatsApp(array $cfg, string $text): bool
{
    global $logFile;
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => $cfg['callmebot_phone'],
        'text'   => $text,
        'apikey' => $cfg['callmebot_apikey'],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);

    // CallMeBot antwortet auch bei Drosselung oder falschem API-Key oft mit HTTP 200,
    // darum zusätzlich den Antworttext prüfen
    $info = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
    if ($code !== 200) {
        logLine($logFile, date('Y-m-d H:i:s') . ";whatsapp_error;0;{$code};" . substr($info, 0, 200));
        return false;
    }
    if (stripos($body, 'queued') === false) {
        // Nicht als Fehler werten (sonst Wiederholung alle 5 Min), nur zur Diagnose loggen
        logLine($logFile, date('Y-m-d H:i:s') . ";whatsapp_warnung;0;{$code};" . substr($info, 0, 200));
    }
    return true;
}

function emptyStats(): array
{
    return ['checks' => 0, 'latency_sum' => 0.0, 'latency_max' => 0.0, 'problems' => 0];
}

function loadState(string $file): array
{
    $default = [
        'consecutive_problems' => 0,
        'alert_sent'    => false,
        'alert_type'    => null,
        'alert_sent_at' => 0,
        'problem_since' => 0,
        'last_problem'  => null,
        'last_check'    => null,
        'last_report'   => '',
        'stats'         => emptyStats(),
    ];
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $state = is_array($data) ? array_merge($default, $data) : $default;
    $state['stats'] = array_merge(emptyStats(), (array)$state['stats']);
    return $state;
}

function saveState(string $file, array $state): void
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

function logLine(string $file, string $line): void
{
    // Einfache Rotation bei 2 MB
    if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
        rename($file, $file . '.1');
    }
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

function formatDuration(int $sek): string
{
    $min = max(1, (int)round($sek / 60));
    return $min < 60 ? "{$min} Min" : floor($min / 60) . ' Std ' . ($min % 60) . ' Min';
}

function num(int $n): string
{
    return number_format($n, 0, '.', "'");  // Schweizer Format, z.B. 15'436
}
