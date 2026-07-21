#!/usr/bin/env bash
#
# crack.sh — Educational Wi-Fi security auditing launcher
# ---------------------------------------------------------------------------
# Walks you from a fresh Kali Linux install all the way to "you are on the
# network", driven from a simple local web interface at http://crack-wifi.local
#
# It is a friendly wrapper around the standard aircrack-ng suite (airmon-ng,
# airodump-ng, aireplay-ng, aircrack-ng) plus optional reaver/bully/hashcat.
#
# ############################################################################
# #  LEGAL / ETHICS                                                          #
# #  Only test networks that YOU OWN or that you have EXPLICIT WRITTEN       #
# #  permission to audit. Attacking networks you do not control is illegal   #
# #  in most countries. This tool exists for learning and authorized         #
# #  penetration testing only. You are responsible for how you use it.       #
# ############################################################################
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration & defaults
# ---------------------------------------------------------------------------
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOSTNAME_LOCAL="crack-wifi.local"
BIND_HOST="127.0.0.1"
PORT="8777"
MODE="auto"                 # auto | real | demo
MANAGE_DNS="1"              # add crack-wifi.local -> 127.0.0.1 to /etc/hosts
ASSUME_YES="0"
OPEN_BROWSER="1"
SKIP_SETUP="0"             # if 1, never auto-install missing dependencies
WORDLIST_DEFAULT="/usr/share/wordlists/rockyou.txt"
HOSTS_FILE="/etc/hosts"
HOSTS_MARKER="# added-by-crack.sh"
SERVER_PID=""

# Colours (fall back to nothing if not a tty)
if [[ -t 1 ]]; then
  C_RESET='\033[0m'; C_BOLD='\033[1m'; C_DIM='\033[2m'
  C_RED='\033[31m'; C_GRN='\033[32m'; C_YEL='\033[33m'; C_BLU='\033[34m'; C_CYA='\033[36m'
else
  C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_GRN=''; C_YEL=''; C_BLU=''; C_CYA=''
fi

log()  { printf '%b\n' "${C_CYA}[*]${C_RESET} $*"; }
ok()   { printf '%b\n' "${C_GRN}[+]${C_RESET} $*"; }
warn() { printf '%b\n' "${C_YEL}[!]${C_RESET} $*"; }
err()  { printf '%b\n' "${C_RED}[x]${C_RESET} $*" >&2; }
step() { printf '\n%b\n' "${C_BOLD}${C_BLU}==> $*${C_RESET}"; }

usage() {
  cat <<EOF
${C_BOLD}crack.sh${C_RESET} — educational Wi-Fi auditing launcher

Usage: sudo ./crack.sh [options]

Options:
  --demo            Force demo mode (fake networks, no hardware needed, safe to
                    run anywhere — great for learning the workflow / the UI).
  --real            Force real mode (requires root + aircrack-ng + a Wi-Fi card
                    that supports monitor mode).
  --port <n>        Web UI port (default: ${PORT}).
  --host <ip>       Bind address for the web server (default: ${BIND_HOST}).
  --wordlist <f>    Default wordlist for dictionary attacks
                    (default: ${WORDLIST_DEFAULT}).
  --no-dns          Do NOT touch /etc/hosts; use http://${BIND_HOST}:PORT instead
                    of http://${HOSTNAME_LOCAL}:PORT.
  --no-browser      Do not try to auto-open a browser.
  --skip-setup      Do NOT auto-install missing dependencies (setup.sh).
  -y, --yes         Skip the interactive authorization prompt (you still accept
                    the terms — use only in automation / labs you own).
  -h, --help        Show this help.

The interface will be available at:
  http://${HOSTNAME_LOCAL}:${PORT}   (or http://${BIND_HOST}:${PORT} with --no-dns)
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --demo) MODE="demo"; shift ;;
    --real) MODE="real"; shift ;;
    --port) PORT="${2:?}"; shift 2 ;;
    --host) BIND_HOST="${2:?}"; shift 2 ;;
    --wordlist) WORDLIST_DEFAULT="${2:?}"; shift 2 ;;
    --no-dns) MANAGE_DNS="0"; shift ;;
    --no-browser) OPEN_BROWSER="0"; shift ;;
    --skip-setup) SKIP_SETUP="1"; shift ;;
    -y|--yes) ASSUME_YES="1"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) err "Unknown option: $1"; usage; exit 1 ;;
  esac
done

