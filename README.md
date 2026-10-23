# Nordbahn Monitor

Statusseite für Pendler auf den Nordbahn-Linien RB 61 und RB 71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist: aktive Störungen (strecken-info.de), die nächsten Züge vom eigenen Bahnhof und zurück (Stationsmonitor der Nordbahn) und die Eilmeldungen der Nordbahn. Live unter https://bahn-pinneberg.de/.

Ein privates Projekt ohne Verbindung zur NBE Nordbahn Eisenbahngesellschaft.

## Aufbau

Das Wurzelverzeichnis des Repos ist das Web-Root. Reines PHP ohne Framework, kein Build-Schritt.

| Pfad | Zweck |
| --- | --- |
| `index.php` | Startseite, rendert Lagekarten, Störungen und Eilmeldungen server-seitig |
| `dashboard.js`, `dashboard.css` | Aktualisiert die Seite im Browser, Abfahrtstafeln, Benachrichtigungen |
| `api/geofox.php`, `api/s3.php`, `dev/geofox.php` | HVV-Schnittstelle Geofox GTI: S3-Abfahrten ab Pinneberg und Hamburg Hbf, HVV-Meldungen zu S3/RB61/RB71, Statusregel `gf_status`, Cache `cache/geofox.json`; Endpunkt `/api/s3?stadt=altona|stadthausbruecke|jungfernstieg|hbf` (Cache je Stadtbahnhof, Meldungen geteilt); Diagnose der Rohantworten ohne SSH über `/api/s3?roh=<NORDBAHN_PUSH_TOKEN>`; CLI zum Prüfen des Zugangs. Aus, solange `NORDBAHN_GEOFOX_USER`/`NORDBAHN_GEOFOX_PASSWORD` in `config.php` leer sind |
| `news/index.php` | Wichtige Meldungen: Störungen und Eilmeldungen nach den Regeln `nb_wichtig_stoerung`/`nb_wichtig_eilmeldung` in `api/render.php`; die Startseite zeigt nur „Aktuelle Meldungen“ (`nb_aktuelle_meldungen`: Eilmeldungen der letzten vier Stunden, Störungen bis eine Stunde nach Aufhebung, gültige ungeplante HVV-Meldungen) |
| `stoerung.php`, `eilmeldung.php`, `api/ereignis.php` | Ereignisseiten `/stoerung/<key>/` und `/eilmeldung/<id>/`: Verlauf aus dem Logbuch (`verlauf` je Eintrag), betroffene Züge aus dem Zuglog, Eilmeldungen und Störungen im Zeitraum |
| `llms.php`, `lage.php` | `/llms.txt` (Beschreibung für KI-Assistenten) und `/lage.md` (aktuelle Lage als Markdown, `?bhf=` für den Bahnhof) |
| `api/zuglog.php` | Zuverlässigkeit je Zug: Tageslogs in `cache/zuglog/`, Auswertung der letzten 30 Tage in `cache/zugstat.json` (30 Min. gecacht) für die Statistikseite |
| `api/abfahrten.php` | Nächste Züge aus dem Stationsmonitor, mit Teilausfall-Erkennung. Tafeln und Zugauskünfte parallel per curl_multi, Cache 60 s; ein bis zu 3 Minuten alter Stand wird sofort geliefert und im Hintergrund erneuert |
| `api/push.php` | Nimmt alle 2 Minuten das Datenpaket aus n8n an (Token-geschützt) |
| `api/data.php` | Liefert das letzte Paket an den Browser |
| `api/revision.php` | Holt die aktuelle Revision von strecken-info.de für n8n |
| `api/subscribe.php`, `api/notify.php`, `api/webpush.php` | Web Push: Abos annehmen, Ereignisse erkennen, verschlüsselt versenden (VAPID, aes128gcm, ohne Fremdpakete) |
| `sw.js` | Service Worker, zeigt Push-Nachrichten an |
| `api/store.php`, `api/render.php` | Gemeinsame Funktionen, Konfiguration, Bahnhofsliste |
| `faq/`, `changelog/` | Eigene Seiten, nur aus dem Footer verlinkt |
| `cache/` | Daten auf dem Server (nicht im Repo, nicht im Deploy) |
| `tests/` | Tests, siehe unten |

Die Daten kommen aus zwei n8n-Workflows: „Nordbahn Dashboard Push“ sammelt Störungen und Eilmeldungen ein und schickt sie an `api/push`; „Strecken-Info Störungen Alert“ formuliert Pendler-Texte und meldet per Telegram, Discord und X.

