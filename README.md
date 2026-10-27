# Bahn-Pinneberg.de

Statusseite für Pendler auf den Nordbahn-Linien RB 60, RB 61 und RB 71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist: aktive Störungen (strecken-info.de), die nächsten Züge vom eigenen Bahnhof und zurück (Stationsmonitor der Nordbahn) und die Eilmeldungen der Nordbahn. Live unter https://bahn-pinneberg.de/.

Ein privates Projekt ohne Verbindung zur NBE Nordbahn Eisenbahngesellschaft.

## Aufbau

Das Wurzelverzeichnis des Repos ist das Web-Root. Reines PHP ohne Framework, kein Build-Schritt.

| Pfad | Zweck |
| --- | --- |
| `index.php` | Startseite, rendert Lagekarten, Störungen und Eilmeldungen server-seitig |
| `dashboard.js`, `dashboard.css` | Aktualisiert die Seite im Browser, Abfahrtstafeln, Benachrichtigungen |
| `api/geofox.php`, `api/s3.php`, `dev/geofox.php` | HVV-Schnittstelle Geofox GTI: S3-Abfahrten ab Pinneberg und Hamburg Hbf, HVV-Meldungen zu S3/RB60/RB61/RB71, Statusregel `gf_status`, Cache `cache/geofox.json`; Endpunkt `/api/s3?stadt=altona|stadthausbruecke|jungfernstieg|hbf` (Cache je Stadtbahnhof, Meldungen geteilt); Diagnose der Rohantworten ohne SSH über `/api/s3?roh=<NORDBAHN_PUSH_TOKEN>`; CLI zum Prüfen des Zugangs. Aus, solange `NORDBAHN_GEOFOX_USER`/`NORDBAHN_GEOFOX_PASSWORD` in `config.php` leer sind |
| `aktuell/index.php` | Aktive Meldungen `/aktuell/`: was gerade gilt, ohne Wichtig-Filter und ohne Wegklicken: laufende Infrastrukturstörungen, Baufahrplan, dazu `nb_aktuelle_meldungen`. Ziel der Lagekarten „Infrastruktur“ und „Eilmeldungen“ |
| `news/index.php` | Wichtige Meldungen: Störungen und Eilmeldungen nach den Regeln `nb_wichtig_stoerung`/`nb_wichtig_eilmeldung` in `api/render.php`; die Startseite zeigt nur „Aktuelle Meldungen“ (`nb_aktuelle_meldungen`: Eilmeldungen der letzten vier Stunden, Störungen bis eine Stunde nach Aufhebung, gültige ungeplante HVV-Meldungen) |
| `stoerung.php`, `eilmeldung.php`, `api/ereignis.php` | Ereignisseiten `/stoerung/<key>/` und `/eilmeldung/<id>/`: Verlauf aus dem Logbuch (`verlauf` je Eintrag), betroffene Züge aus dem Zuglog, Eilmeldungen und Störungen im Zeitraum |
| `llms.php`, `lage.php` | `/llms.txt` (Beschreibung für KI-Assistenten) und `/lage.md` (aktuelle Lage als Markdown, `?bhf=` für den Bahnhof) |
| `karte/index.php`, `karte.js`, `api/karte.php`, `karte/strecke.json`, `vendor/leaflet/` | Live-Karte `/karte/`: wo die Züge der RB60, RB61 und RB71 gerade sind. Keine GPS-Ortung: `api/karte` liest die Tafeln aller 15 Bahnhöfe (Hamburg Hbf, Dammtor, Altona und die zwölf Nordbahn-Halte), holt für jeden Zug, der in den nächsten 20 Minuten irgendwo abfährt, den Laufweg mit Prognose je Halt und rechnet aus den Ist-Zeiten, zwischen welchen Halten er ist und wie weit (`ka_lage`). Züge, die ihren letzten Zwischenhalt verlassen haben, bleiben aus dem vorigen Stand bekannt. Cache `cache/karte.json` 60 s, bis 3 Minuten alter Stand sofort mit Erneuerung im Hintergrund. `karte/strecke.json` hält Koordinaten (DS100-Kürzel, aus den DB-Stammdaten) und den Streckenverlauf; `node dev/strecke.js` holt die Gleise von Overpass (OpenStreetMap) und schreibt die Stützpunkte jeder Kante entlang der echten Schienen neu (ohne Netzzugang: `node dev/strecke.js osm.json` mit gespeicherter Overpass-Antwort); `karte.js` zeichnet mit Leaflet (selbst gehostet unter `vendor/leaflet/`) auf OpenStreetMap-Kacheln und bewegt die Züge zwischen zwei Abrufen nach ihren Ist-Zeiten weiter. CLI zum Prüfen gegen gespeicherte XML-Dateien: `php api/karte.php <verzeichnis>` mit `<ID>.xml` je Tafel und `zug-<nummer>.xml` je Laufweg |
| `api/fahrplan.php` | Fahrplan je Bahnhof für die Suche unter „Meine Züge“: `/api/fahrplan?bhf=APD` liefert `hin` und `rueck` mit Zugnummer, Linie, Planzeit, Ziel, Startbahnhof und beobachteten Wochentagen aus dem Zuglog der letzten 14 Tage (`fp_aus_logs`), Cache `cache/fahrplan-<bhf>.json` 30 Min. Varianten desselben Pendelzugs (Baufahrplan, Fahrplanwechsel: andere Nummer, bis 10 Minuten daneben, nie am selben Tag, beide werktags oder beide am Wochenende, `fp_variante`) werden ein Eintrag mit `nummern` und `varianten`. Die Rückrichtung kennt die Seite für alle Bahnhöfe, die Hinrichtung nur für abgefragte Bahnhöfe |
| `api/zuglog.php` | Zuverlässigkeit je Zug: Tageslogs in `cache/zuglog/`, Auswertung der letzten 30 Tage in `cache/zugstat.json` (30 Min. gecacht) für die Statistikseite |
| `api/abfahrten.php` | Nächste Züge aus dem Stationsmonitor, mit Teilausfall-Erkennung. Tafeln und Zugauskünfte parallel per curl_multi, Cache 60 s; ein bis zu 3 Minuten alter Stand wird sofort geliefert und im Hintergrund erneuert |
| `api/push.php` | Nimmt alle 2 Minuten das Datenpaket aus n8n an (Token-geschützt) |
| `api/data.php` | Liefert das letzte Paket an den Browser |
| `api/revision.php` | Holt die aktuelle Revision von strecken-info.de für n8n |
| `api/subscribe.php`, `api/notify.php`, `api/webpush.php` | Web Push: Abos annehmen, Ereignisse erkennen, verschlüsselt versenden (VAPID, aes128gcm, ohne Fremdpakete). „Meine Züge“: gemerkte Züge je Abo (`nb_push_clean_zuege`), Erkennung je Zug in `nb_push_events_meine` |
| `sw.js` | Service Worker, zeigt Push-Nachrichten an |
| `api/feedback.php`, `feedback.js`, `nb_render_feedback()` in `api/render.php` | Feedback-Knopf unten links auf jeder Seite (Footer): Dialog mit Art, Nachricht und optionalem Kontakt, `feedback.js` schickt ihn an `/api/feedback`, der Endpunkt reicht ihn an einen Discord-Webhook weiter. Nur POST vom eigenen Origin, Bot-Filter, Honeypot, fünf Nachrichten je Besucher und Stunde (`cache/feedback/`), `allowed_mentions` leer. Aus, solange `NORDBAHN_FEEDBACK_WEBHOOK` in `config.php` leer ist |
| `api/store.php`, `api/render.php` | Gemeinsame Funktionen, Konfiguration, Bahnhofsliste |
| `faq/`, `changelog/` | Eigene Seiten, verlinkt aus dem Footer und dem Handy-Menü |
| `einstellungen/index.php` | Einstellungen `/einstellungen/` (noindex): Meine Züge mit Bahnhof, Richtung und Suche, Benachrichtigungen (an/aus, Was, Wann, Anleitung für den Home-Bildschirm), gespeicherte Daten mit „Alles löschen“. Erreichbar über die Glocke im Kopf jeder Seite und das Handy-Menü; `dashboard.js` startet dort mit `bootEinstellungen()` ohne Nachladen der Lage |
| `nav.js`, `nb_render_kopf()` in `api/render.php` | Kopfzeile aller Seiten: Bereiche Start, Meldungen, Live-Karte, Statistik, rechts die Glocke als Link zu den Einstellungen (`tool--an`, wenn Benachrichtigungen an sind); unter 720 px hinter einem Menü-Knopf (`<details>`, klappt auch ohne JavaScript), `nav.js` schließt es bei Klick daneben und mit Escape |
| `cache/` | Daten auf dem Server (nicht im Repo, nicht im Deploy) |
| `tests/` | Tests, siehe unten |

