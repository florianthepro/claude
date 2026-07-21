# crack-wifi — educational Wi-Fi security auditing

A friendly launcher that takes you from a **fresh Kali Linux install** all the
way to **"you are on the network"** — with a single command and a simple local
web interface at **http://crack-wifi.local**.

You run one command. It installs anything that's missing, starts a local page,
and opens your browser. You click a network, watch — live — every step the tool
takes, and when it finishes you see the recovered key.

It is a thin, teaching-focused wrapper around the standard **aircrack-ng** suite
that already ships with Kali.

---

## ⚠️ Legal & ethics — read this first

**Only audit Wi-Fi networks you own or have explicit written permission to
test.** Cracking or connecting to networks you do not control is illegal in most
countries. This project exists for **learning** and **authorized penetration
testing** only. You alone are responsible for how you use it.

The launcher makes you type `I AGREE` before it starts (skip with `--yes` only in
labs you own).

---

## 🚀 The whole thing in one command

On Kali:

```bash
git clone https://github.com/florianthepro/claude.git
cd claude/wifi
sudo ./crack.sh
```

That's it. `crack.sh` will:

1. show the legal notice and ask you to type `I AGREE`,
2. **auto-install anything missing** (`aircrack-ng`, `reaver`, `crunch`, … via
   `apt` — the on-board package manager) by calling `setup.sh` for you,
3. map `crack-wifi.local → 127.0.0.1` (temporarily, in `/etc/hosts`),
4. start the local web server and **open your browser** at
   **http://crack-wifi.local:8777**.

Then, in the page:

> **click a network → wait → read the password.**

Press **Ctrl+C** in the terminal when you're done — it cleans everything up
(removes the hosts entry, stops the server, returns the card to normal).

### Try it anywhere first (demo mode — no Wi-Fi card, no root)

The tool ships with a **demo mode** that simulates realistic networks and the
full attack workflow, so you can learn the process and explore the UI on any
machine:

```bash
cd claude/wifi
./crack.sh --demo --no-dns
# then open the URL it prints, e.g. http://127.0.0.1:8777
```

`--no-dns` avoids touching `/etc/hosts` (no root needed).

---

## Step-by-step: from first-ever Kali boot to on the network

### 0. What you need
- **Kali Linux** (bare metal, VM, or USB live). Any recent release.
- A **Wi-Fi adapter that supports monitor mode + packet injection**. Kali's
  built-in adapter often does *not* in a VM — a well-supported USB dongle
  (Atheros AR9271, Realtek RTL8812AU, MT7612U, …) is the usual choice.
- Physical proximity to the network **you are authorized to test**.

### 1. First boot
Just boot Kali and open a terminal. You don't need to hand-install anything —
`crack.sh` installs its dependencies on first run. (If you like, you can update
the system first: `sudo apt update && sudo apt full-upgrade -y`.)

### 2. Get the tool
```bash
git clone https://github.com/florianthepro/claude.git
cd claude/wifi
```

### 3. Plug in your Wi-Fi adapter and launch
```bash
sudo ./crack.sh
```
On the first run it installs `aircrack-ng` and friends automatically, then opens
the interface in your browser.

### 4. In the web interface
1. Click **Scan networks**. The card is put into monitor mode and
   `airodump-ng` collects nearby APs.
2. Networks appear **sorted easiest-first**, each with a **difficulty badge**.
3. Click a network to see its detail:
   - a **crackability gauge** (0–100) with the reasons behind the score,
   - encryption, channel, signal, WPS status.
4. (Optional) open **Advanced options** to choose the method, a custom
   wordlist, or a bruteforce charset/length.
5. Click **Start audit** and watch the live console:
   `monitor → capture → deauth → handshake → crack → connect`.
6. On success the **Recovered** panel shows the network name, **password**, the
   method used, any captive-portal **login**, and a **connection status**.

### 5. You're on the network
With **Auto-connect** enabled (default), the tool leaves monitor mode, restarts
NetworkManager and joins the network for you via `nmcli` — the status shows
**✓ Connected**. If you turned auto-connect off, the panel shows the exact
command to run yourself:
```bash
nmcli dev wifi connect "SSID" password "recovered-key"
```

### 6. Clean up
Press **Ctrl+C** in the terminal. The launcher automatically removes the
`crack-wifi.local` entry, stops the web server, and returns any monitor
interfaces to managed mode.

---

## The attack methods explained

| Encryption | What the tool does | Notes |
|-----------|--------------------|-------|
| **Open** | Associates directly via `nmcli`. | Nothing to crack; captive-portal creds shown if any. |
| **WEP** | Captures IVs (with ARP-replay injection), runs `aircrack-ng`. | Broken by design — minutes. |
| **WPS on** | `reaver` Pixie-Dust / PIN. WPS APs are detected during the scan with `wash`. | Often bypasses the WPA passphrase entirely. |
| **WPA/WPA2-PSK** | Captures the 4-way handshake (forced via deauth), then dictionary or bruteforce with `aircrack-ng`. | Strength depends entirely on the passphrase. |
| **WPA3-SAE** | Attempts capture, then reports no practical offline attack. | SAE resists offline cracking. |

After a key is recovered (any method) the tool can **join the network for you**
automatically — see *Auto-connect* below.

### Advanced options
- **Method** — force dictionary, bruteforce, or WPS instead of *Auto*.
- **Wordlist** — path to any wordlist (default: `rockyou.txt`).
- **Bruteforce** — pick a charset (digits / lowercase / alphanumeric) and length;
  the tool pipes `crunch` into `aircrack-ng`. *Warning: the keyspace grows
  exponentially — long passphrases are impractical to bruteforce.*
- **Auto-connect** — when the key is found, leave monitor mode, restart
  NetworkManager and connect with `nmcli` so you're actually on the network
  (on by default; turn it off to just get the key).

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
  --skip-setup      Don't auto-install missing dependencies.
  -y, --yes         Skip the interactive authorization prompt (labs you own only).
  -h, --help        Help.
```

`setup.sh` can also be run on its own to (re-)install dependencies:
```bash
sudo ./setup.sh
```
It's idempotent — it only installs what's actually missing.

---

## How it's built

```
wifi/
├── crack.sh              # single command: setup → server → open browser → cleanup
├── setup.sh              # installs missing deps via apt (on-board, idempotent)
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
  installed. Run `sudo ./crack.sh` (it installs deps and needs root for real mode).
- **Scan finds nothing (real mode)** — your adapter may not support monitor mode.
  Check with `sudo airmon-ng start wlan0` then `iw dev`. Use a supported USB dongle.
- **`crack-wifi.local` doesn't resolve** — you used `--no-dns`, or `/etc/hosts`
  isn't writable. Use the printed `http://127.0.0.1:PORT` URL instead.
- **Browser didn't open** — just click the `http://crack-wifi.local:8777` link the
  launcher prints in the terminal.
- **Handshake never captured** — no clients on the AP, or signal too weak. Get
  closer, or wait for a device to connect.
```
