#!/usr/bin/env bash
# Deploy per SFTP: uebertraegt nur, was sich seit dem letzten Deploy geaendert hat.
#
# Der zuletzt ausgerollte Commit steht auf dem Server in cache/.deploy-rev
# (cache/ ist von aussen gesperrt und wird vom Mirror nicht angefasst). Aus
# "git diff <alt> HEAD" entsteht die Liste der Dateien zum Hochladen und zum
# Loeschen. Die Marke wird erst ganz am Ende geschrieben: bricht ein Lauf ab,
# holt der naechste alles nach.
#
# Voller Abgleich (lftp mirror --delete, wie frueher) laeuft, wenn
#   - DEPLOY_VOLL=1 gesetzt ist (Workflow von Hand mit "voll" gestartet),
#   - die Marke fehlt oder auf einen Commit zeigt, den das Repo nicht kennt,
#   - ein Pfad Zeichen enthaelt, die sich in lftp nicht sicher quoten lassen.
#
# Zugangsdaten gehen per -u und nicht in der URL mit, damit Sonderzeichen im
# Passwort (: @ /) die Adresse nicht zerlegen. DEPLOY_HOST ist nur der
# Hostname, DEPLOY_PATH der absolute Pfad zum Web-Root. DEPLOY_OPEN ersetzt
# die Verbindung fuer lokale Tests (z. B. "open file:///").
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH fehlt}"
ZIEL="${DEPLOY_PATH%/}"
MARKE="$ZIEL/cache/.deploy-rev"
OPEN="${DEPLOY_OPEN:-open -u \"${DEPLOY_USER:-}\",\"${DEPLOY_PASSWORD:-}\" \"sftp://${DEPLOY_HOST:-}\"}"
KOPF="set sftp:auto-confirm yes; set xfer:make-backup no; set xfer:clobber yes; set net:max-retries 3; set net:reconnect-interval-base 5; $OPEN"

# Was nie auf den Server gehoert bzw. dort nie angefasst wird. Dieselbe Liste
# gilt fuer den vollen Abgleich und fuer den Diff.
AUSNAHMEN=(
  '^\.git/' '^\.github/' '^\.claude/' '^\.gitignore$' '^tests/' '^dev/'
  '^config\.php$' '^cache/' '^v2/' '^README\.md$' '^php-error\.log$' '^error_log$'
)
AUSNAHMEN_RE="$(IFS='|'; echo "${AUSNAHMEN[*]}")"

NEU="$(git rev-parse HEAD)"

marke_schreiben() {
  echo "$NEU" > "$RUNNER_TEMP_DIR/.deploy-rev"
  # cache/ legt sonst erst die Anwendung an; beim allerersten Deploy fehlt es.
  lftp -c "$KOPF; mkdir -p -f \"$ZIEL/cache\"; put \"$RUNNER_TEMP_DIR/.deploy-rev\" -o \"$MARKE\"; bye"
}

voll() {
  echo "Voller Abgleich: $1"
  local excl=() a
  for a in "${AUSNAHMEN[@]}"; do excl+=("--exclude='$a'"); done
  # v2/ ist der Rest einer Design-Vorschau, den SFTP nicht loeschen kann
  # (liefert 403); ohne Ausnahme bricht der Mirror mit "Directory is not
  # empty" ab.
  lftp -c "$KOPF; mirror --reverse --delete --verbose ${excl[*]} ./ \"$ZIEL\"; bye"
  marke_schreiben
  exit 0
}

RUNNER_TEMP_DIR="$(mktemp -d)"
trap 'rm -rf "$RUNNER_TEMP_DIR"' EXIT

[ "${DEPLOY_VOLL:-}" = "1" ] && voll "von Hand angefordert"

ALT="$(lftp -c "$KOPF; cat \"$MARKE\"; bye" 2>/dev/null | tr -d '[:space:]' || true)"
[[ "$ALT" =~ ^[0-9a-f]{40}$ ]] || voll "keine Marke auf dem Server"
git cat-file -e "$ALT^{commit}" 2>/dev/null || voll "Commit $ALT der Marke ist im Repo unbekannt"

if [ "$ALT" = "$NEU" ]; then
  echo "Server steht bereits auf $NEU, nichts zu tun."
  exit 0
fi

# Diff einlesen: je Eintrag Status und Pfad, NUL-getrennt. --no-renames macht
# aus einer Umbenennung ein D und ein A.
SKRIPT="$RUNNER_TEMP_DIR/deploy.lftp"
HOCH=(); WEG=()
while IFS= read -r -d '' status && IFS= read -r -d '' pfad; do
  [[ "$pfad" =~ $AUSNAHMEN_RE ]] && continue
  case "$pfad" in *[\"\'\\\$\`]*|*$'\n'*) voll "Pfad mit Sonderzeichen: $pfad";; esac
  case "$status" in
    D) WEG+=("$pfad");;
    *) HOCH+=("$pfad");;
  esac
done < <(git diff --name-status --no-renames -z "$ALT" "$NEU")

echo "Diff $ALT..$NEU: ${#HOCH[@]} hochladen, ${#WEG[@]} loeschen"
if [ $(( ${#HOCH[@]} + ${#WEG[@]} )) -gt 0 ]; then
  {
    echo "set cmd:fail-exit yes"
    # Ordner zuerst, dann Dateien, zuletzt Loeschungen: so zeigt keine neue
    # Seite kurzzeitig auf eine Datei, die noch fehlt.
    for p in "${HOCH[@]}"; do dirname "$p"; done | sort -u | while IFS= read -r d; do
      [ "$d" = "." ] || echo "mkdir -p -f \"$ZIEL/$d\""
    done
    # .htaccess zuletzt: ihre Regeln zeigen womoeglich auf Dateien, die erst in
    # diesem Lauf hochkommen
    for p in "${HOCH[@]}"; do [ "$p" = ".htaccess" ] || echo "put \"$p\" -o \"$ZIEL/$p\""; done
    for p in "${HOCH[@]}"; do [ "$p" = ".htaccess" ] && echo "put \"$p\" -o \"$ZIEL/$p\""; done
    for p in "${WEG[@]}"; do echo "rm -f \"$ZIEL/$p\""; done
    echo "bye"
  } > "$SKRIPT"
  sed "s|$ZIEL/||" "$SKRIPT"
  lftp -c "$KOPF; source \"$SKRIPT\""

  # Ordner, die es im Repo nicht mehr gibt, auf dem Server abraeumen (tiefste
  # zuerst). rmdir nimmt nur leere Ordner; liegt dort noch etwas, bleibt er
  # stehen und der Deploy laeuft trotzdem durch.
  LEER="$(for p in "${WEG[@]}"; do
    d="$(dirname "$p")"
    while [ "$d" != "." ] && [ ! -d "$d" ]; do echo "$d"; d="$(dirname "$d")"; done
  done | sort -ur)"
  if [ -n "$LEER" ]; then
    lftp -c "$KOPF; $(while IFS= read -r d; do printf 'rmdir -f "%s/%s"; ' "$ZIEL" "$d"; done <<< "$LEER") bye" || true
  fi
fi

marke_schreiben
echo "Server steht jetzt auf $NEU."
