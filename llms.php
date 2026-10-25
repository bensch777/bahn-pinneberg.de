<?php
/**
 * /llms.txt: Kurzbeschreibung der Seite fuer KI-Assistenten und Crawler nach dem
 * llms.txt-Vorschlag (Markdown: Titel, Zusammenfassung, Abschnitte mit Links).
 */

require __DIR__ . '/api/store.php';

$site = nordbahn_site_url();
$bahnhoefe = [];
foreach (NORDBAHN_BAHNHOEFE as $id => $bhf) {
    $bahnhoefe[] = $id . ' = ' . $bhf['name'];
}

header('Content-Type: text/markdown; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');

echo "# Bahn-Pinneberg.de\n\n";
echo "> Statusseite für Pendler auf den Nordbahn-Linien RB61 (Itzehoe – Hamburg Hbf), RB71 (Wrist – Hamburg Hbf) und RB60 (Itzehoe – Hamburg-Altona) zwischen Hamburg, Pinneberg, Elmshorn, Itzehoe und Wrist: aktive Infrastrukturstörungen, die nächsten Züge vom eigenen Bahnhof nach Hamburg und zurück, Eilmeldungen der Nordbahn. Privates Projekt, keine offizielle Seite der Nordbahn (NBE Nordbahn Eisenbahngesellschaft).\n\n";
echo "Die Daten werden alle zwei Minuten (Störungen, Eilmeldungen) bzw. jede Minute (Abfahrten) aktualisiert. Zeiten sind Ortszeit Europe/Berlin. Verbindliche Auskünfte gibt nur die Nordbahn.\n\n";

echo "## Aktuelle Lage als Text\n\n";
echo "- [Lage als Markdown](" . $site . "/lage.md): Status, aktive Störungen, nächste Züge, letzte Eilmeldungen, zuletzt aufgehobene Störungen. Mit `?bhf=KÜRZEL` für einen anderen Heimatbahnhof, z. B. `" . $site . "/lage.md?bhf=AEL`.\n";
echo "- Bahnhofskürzel: " . implode(', ', $bahnhoefe) . ". Hamburg: AH = Hamburg Hbf, ADF = Hamburg Dammtor, AA = Hamburg-Altona.\n\n";

echo "## Daten als JSON\n\n";
echo "- [api/data](" . $site . "/api/data): Lagebild mit `stoerungen` (aktive Infrastrukturstörungen mit `titel`, `text`, `ki_titel`, `ki_text` in Alltagssprache, `beginn`, `ende`, `abgelaufen`), `eilmeldungen_live` (aktuell auf nordbahn.de), `eilmeldungen` (Historie) und `stoerungen_verlauf` (Logbuch mit `first_seen`, `last_seen`).\n";
echo "- [api/abfahrten?bhf=APD](" . $site . "/api/abfahrten?bhf=APD): nächste Züge in `richtungen.hin` (Heimat → Hamburg) und `richtungen.rueck` (Hamburg → Heimat). Je Zug `zeit` (ISO 8601, mit Verspätung), `verspaetung` (Minuten), `linie`, `ziel`, `gleis`, `gleis_plan`, `ausfall`, `hinweis`, `ab` (Hbf, Dammtor oder Altona), `teil` (Teilausfall: `art` endet/beginnt mit `name`), `ersatz` (Ersatzabfahrt bei Ausfall).\n";
echo "- [api/s3](" . $site . "/api/s3): S-Bahn S3 zwischen Pinneberg und Hamburg Hbf aus der HVV-Schnittstelle (Geofox): `s3.level`, `s3.title`, `s3.detail` (ok, warn, alert), `s3.abfahrten` (zeit, ist, ziel, ab, delay_min, ausfall, gleis), `s3.meldungen` (HVV-Meldungen zu S3, RB60, RB61 und RB71 mit titel, text, geplant, aktiv, von, bis, linien). `ok` ist false, wenn die Schnittstelle nicht eingerichtet oder nicht erreichbar ist.\n";
echo "- [api/karte](" . $site . "/api/karte): Zugpositionen für die Live-Karte, berechnet aus dem Stationsmonitor (keine GPS-Ortung): `zuege[]` mit `zug`, `linie`, `von`, `nach`, `richtung` (nord, hamburg), `status` (wartet, halt, unterwegs, ziel), `verspaetung` (Minuten am nächsten Halt), `hinweis`, `lage` (`von`/`nach` oder `bei` mit Zeiten, `anteil` 0–1 zwischen den Halten, `lat`, `lon`, `text`) und `halte[]` (id, name, an, ab, an_ist, ab_ist, verspaetung, ausfall, gleis).\n";
echo "- Alle Endpunkte sind öffentlich, ohne Schlüssel, nur lesend. Bitte nicht häufiger als einmal pro Minute abrufen.\n\n";