Die Daten kommen aus zwei n8n-Workflows: „Nordbahn Dashboard Push“ sammelt Störungen und Eilmeldungen ein und schickt sie an `api/push`; „Strecken-Info Störungen Alert“ formuliert Pendler-Texte und meldet per Telegram, Discord und X.

## Konfiguration

`config.example.php` als `config.php` ins Web-Root kopieren:

- `NORDBAHN_PUSH_TOKEN`: muss mit dem Token in den n8n-Workflows übereinstimmen
- `NORDBAHN_SITE_URL`: öffentliche Adresse, Grundlage für Canonical, Open Graph, Sitemap und robots.txt
- `NORDBAHN_PLAUSIBLE_JS`: Script-URL der Plausible-Site, leer für kein Analytics. Jeder Seitenaufruf trägt die Eigenschaft `heimat` (gewählter Heimatbahnhof oder „Prisdorf (Vorgabe)“), dazu die Ereignisse `Bahnhof` (Wechsel), `Telegram` (Klick), `Zug gemerkt` (aus der Suche unter „Meine Züge“, mit `richtung`) und `Feedback` (abgeschickt, mit `art`). In Plausible unter Properties bzw. Goals auswerten.
- `NORDBAHN_FEEDBACK_WEBHOOK`: Discord-Webhook für das Feedback-Formular, leer für keinen Feedback-Knopf
- `NORDBAHN_VAPID_PUBLIC`, `NORDBAHN_VAPID_PRIVATE`, `NORDBAHN_VAPID_SUBJECT`: Schlüsselpaar für Web Push, erzeugen mit `php dev/vapid.php`; ohne Schlüssel gibt es nur Benachrichtigungen bei geöffnetem Tab
- `NORDBAHN_GOOGLE_VERIFICATION`, `NORDBAHN_BING_VERIFICATION`: Verifizierungs-Codes für Search Console und Bing Webmaster Tools
- `NORDBAHN_IMPRESSUM`: optional, überschreibt die Impressumsangaben Feld für Feld (`name`, `zusatz`, `strasse`, `ort`, `land`, `email`, `hoster`)

