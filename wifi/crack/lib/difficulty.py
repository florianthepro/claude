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
