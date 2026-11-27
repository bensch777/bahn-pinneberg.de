# Leaflet 1.9.4

Unveraenderte Kopie von `dist/` aus dem npm-Paket `leaflet@1.9.4` (BSD-2-Clause, siehe LICENSE),
nur das Script, das Stylesheet und die Bilder. Die Karte unter /karte/ laedt sie von hier,
damit die Content-Security-Policy keine fremden Script-Hosts braucht.

Aktualisieren: `npm pack leaflet@<version>`, Dateien aus `package/dist/` hierher kopieren,
Version in karte/index.php (Query-String `?v=`) anpassen.
