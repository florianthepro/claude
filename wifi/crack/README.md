# crack-wifi — educational Wi-Fi security auditing

A friendly launcher that takes you from a **fresh Kali Linux install** all the
way to **"you are on the network"**, driven from a simple local web interface at
**http://crack-wifi.local**.

You scan for nearby networks, see an at-a-glance estimate of how hard each one is
to crack, click one, and watch — live — every step the tool takes (monitor mode →
handshake capture → deauth → crack). When it finishes you see the recovered key
(and login, for captive portals). Advanced mode lets you pick the attack method,
wordlist, or a bruteforce keyspace.

It is a thin, teaching-focused wrapper around the standard **aircrack-ng** suite
that already ships with Kali.

---

## ⚠️ Legal & ethics — read this first

**Only audit Wi-Fi networks you own or have explicit written permission to
test.** Cracking or connecting to networks you do not control is illegal in most
countries and can carry serious penalties. This project exists for **learning**
and **authorized penetration testing** only. You alone are responsible for how
you use it.

The launcher makes you type `I AGREE` before it starts (skip with `--yes` only in
labs you own).

---

## Quick start (works anywhere — no Wi-Fi card needed)

The tool ships with a **demo mode** that simulates realistic networks and the full
attack workflow, so you can learn the process and explore the UI on any machine:

```bash
cd wifi/crack
./crack.sh --demo --no-dns
# then open the URL it prints, e.g. http://127.0.0.1:8777
```

`--no-dns` avoids touching `/etc/hosts` (no root needed). For the real
`crack-wifi.local` hostname, run with `sudo` and drop `--no-dns`.

---

## Step-by-step: from first-ever Kali boot to on the network

### 0. What you need
- **Kali Linux** (bare metal, VM, or USB live). Any recent release.
- A **Wi-Fi adapter that supports monitor mode + packet injection**. Kali's
  built-in adapter often does *not* in a VM — a well-supported USB dongle
  (Atheros AR9271, Realtek RTL8812AU, MT7612U, …) is the usual choice.
- Physical proximity to the network **you are authorized to test**.

### 1. First boot & update
```bash
sudo apt update && sudo apt full-upgrade -y
```

### 2. Install the toolkit
Kali ships most of this, but to be sure:
```bash
sudo apt install -y aircrack-ng reaver crunch python3
# optional GPU cracking:
sudo apt install -y hashcat
```

### 3. Get the tool
```bash
git clone <this-repo>
cd <repo>/wifi/crack
chmod +x crack.sh
```

### 4. Plug in your Wi-Fi adapter and confirm it's seen
```bash
iw dev            # should list your interface, e.g. wlan0
```

### 5. Launch
```bash
sudo ./crack.sh          # auto-detects tools + root -> REAL mode
```
The launcher will:
1. Show the legal notice and ask you to type `I AGREE`.
2. Detect your environment (real vs demo).
3. Temporarily add `crack-wifi.local → 127.0.0.1` to `/etc/hosts`.
4. Start the local web server and open your browser.

### 6. In the web interface
1. Click **Scan networks**. The card is put into monitor mode and
   `airodump-ng` collects nearby APs.
2. Networks appear **sorted easiest-first**, each with a **difficulty badge**.
3. Click a network to see its detail:
   - a **crackability gauge** (0–100) with the reasons behind the score,
   - encryption, channel, signal, WPS status.
4. (Optional) open **Advanced options** to choose the method, a custom
   wordlist, or a bruteforce charset/length.
5. Click **Start audit** and watch the live console:
   `monitor → capture → deauth → handshake → crack`.
6. On success the **Recovered** panel shows the network name, **password**, the
   method used, and any captive-portal **login**.

### 7. Connect
Use the recovered key with NetworkManager / `nmcli`, or your desktop's Wi-Fi
menu:
```bash
nmcli dev wifi connect "SSID" password "recovered-key"
```

### 8. Clean up
Press **Ctrl+C** in the terminal. The launcher automatically:
- removes the `crack-wifi.local` entry from `/etc/hosts`,
- stops the web server,
- returns any monitor interfaces to managed mode.

---

## The attack methods explained

| Encryption | What the tool does | Notes |
|-----------|--------------------|-------|
| **Open** | Simply associates. | Nothing to crack; captive-portal creds shown if any. |
| **WEP** | Captures IVs, runs `aircrack-ng` PTW. | Broken by design — minutes. |
| **WPS on** | `reaver` Pixie-Dust / PIN. | Often bypasses the WPA passphrase entirely. |
| **WPA/WPA2-PSK** | Captures the 4-way handshake (forced via deauth), then dictionary or bruteforce with `aircrack-ng`. | Strength depends entirely on the passphrase. |
| **WPA3-SAE** | Attempts capture, then reports no practical offline attack. | SAE resists offline cracking. |

### Advanced options
- **Method** — force dictionary, bruteforce, or WPS instead of *Auto*.
- **Wordlist** — path to any wordlist (default: `rockyou.txt`).
- **Bruteforce** — pick a charset (digits / lowercase / alphanumeric) and length;
  the tool pipes `crunch` into `aircrack-ng`. *Warning: the keyspace grows
  exponentially — long passphrases are impractical to bruteforce.*

### How the difficulty score works
`lib/difficulty.py` combines:
- **encryption type** (Open ≪ WEP ≪ WPA2 ≪ WPA3),
- **WPS enabled** (big weakness),
- **signal strength** (affects handshake capture),
- **connected clients** (easy deauth → easy handshake),

into a 0–100 score with a label (*Trivial → Very hard*), an ETA bucket, and a
recommended method. It's a rough teaching estimate, not a guarantee.

---

## Command reference

```
sudo ./crack.sh [options]

  --demo            Force demo mode (safe anywhere, no hardware/root).
  --real            Force real mode (needs root + aircrack-ng + monitor-capable card).
  --port <n>        Web UI port (default 8777).
  --host <ip>       Bind address (default 127.0.0.1).
  --wordlist <f>    Default dictionary (default /usr/share/wordlists/rockyou.txt).
  --no-dns          Don't touch /etc/hosts; use http://127.0.0.1:PORT.
  --no-browser      Don't auto-open a browser.
  -y, --yes         Skip the interactive authorization prompt (labs you own only).
  -h, --help        Help.
```

---

## How it's built

```
wifi/crack/
├── crack.sh              # launcher: env detect, DNS, server, cleanup trap
├── server/server.py      # stdlib HTTP server + JSON API (no dependencies)
├── lib/
│   ├── engine.py         # DemoEngine + RealEngine (aircrack-ng suite)
│   └── difficulty.py     # crackability estimator
└── web/                  # simple, clean interface
    ├── index.html
    ├── style.css
    └── app.js
```

- **No third-party Python packages** — only the standard library, so it runs on a
  stock Kali install.
- **Demo and real modes share the same UI and API**, so what you learn in demo
  mode maps exactly onto a real audit.

---

## Troubleshooting

- **"falling back to DEMO mode"** — either you're not root or `aircrack-ng` isn't
  installed. Run `sudo apt install -y aircrack-ng` and re-run with `sudo`.
- **Scan finds nothing (real mode)** — your adapter may not support monitor mode.
  Check with `sudo airmon-ng start wlan0` then `iw dev`. Use a supported USB dongle.
- **`crack-wifi.local` doesn't resolve** — you used `--no-dns`, or `/etc/hosts`
  isn't writable. Use the printed `http://127.0.0.1:PORT` URL instead.
- **Handshake never captured** — no clients on the AP, or signal too weak. Get
  closer, or wait for a device to connect.
```
