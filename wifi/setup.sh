#!/usr/bin/env bash
#
# setup.sh — install everything crack.sh needs, using on-board means (apt).
#
# On Kali / Debian / Ubuntu this installs any missing dependency automatically.
# It is idempotent: run it as often as you like, it only installs what's absent.
# crack.sh calls this for you when it notices something is missing, so you
# normally never run it by hand.
#
set -euo pipefail

# Colours (fall back to nothing if not a tty)
if [[ -t 1 ]]; then
  C_RESET='\033[0m'; C_BOLD='\033[1m'
  C_GRN='\033[32m'; C_YEL='\033[33m'; C_RED='\033[31m'; C_CYA='\033[36m'
else
  C_RESET=''; C_BOLD=''; C_GRN=''; C_YEL=''; C_RED=''; C_CYA=''
fi
log()  { printf '%b\n' "${C_CYA}[*]${C_RESET} $*"; }
ok()   { printf '%b\n' "${C_GRN}[+]${C_RESET} $*"; }
warn() { printf '%b\n' "${C_YEL}[!]${C_RESET} $*"; }
err()  { printf '%b\n' "${C_RED}[x]${C_RESET} $*" >&2; }

have() { command -v "$1" >/dev/null 2>&1; }

# Map required command -> apt package that provides it.
# aircrack-ng ships airmon-ng / airodump-ng / aireplay-ng / aircrack-ng.
declare -A PKG=(
  [airodump-ng]="aircrack-ng"
  [aircrack-ng]="aircrack-ng"
  [aireplay-ng]="aircrack-ng"
  [airmon-ng]="aircrack-ng"
  [iw]="iw"
  [reaver]="reaver"          # WPS Pixie-Dust (optional but recommended)
  [crunch]="crunch"          # bruteforce keyspace generator (optional)
  [python3]="python3"        # runs the web server
  [xdg-open]="xdg-utils"     # so we can auto-open your browser
)

# Order matters only for readability of the summary.
REQUIRED=(python3 iw airmon-ng airodump-ng aireplay-ng aircrack-ng xdg-open)
OPTIONAL=(reaver crunch)

need_sudo() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    if have sudo; then echo "sudo"; else echo ""; fi
  else
    echo ""
  fi
}

collect_missing() {
  local -n _out=$1; shift
  _out=()
  local pkgs_seen=""
  for cmd in "$@"; do
    if ! have "$cmd"; then
      local p="${PKG[$cmd]:-$cmd}"
      case " $pkgs_seen " in
        *" $p "*) : ;;                     # already queued this package
        *) _out+=("$p"); pkgs_seen="$pkgs_seen $p" ;;
      esac
    fi
  done
}

main() {
  printf '%b\n' "${C_BOLD}${C_CYA}== crack-wifi setup ==${C_RESET}"

  local missing_req=() missing_opt=()
  collect_missing missing_req "${REQUIRED[@]}"
  collect_missing missing_opt "${OPTIONAL[@]}"

  if [[ ${#missing_req[@]} -eq 0 && ${#missing_opt[@]} -eq 0 ]]; then
    ok "All dependencies are already installed. Nothing to do."
    return 0
  fi

  [[ ${#missing_req[@]} -gt 0 ]] && warn "Missing (required): ${missing_req[*]}"
  [[ ${#missing_opt[@]} -gt 0 ]] && log  "Missing (optional): ${missing_opt[*]}"

  # We install on-board via apt. If apt isn't here, give manual guidance.
  if ! have apt-get; then
    err "No apt-get found — this doesn't look like Kali/Debian/Ubuntu."
    err "Install these packages with your distro's package manager:"
    err "  required: ${missing_req[*]}"
    [[ ${#missing_opt[@]} -gt 0 ]] && err "  optional: ${missing_opt[*]}"
    # Missing required deps are fatal; missing optional ones are fine.
    [[ ${#missing_req[@]} -gt 0 ]] && return 1
    return 0
  fi

  local SUDO; SUDO="$(need_sudo)"
  if [[ "${EUID:-$(id -u)}" -ne 0 && -z "$SUDO" ]]; then
    err "Need root to install packages, and 'sudo' is not available."
    err "Re-run as root:  su -c '$0'"
    return 1
  fi

  local to_install=("${missing_req[@]}" "${missing_opt[@]}")
  log "Installing: ${to_install[*]}"
  log "(using apt — this is the on-board package manager on Kali)"

  # apt update can be flaky on fresh installs; don't abort the whole run on it.
  $SUDO apt-get update -y || warn "apt-get update reported problems — continuing."

  if $SUDO apt-get install -y "${to_install[@]}"; then
    ok "Dependencies installed."
  else
    # Retry required-only in case an optional package name was unavailable.
    if [[ ${#missing_req[@]} -gt 0 ]]; then
      warn "Bulk install failed — retrying required packages only."
      $SUDO apt-get install -y "${missing_req[@]}"
    fi
  fi

  # Final verification of the required set.
  local still=()
  collect_missing still "${REQUIRED[@]}"
  if [[ ${#still[@]} -gt 0 ]]; then
    err "Still missing after install: ${still[*]}"
    return 1
  fi
  ok "Setup complete — you're ready to run ./crack.sh"
}

main "$@"