echo "## Seiten\n\n";
echo "- [Startseite](" . $site . "/): Lagebild, Abfahrtstafel, letzte Meldungen (JavaScript für Live-Aktualisierung).\n";
echo "- [Häufige Fragen](" . $site . "/faq/): Datenquellen, Bedeutung der Statusstufen, Bahnhöfe, Benachrichtigungen.\n";
echo "- [Alle Eilmeldungen](" . $site . "/eilmeldungen/) und [alle Infrastrukturstörungen](" . $site . "/stoerungen/): Archiv nach Tagen, 50 Einträge je Seite (`?seite=N`).\n";
echo "- Ereignisseiten: `" . $site . "/stoerung/<key>/` (z. B. BZI_33570) mit Pendler-Text, Originalmeldung, Verlauf, betroffenen Zügen und Eilmeldungen im Zeitraum; `" . $site . "/eilmeldung/<id>/` (z. B. em-123456) je Eilmeldung. lage.md und die Archive verlinken darauf.\n";
echo "- [Wichtige Meldungen](" . $site . "/news/): nur die Störungen mit schwerer Einschränkung, Ausfällen, Umleitungen oder Ersatzverkehr und die Eilmeldungen zu Ersatzverkehr, Sperrungen, Bauarbeiten oder mehreren Zügen; einzelne kurzfristige Zugausfälle fehlen hier bewusst.\n";
echo "- [Live-Karte](" . $site . "/karte/): wo die Züge gerade sind; der Kartenausschnitt folgt dem Heimatbahnhof und lässt sich wechseln; Positionen gerechnet aus Laufweg und Prognose, nicht geortet.\n";
echo "- [Statistik](" . $site . "/statistik/): Pünktlichkeit je Zug und Bahnhof (Filter bhf, richtung=hin|rueck, tage=7|14|30|60, zeit=morgens|abends, werktage=1) und Infrastrukturstörungen mit Ursachen (ansicht=strecke).\n";
echo "- [Was ist neu](" . $site . "/changelog/): Änderungen der Seite.\n\n";

echo "## Begriffe\n\n";
echo "- Infrastrukturstörung: Meldung der DB InfraGO von strecken-info.de für den Abschnitt Hamburg – Elmshorn – Itzehoe/Wrist (Signale, Weichen, Oberleitung, Personen im Gleis). `abgelaufen` heißt: das gemeldete Ende ist vorbei, die Störung dauert aber an.\n";
echo "- Eilmeldung: Kurzmeldung der Nordbahn auf nordbahn.de, meist Zugausfälle wegen Personal oder Fahrzeugen. `ki_titel`/`ki_text` sind von einem Sprachmodell formulierte Pendler-Fassungen, der Originaltitel steht in `title`.\n";
echo "- „nur bis Pinneberg“ / „erst ab Pinneberg“: Teilausfall, der Zug fährt nur einen Teil des Weges; ab Pinneberg fährt die S-Bahn nach Hamburg.\n";
echo "- Quellen: Stationsmonitor der Nordbahn (Abfahrten), nordbahn.de (Eilmeldungen), strecken-info.de (Störungen), HVV-Schnittstelle Geofox (S-Bahn S3, HVV-Meldungen; nur wenn eingerichtet). Betreiber und Kontakt: " . $site . "/impressum/\n";
