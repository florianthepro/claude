#!/usr/bin/env bash
#
# Nyx — mitmachen.
#
#   ./mitmachen.sh              Knoten und Web-Client starten
#   ./mitmachen.sh demo         nur die Vorfuehrung, ohne Netz
#   ./mitmachen.sh knoten       nur den Knoten
#   ./mitmachen.sh pruefen      Voraussetzungen pruefen
#
# Startet einen Knoten und den Web-Client auf diesem Rechner und legt bei
# Bedarf eine Identitaet an. Danach ist man Teil des Netzes: der eigene
# Knoten speichert Teile fremder Nachrichten, der eigene Client sendet.
#
# Das Skript aendert nichts ausserhalb seines eigenen Ordners und
# installiert nichts.

set -euo pipefail

HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATEN="${NYX_DATEN:-$HIER/daten}"
KNOTEN_PORT="${NYX_PORT:-8443}"
WEB_PORT="${NYX_WEB_PORT:-8080}"
POW="${NYX_POW_BITS:-12}"
BITS="${NYX_BITS:-12}"

rot()  { printf '\033[31m%s\033[0m\n' "$*"; }
grau() { printf '\033[90m%s\033[0m\n' "$*"; }
fett() { printf '\033[1m%s\033[0m\n' "$*"; }

kinder=()
aufraeumen() {
  for pid in "${kinder[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done
}
trap aufraeumen EXIT INT TERM

# -- Voraussetzungen --------------------------------------------------------

pruefe_python() {
  if ! command -v python3 >/dev/null; then
    rot "python3 fehlt. Ohne Python laeuft weder Client noch Vorfuehrung."
    return 1
  fi
  local version
  version="$(python3 -c 'import sys; print("%d.%d" % sys.version_info[:2])')"
  if ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)'; then
    rot "Python $version ist zu alt — gebraucht wird 3.11 oder neuer."
    return 1
  fi
  echo "  python3 $version"
}

pruefe_php() {
  if ! command -v php >/dev/null; then
    grau "  php fehlt — der Knoten laeuft dann in Python statt in PHP."
    return 1
  fi
  if ! php -r 'exit(extension_loaded("sodium") ? 0 : 1);'; then
    grau "  php ohne die Erweiterung sodium — der Knoten laeuft in Python."
    return 1
  fi
  echo "  php $(php -r 'echo PHP_VERSION;') mit sodium"
}

frei() {
  ! (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null
}

# -- Teile ------------------------------------------------------------------

starte_knoten() {
  mkdir -p "$DATEN"
  chmod 700 "$DATEN"

  if pruefe_php >/dev/null 2>&1; then
    NYX_DATA="$DATEN" NYX_POW_BITS="$POW" NYX_BITS="$BITS" \
      php -S "0.0.0.0:$KNOTEN_PORT" "$HIER/nyx-knoten.php" >"$DATEN/knoten.log" 2>&1 &
    kinder+=($!)
    echo "php"
  else
    python3 "$HIER/nyx.py" knoten --host 0.0.0.0 --port "$KNOTEN_PORT" \
      --pow "$POW" --bits "$BITS" >"$DATEN/knoten.log" 2>&1 &
    kinder+=($!)
    echo "python"
  fi
}

warte_auf_knoten() {
  for _ in $(seq 1 40); do
    if command -v curl >/dev/null; then
      curl -sf "http://127.0.0.1:$KNOTEN_PORT/v1/info" >/dev/null && return 0
    else
      python3 - "$KNOTEN_PORT" <<'PY' && return 0
import sys, urllib.request
try:
    urllib.request.urlopen(f"http://127.0.0.1:{sys.argv[1]}/v1/info", timeout=2).read()
except Exception:
    sys.exit(1)
PY
    fi
    sleep 0.5
  done
  return 1
}

starte_web() {
  (cd "$HIER" && python3 -m http.server "$WEB_PORT" --bind 0.0.0.0) >/dev/null 2>&1 &
  kinder+=($!)
}

# -- Befehle ----------------------------------------------------------------

befehl="${1:-start}"

case "$befehl" in
  pruefen)
    fett "Voraussetzungen"
    pruefe_python || exit 1
    pruefe_php || true
    for p in "$KNOTEN_PORT" "$WEB_PORT"; do
      frei "$p" || rot "  Port $p ist belegt."
    done
    echo
    grau "Fuer den Browser-Client wird Chrome ab 133, Firefox ab 130 oder"
    grau "Safari ab 17 gebraucht — aeltere koennen kein X25519 in WebCrypto."
    ;;

  demo)
    exec python3 "$HIER/nyx.py" demo
    ;;

  knoten)
    fett "Knoten"
    art="$(starte_knoten)"
    warte_auf_knoten || { rot "Der Knoten ist nicht hochgekommen. Siehe $DATEN/knoten.log"; exit 1; }
    echo "  Fassung   $art"
    echo "  Adresse   http://$(hostname -I 2>/dev/null | awk '{print $1}'):$KNOTEN_PORT"
    echo "  Daten     $DATEN"
    echo
    grau "Kein Zugriffsprotokoll. Beenden mit Strg-C."
    wait
    ;;

  start|"")
    pruefe_python >/dev/null || exit 1

    fett "Knoten"
    art="$(starte_knoten)"
    warte_auf_knoten || { rot "Der Knoten ist nicht hochgekommen. Siehe $DATEN/knoten.log"; exit 1; }
    echo "  Fassung   $art"
    echo "  Schnittstelle  http://127.0.0.1:$KNOTEN_PORT"

    export NYX_NODE="http://127.0.0.1:$KNOTEN_PORT"
    export NYX_HOME="${NYX_HOME:-$HIER/identitaet}"

    echo
    fett "Identitaet"
    if [ -f "$NYX_HOME/identitaet.json" ]; then
      echo "  $(python3 "$HIER/nyx.py" wer)"
      grau "  (vorhanden, in $NYX_HOME)"
    else
      python3 "$HIER/nyx.py" init | sed 's/^/  /'
    fi

    starte_web
    sleep 1

    echo
    fett "Web-Client"
    echo "  http://127.0.0.1:$WEB_PORT/nyx.html"
    grau "  Beim ersten Start als Knoten http://127.0.0.1:$KNOTEN_PORT eintragen."

    echo
    fett "Kommandozeile"
    echo "  export NYX_NODE=$NYX_NODE NYX_HOME=$NYX_HOME"
    echo "  python3 nyx.py senden <adresse> \"Text\""
    echo "  python3 nyx.py holen"

    echo
    grau "Solange dieses Fenster offen ist, laeuft der Knoten und speichert"
    grau "Teile fremder Nachrichten. Beenden mit Strg-C."
    wait
    ;;

  *)
    sed -n '2,18p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