`cache/` muss für PHP beschreibbar sein; der Ordner wird beim ersten Push angelegt.

## Web Push

Wer Benachrichtigungen einschaltet, bekommt sie auch bei geschlossener Seite: der Browser registriert `sw.js`, abonniert beim Push-Dienst seines Herstellers und meldet das Abo an `api/subscribe` (Endpunkt, Schlüssel, Heimatbahnhof, Zeitregel). Der Versand hängt am n8n-Push: `api/push.php` antwortet n8n und ruft danach `nb_push_run()` auf, das neue Störungen (sobald der Pendler-Text da ist, spätestens nach sechs Minuten), aufgehobene Störungen, neue Eilmeldungen (ohne stumme) und Zugausfälle am Heimatbahnhof der Abonnenten erkennt und verschlüsselt verschickt. Abos mit 404/410 werden gelöscht.

**Meine Züge:** Unter Einstellungen (`/einstellungen/#meine`) sucht man seinen Zug nach Bahnhof, Richtung und Abfahrtszeit („8:13“, „813“, „RB 71“, „Altona“). Die Vorschläge kommen aus `/api/fahrplan` und der aktuellen Tafel; Varianten stehen mit „sonst 08:17 → Hbf“ dabei. Gemerkt wird im Browser (`localStorage` `nordbahn.meine`: Bahnhof, Richtung, Zugnummer, `nummern` aller Varianten, Linie, Planzeit, Startbahnhof, Ziel, Wochentage), die Liste geht mit dem Abo an `api/subscribe` (höchstens acht Züge, je Zug bis zu fünf Nummern). Nach jedem Laden des Fahrplans gleicht `meineAbgleichen()` die gemerkten Züge ab: neue Varianten kommen zu den Nummern, Zeit und Ziel folgen der zuletzt gesehenen, zwei gemerkte Varianten desselben Zugs werden einer. `nb_push_run()` liest die Tafel jedes Bahnhofs, an dem jemand einen Zug gemerkt hat, findet den Zug über eine seiner Nummern (sonst Linie und Planzeit) und meldet ab einer Stunde vor der Abfahrt Ausfall, Ersatz ab einem anderen Hamburger Bahnhof, Teilausfall, Bus, Verspätung ab 5 Minuten (erneut bei 10, 15, 20, 30, 45, 60) und Gleiswechsel. Gemerkt wird in `push-state.json` je Zug und Tag, was schon gemeldet ist; alle Meldungen zu einem Zug und Tag tragen denselben `tag` und ersetzen sich auf dem Gerät. Diese Meldungen gelten unabhängig von der Zeitregel, nur an den gewählten Wochentagen. Mit „Nur meine Züge“ (`nur` im Abo) entfallen Störungen, Eilmeldungen und Ausfälle am Heimatbahnhof.

## Lokal entwickeln

```
php -S 127.0.0.1:8080 dev/router.php
```

Der Router bildet die Rewrites der `.htaccess` nach. Ohne `config.php` läuft die Seite mit leeren Daten; die Abfahrten kommen live vom Stationsmonitor.

Die Abfahrtslogik lässt sich auch gegen gespeicherte XML-Dateien prüfen:

```
php api/abfahrten.php --bhf=APD heimat.xml hbf.xml altona.xml dammtor.xml [zuege/]
```

Favicon, Logo im Kopf und Webapp-Icons (`favicon.ico`, `icon-*.png`) entstehen aus `dev/icon/zug-front.webp`:

```
python3 dev/icon/build.py   # braucht Pillow
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