## Konfiguration

`config.example.php` als `config.php` ins Web-Root kopieren:

- `NORDBAHN_PUSH_TOKEN`: muss mit dem Token in den n8n-Workflows übereinstimmen
- `NORDBAHN_SITE_URL`: öffentliche Adresse, Grundlage für Canonical, Open Graph, Sitemap und robots.txt
- `NORDBAHN_PLAUSIBLE_JS`: Script-URL der Plausible-Site, leer für kein Analytics. Jeder Seitenaufruf trägt die Eigenschaft `heimat` (gewählter Heimatbahnhof oder „Prisdorf (Vorgabe)“), dazu die Ereignisse `Bahnhof` (Wechsel) und `Telegram` (Klick). In Plausible unter Properties bzw. Goals auswerten.
- `NORDBAHN_VAPID_PUBLIC`, `NORDBAHN_VAPID_PRIVATE`, `NORDBAHN_VAPID_SUBJECT`: Schlüsselpaar für Web Push, erzeugen mit `php dev/vapid.php`; ohne Schlüssel gibt es nur Benachrichtigungen bei geöffnetem Tab
- `NORDBAHN_GOOGLE_VERIFICATION`, `NORDBAHN_BING_VERIFICATION`: Verifizierungs-Codes für Search Console und Bing Webmaster Tools
- `NORDBAHN_IMPRESSUM`: optional, überschreibt die Impressumsangaben Feld für Feld (`name`, `zusatz`, `strasse`, `ort`, `land`, `email`, `hoster`)

`cache/` muss für PHP beschreibbar sein; der Ordner wird beim ersten Push angelegt.

## Web Push

Wer Benachrichtigungen einschaltet, bekommt sie auch bei geschlossener Seite: der Browser registriert `sw.js`, abonniert beim Push-Dienst seines Herstellers und meldet das Abo an `api/subscribe` (Endpunkt, Schlüssel, Heimatbahnhof, Zeitregel). Der Versand hängt am n8n-Push: `api/push.php` antwortet n8n und ruft danach `nb_push_run()` auf, das neue Störungen (sobald der Pendler-Text da ist, spätestens nach sechs Minuten), aufgehobene Störungen, neue Eilmeldungen (ohne stumme) und Zugausfälle am Heimatbahnhof der Abonnenten erkennt und verschlüsselt verschickt. Abos mit 404/410 werden gelöscht. Auf dem iPhone funktioniert Push nur, wenn die Seite zum Home-Bildschirm hinzugefügt wurde.

## Lokal entwickeln

```
php -S 127.0.0.1:8080 dev/router.php
```

Der Router bildet die Rewrites der `.htaccess` nach. Ohne `config.php` läuft die Seite mit leeren Daten; die Abfahrten kommen live vom Stationsmonitor.

Die Abfahrtslogik lässt sich auch gegen gespeicherte XML-Dateien prüfen:

```
php api/abfahrten.php --bhf=APD heimat.xml hbf.xml altona.xml dammtor.xml [zuege/]
```

Die Vorschaubilder für Messenger und soziale Netze (`og/*.png`) entstehen aus `dev/og/template.html`:

```
node dev/og/build.js     # braucht Playwright mit Chromium
```

## Tests

```
php tests/run.php          # alle
php tests/run.php teil     # nur Tests mit "teil" im Namen
```

Ohne Fremdpakete. Die Tests bauen die XML-Antworten des Stationsmonitors und der Zugauskunft selbst und rechnen mit einer festen Uhrzeit. Auf GitHub laufen sie bei jedem Pull Request und vor jedem Deploy.

## Deploy

Ein Push auf `main` lässt erst die Tests laufen und spiegelt dann das Repo per SFTP ins Web-Root (`.github/workflows/deploy.yml`). Nötige Secrets im Repo: `DEPLOY_HOST` (nur der Hostname), `DEPLOY_USER`, `DEPLOY_PASSWORD`, `DEPLOY_PATH` (Pfad zum Web-Root der Domain, z. B. `/www/htdocs/xyz/bahn-pinneberg.de`). `config.php` und `cache/` bleiben vom Deploy unberührt.

Achtung: der Mirror läuft mit `--delete`, alles im Zielverzeichnis, was nicht im Repo ist, wird gelöscht. Der Workflow prüft deshalb vorher, ob `DEPLOY_PATH` auf ein Home-Verzeichnis zeigt, und bricht dann ab.
