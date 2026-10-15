# Nordbahn Monitor

Statusseite für Pendler auf den Nordbahn-Linien RB 61 und RB 71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist: aktive Störungen (strecken-info.de), die nächsten Züge vom eigenen Bahnhof und zurück (Stationsmonitor der Nordbahn) und die Eilmeldungen der Nordbahn. Live unter https://bahn-pinneberg.de/.

Ein privates Projekt ohne Verbindung zur NBE Nordbahn Eisenbahngesellschaft.

## Aufbau

Das Wurzelverzeichnis des Repos ist das Web-Root. Reines PHP ohne Framework, kein Build-Schritt.

| Pfad | Zweck |
| --- | --- |
| `index.php` | Startseite, rendert Lagekarten, Störungen und Eilmeldungen server-seitig |
| `dashboard.js`, `dashboard.css` | Aktualisiert die Seite im Browser, Abfahrtstafeln, Benachrichtigungen |
| `api/abfahrten.php` | Nächste Züge aus dem Stationsmonitor, mit Teilausfall-Erkennung |
| `api/push.php` | Nimmt alle 2 Minuten das Datenpaket aus n8n an (Token-geschützt) |
| `api/data.php` | Liefert das letzte Paket an den Browser |
| `api/revision.php` | Holt die aktuelle Revision von strecken-info.de für n8n |
| `api/store.php`, `api/render.php` | Gemeinsame Funktionen, Konfiguration, Bahnhofsliste |
| `faq/`, `changelog/` | Eigene Seiten, nur aus dem Footer verlinkt |
| `cache/` | Daten auf dem Server (nicht im Repo, nicht im Deploy) |
| `tests/` | Tests, siehe unten |

Die Daten kommen aus zwei n8n-Workflows: „Nordbahn Dashboard Push“ sammelt Störungen und Eilmeldungen ein und schickt sie an `api/push`; „Strecken-Info Störungen Alert“ formuliert Pendler-Texte und meldet per Telegram, Discord und X.

## Konfiguration

`config.example.php` als `config.php` ins Web-Root kopieren:

- `NORDBAHN_PUSH_TOKEN`: muss mit dem Token in den n8n-Workflows übereinstimmen
- `NORDBAHN_SITE_URL`: öffentliche Adresse, Grundlage für Canonical, Open Graph, Sitemap und robots.txt
- `NORDBAHN_PLAUSIBLE_JS`: Script-URL der Plausible-Site, leer für kein Analytics
- `NORDBAHN_GOOGLE_VERIFICATION`, `NORDBAHN_BING_VERIFICATION`: Verifizierungs-Codes für Search Console und Bing Webmaster Tools
- `NORDBAHN_IMPRESSUM`: optional, überschreibt die Impressumsangaben Feld für Feld (`name`, `zusatz`, `strasse`, `ort`, `land`, `email`, `hoster`)

`cache/` muss für PHP beschreibbar sein; der Ordner wird beim ersten Push angelegt.

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