# ---------------------------------------------------------------------------
# Banner + authorization gate
# ---------------------------------------------------------------------------
banner() {
  printf '%b' "${C_BOLD}${C_CYA}"
  cat <<'EOF'
   ___                _     __        ___ _____ _
  / __\ __ __ _  ___ | | __ \ \      / (_)  ___(_)
 / / | '__/ _` |/ __|| |/ /  \ \ /\ / /| | |_  | |
/ /__| | | (_| | (__ |   <    \ V  V / | |  _| | |
\____/_|  \__,_|\___||_|\_\    \_/\_/  |_|_|   |_|
        educational wi-fi security auditing
EOF
  printf '%b\n' "${C_RESET}"
}

authorize() {
  printf '%b\n' "${C_YEL}${C_BOLD}"
  cat <<'EOF'
  ------------------------------------------------------------------
   AUTHORIZED USE ONLY
   Only audit Wi-Fi networks you own or have written permission to
   test. Unauthorized access to networks is a crime in most places.
   This tool is for learning and authorized penetration testing.
  ------------------------------------------------------------------
EOF
  printf '%b' "${C_RESET}"
  if [[ "$ASSUME_YES" == "1" ]]; then
    warn "Authorization auto-accepted via --yes."
    return 0
  fi
  read -r -p "Type 'I AGREE' to confirm you are authorized: " reply
  if [[ "$reply" != "I AGREE" ]]; then
    err "Authorization not given. Exiting."
    exit 1
  fi
  ok "Authorization confirmed."
}

# ---------------------------------------------------------------------------
# Environment detection
# ---------------------------------------------------------------------------
have() { command -v "$1" >/dev/null 2>&1; }

# Auto-install missing dependencies via setup.sh (on-board apt) so a single
# command is all the user ever needs. Skipped in demo mode or with --skip-setup.
maybe_setup() {
  [[ "$MODE" == "demo" ]] && return 0
  [[ "$SKIP_SETUP" == "1" ]] && return 0

  local missing=()
  for t in python3 iw airmon-ng airodump-ng aireplay-ng aircrack-ng; do
    have "$t" || missing+=("$t")
  done
  have xdg-open || missing+=("xdg-open")   # so the browser can auto-open

  [[ ${#missing[@]} -eq 0 ]] && return 0

  warn "Missing dependencies: ${missing[*]}"
  log  "Running setup.sh to install them automatically (on-board apt)..."
  if bash "${HERE}/setup.sh"; then
    ok "Setup finished."
  else
    warn "Setup could not install everything — continuing (may fall back to DEMO)."
  fi
}

detect_mode() {
  if [[ "$MODE" == "demo" ]]; then
    ok "Running in ${C_BOLD}DEMO${C_RESET} mode (simulated networks)."
    return
  fi

  local missing=()
  for t in airmon-ng airodump-ng aireplay-ng aircrack-ng; do
    have "$t" || missing+=("$t")
  done

  if [[ "$MODE" == "real" ]]; then
    if [[ ${#missing[@]} -gt 0 ]]; then
      err "Real mode requested but still missing tools: ${missing[*]}"
      err "Try:  sudo ./setup.sh   (or: sudo apt install -y aircrack-ng)"
      exit 1
    fi
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
      err "Real mode needs root. Re-run with: sudo ./crack.sh --real"
      exit 1
    fi
    ok "Running in ${C_BOLD}REAL${C_RESET} mode."
    return
  fi

  # auto
  if [[ ${#missing[@]} -eq 0 && "${EUID:-$(id -u)}" -eq 0 ]]; then
    MODE="real"
    ok "aircrack-ng found and running as root -> ${C_BOLD}REAL${C_RESET} mode."
  else
    MODE="demo"
    if [[ ${#missing[@]} -gt 0 ]]; then
      warn "Missing tools (${missing[*]}) -> falling back to ${C_BOLD}DEMO${C_RESET} mode."
    else
      warn "Not running as root -> falling back to ${C_BOLD}DEMO${C_RESET} mode."
    fi
    log "For real audits, run as root (deps auto-install on first run):"
    log "  sudo ./crack.sh"
  fi
}

# ---------------------------------------------------------------------------
# Local DNS (crack-wifi.local -> 127.0.0.1) via /etc/hosts
# ---------------------------------------------------------------------------
setup_dns() {
  [[ "$MANAGE_DNS" == "1" ]] || { UI_HOST="$BIND_HOST"; return; }

  if [[ ! -w "$HOSTS_FILE" ]]; then
    warn "Cannot write ${HOSTS_FILE} (need root). Using ${BIND_HOST} instead."
    MANAGE_DNS="0"; UI_HOST="$BIND_HOST"; return
  fi

  if grep -q "$HOSTNAME_LOCAL" "$HOSTS_FILE" 2>/dev/null; then
    log "${HOSTNAME_LOCAL} already present in ${HOSTS_FILE}."
  else
    printf '%s\t%s %s\n' "127.0.0.1" "$HOSTNAME_LOCAL" "$HOSTS_MARKER" >> "$HOSTS_FILE"
    ok "Added ${HOSTNAME_LOCAL} -> 127.0.0.1 to ${HOSTS_FILE} (temporary)."
  fi
  UI_HOST="$HOSTNAME_LOCAL"
}

teardown_dns() {
  [[ "$MANAGE_DNS" == "1" ]] || return 0
  [[ -w "$HOSTS_FILE" ]] || return 0
  if grep -q "$HOSTS_MARKER" "$HOSTS_FILE" 2>/dev/null; then
    # Remove only the lines we added.
    local tmp; tmp="$(mktemp)"
    grep -v "$HOSTS_MARKER" "$HOSTS_FILE" > "$tmp" && cat "$tmp" > "$HOSTS_FILE"
    rm -f "$tmp"
    ok "Removed ${HOSTNAME_LOCAL} from ${HOSTS_FILE}."
  fi
}

# ---------------------------------------------------------------------------
# Cleanup on exit
# ---------------------------------------------------------------------------
cleanup() {
  local code=$?
  echo
  step "Cleaning up"
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    ok "Stopped web server."
  fi
  teardown_dns
  # Best-effort: return any monitor interfaces to managed mode in real mode.
  if [[ "$MODE" == "real" ]] && have airmon-ng; then
    for i in $(iw dev 2>/dev/null | awk '/Interface/{print $2}' | grep -E 'mon$' || true); do
      airmon-ng stop "$i" >/dev/null 2>&1 || true
      ok "Disabled monitor interface $i."
    done
  fi
  ok "Done. Stay legal."
  exit "$code"
}
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------------------
# Python backend
# ---------------------------------------------------------------------------
find_python() {
  for p in python3 python; do have "$p" && { echo "$p"; return; }; done
  err "Python 3 is required but not found. Install: sudo apt install -y python3"
  exit 1
}

start_server() {
  local py; py="$(find_python)"
  step "Starting web interface"
  log "mode=${MODE} host=${BIND_HOST} port=${PORT}"

  CRACK_MODE="$MODE" \
  CRACK_WORDLIST="$WORDLIST_DEFAULT" \
  CRACK_WEBROOT="${HERE}/web" \
  "$py" "${HERE}/server/server.py" --host "$BIND_HOST" --port "$PORT" &
  SERVER_PID=$!

  # Wait for the port to come up.
  local tries=0
  until "$py" - "$BIND_HOST" "$PORT" <<'PYEOF' 2>/dev/null
import socket, sys
s = socket.socket(); s.settimeout(0.5)
try:
    s.connect((sys.argv[1], int(sys.argv[2]))); sys.exit(0)
except Exception:
    sys.exit(1)
PYEOF
  do
    tries=$((tries+1))
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
      err "Web server failed to start."; exit 1
    fi
    [[ $tries -gt 40 ]] && { err "Timed out waiting for web server."; exit 1; }
    sleep 0.25
  done
  ok "Web server is up (pid ${SERVER_PID})."
}

open_browser() {
  [[ "$OPEN_BROWSER" == "1" ]] || return 0
  local url="http://${UI_HOST}:${PORT}"
  local opener=""
  for o in xdg-open sensible-browser x-www-browser firefox firefox-esr chromium google-chrome open; do
    if have "$o"; then opener="$o"; break; fi
  done
  if [[ -n "$opener" ]]; then
    log "Opening your browser (${opener})..."
    ( "$opener" "$url" >/dev/null 2>&1 & )
  else
    warn "Couldn't find a browser to open automatically — click the link below."
  fi
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
banner
authorize
step "Detecting environment"
maybe_setup
detect_mode
setup_dns
start_server
open_browser

URL="http://${UI_HOST}:${PORT}"
printf '\n%b\n' "${C_GRN}${C_BOLD}  Open the interface:  ${URL}${C_RESET}"
printf '%b\n'   "${C_DIM}  (Press Ctrl+C to stop the server and clean up.)${C_RESET}\n"

# Keep the launcher alive until the server dies or the user interrupts.
wait "$SERVER_PID"
