# php-shop-watchdog

Leichtgewichtiges PHP-Monitoring für Webshops mit WhatsApp-Alerts (CallMeBot) und täglichem Bericht inkl. Cloudflare-Statistiken.

Entstanden als Reaktion auf wiederholte Bot-Angriffe auf einen kleinen Schweizer Webshop.

## Features

- Misst bei jedem Cronjob-Lauf (z.B. alle 5 Minuten) die Antwortzeit der überwachten URL
- Alarm erst nach N aufeinanderfolgenden Problemen (verhindert Fehlalarme durch einzelne Ausreisser)
- Referenz-Check: Bei Verbindungsfehlern wird geprüft, ob der Monitor selbst kein Internet hat
- Meldung "wieder normal" inkl. Dauer der Störung
- Täglicher Bericht: Anzahl Checks, Durchschnitts- und Maximallatenz, Anzahl Probleme
- **Cloudflare-Statistiken im Tagesbericht** über die GraphQL API (Free Plan reicht):
  Requests, Besucher (IPs), Requests pro Besucher, Spitzenstunden sowie geblockte, gechallengte und rate-limitierte Anfragen der letzten 24h
- Log jedes Checks (`monitor.log`, rotiert bei 2 MB)
- Läuft nur per Cronjob (CLI), Browser-Aufrufe werden mit 404 abgewiesen
- Lock-File verhindert überlappende Läufe
- Zeitzone: Europe/Zurich (im Script anpassbar)

## Voraussetzungen

- PHP 8.x mit curl-Extension
- Hosting mit Cronjob-Funktion, **idealerweise getrennt vom überwachten Server**
- CallMeBot-Zugang für WhatsApp (kostenlos)
- Optional: Cloudflare API-Token (nur Lesezugriff auf Analytics)

## Installation

### 1. Dateien hochladen

In einen Ordner **ausserhalb des Webroots**, z.B.:
```
/var/www/vhosts/example.com/monitor/
```

### 2. CallMeBot einrichten

Anleitung und aktuelle Telefonnummer auf der offiziellen Seite:
https://www.callmebot.com/blog/free-api-whatsapp-messages/

Kurz: Nummer als Kontakt speichern, die dort angegebene Freigabe-Nachricht schicken, API-Key per WhatsApp erhalten.

Hinweis: CallMeBot drosselt bei vielen Nachrichten in kurzer Zeit. Beim Testen mit `--report` also nicht im Minutentakt senden.

### 3. Cloudflare API-Token (optional)

1. Cloudflare → Profil → API Tokens → Create Token → **Custom Token**
2. Permission: **Zone → Analytics → Read**
3. Zone Resources: nur die überwachte Domain
4. Token und Zone-ID (Overview-Seite der Domain, rechts unter "API") in `config.php` eintragen

Hinweis zum IP-Filter am Token: Die ausgehende IP des Servers kann von der IP abweichen, die ein Ping zeigt. Bei "Authentication error" im Log testweise ohne IP-Filter versuchen.

Free Plan: Die zusammengefassten Firewall-Statistiken (`firewallEventsAdaptiveGroups`) sind dort nicht verfügbar. Das Script holt darum die einzelnen Ereignisse der letzten 24h und zählt selbst.

### 4. config.php erstellen

`config.example.php` nach `config.php` kopieren, ausfüllen, dann:
```bash
chmod 600 config.php
```

### 5. Cronjob einrichten

crontab:
```
*/5 * * * * php /var/www/vhosts/example.com/monitor/monitor.php
```

Plesk: Geplante Aufgabe vom Typ "PHP-Skript ausführen", Skriptpfad relativ zum Abonnement (z.B. `monitor/monitor.php`), Cron-Stil `*/5 * * * *`.

## Manueller Test

```bash
php monitor.php --report
```
schickt den Tagesbericht sofort.

Achtung in Plesk: Ein gespeichertes Argument `--report` gilt auch für jeden automatischen Lauf. Nach dem Test wieder entfernen.

## Dateien

| Datei | Beschreibung |
|-------|-------------|
| `monitor.php` | Hauptscript |
| `config.example.php` | Vorlage für die Konfiguration |
| `config.php` | Eigene Konfiguration, **nicht in Git** |
| `status.json` | Aktueller Status und Statistik, **nicht in Git** |
| `monitor.log` | Log aller Checks, **nicht in Git** |
| `monitor.lock` | Lock-File, **nicht in Git** |

## Meldungen

| Meldung | Bedeutung |
|-------|-----------|
| ALERT: LANGSAM | Antwortzeit über dem Schwellenwert |
| ALERT: DOWN | Keine Antwort, Timeout oder HTTP 4xx/5xx |
| Netzwerkstörung | Shop **und** Referenzseite nicht erreichbar: Problem liegt beim Monitor |
| wieder normal | Shop antwortet wieder normal, inkl. Dauer |
| Tagesbericht | Tägliche Zusammenfassung inkl. Cloudflare-Zahlen |

## Log-Format

```
2026-10-14 08:00:02;ok;1.43;200;
2026-10-14 00:36:19;down;7.57;0;timed out before SSL handshake
```
Zeitstempel; Status (ok / slow / down / netz); Antwortzeit; HTTP-Code; Fehler
